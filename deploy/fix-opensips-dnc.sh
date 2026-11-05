#!/usr/bin/env bash
# Enable DNC check in a live OpenSIPS cfg (OpenSIPS 3.6+ sqlops / cachedb_redis).
# SIP-user DNC blocks before trunk; trunk DNC uses original dialled digits (dlg_val os_dst).
# On hit: instant 403 — never failover to another trunk / never INVITE the vendor.
# Backs up cfg and restores if opensips -c fails.
set -Eeuo pipefail
CFG="${1:-/etc/opensips/opensips.cfg}"
[[ -f "$CFG" ]] || { echo "Missing $CFG"; exit 1; }

mod_dir="$(dirname "$(find /usr/lib -type f -name tm.so -path '*opensips/modules*' 2>/dev/null | head -n1)")"
[[ -d "$mod_dir" ]] || { echo "OpenSIPS modules dir not found"; exit 1; }

apt-get install -y opensips-module-cachedb-redis opensips-cachedb-redis-module \
  opensips-module-sqlops opensips-sqlops-module >/dev/null 2>&1 || true

REDIS_PASS=""
[[ -s /etc/ownswitch/secrets/redis_password ]] && REDIS_PASS="$(tr -d '\n' </etc/ownswitch/secrets/redis_password)"
REDIS_DB="${REDIS_PORTAL_DB:-1}"
PW=""
[[ -s /etc/ownswitch/secrets/mysql_opensips_password ]] && PW="$(tr -d '\n' </etc/ownswitch/secrets/mysql_opensips_password)"
DB_URL="mysql://opensips:${PW}@127.0.0.1/opensips"

mysql -uroot opensips -e \
  "CREATE TABLE IF NOT EXISTS dnc_cache (
    group_id INT UNSIGNED NOT NULL,
    number_digits VARCHAR(32) NOT NULL,
    PRIMARY KEY (group_id, number_digits)
  ) ENGINE=InnoDB DEFAULT CHARSET=latin1" 2>/dev/null || true

# MUST succeed before touching load_credentials — missing column breaks Eyebeam REGISTER
ensure_sub_col() {
  local col="$1" def="$2"
  local n
  n="$(mysql -Nuroot opensips -e "SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='opensips' AND TABLE_NAME='subscriber' AND COLUMN_NAME='${col}'" 2>/dev/null || echo 0)"
  if [[ "${n:-0}" -eq 0 ]]; then
    echo "ADD subscriber.${col}"
    mysql -uroot opensips -e "ALTER TABLE subscriber ADD COLUMN ${def}"
  else
    echo "OK subscriber.${col}"
  fi
}
ensure_sub_col dnc_group_id "dnc_group_id INT UNSIGNED NOT NULL DEFAULT 0"
ensure_sub_col dst_format "dst_format VARCHAR(16) NOT NULL DEFAULT 'as_is'"

BAK="${CFG}.bak-dnc-$(date +%Y%m%d%H%M%S)"
cp -a "$CFG" "$BAK"
echo "Backup: $BAK"

python3 - "$CFG" "$mod_dir" "$REDIS_PASS" "$REDIS_DB" "$DB_URL" <<'PY'
import os, re, sys, urllib.parse
cfg, mod_dir, redis_pass, redis_db, db_url = sys.argv[1:6]
text = open(cfg, encoding="utf-8", errors="replace").read()

# load_credentials: os_dncid — only if column exists (verified above)
lc = re.search(r'modparam\("auth_db",\s*"load_credentials",\s*"([^"]*)"\)', text)
if lc and "os_dncid" not in lc.group(1):
    creds = lc.group(1)
    if not creds.endswith(";"):
        creds += ";"
    creds += "$avp(os_dncid)=dnc_group_id"
    text = text[: lc.start(1)] + creds + text[lc.end(1) :]
    print("load_credentials: added os_dncid")

# Ensure route(check_sip_dnc) before do_routing (custom cfgs may use different do_routing shapes)
if "route(check_sip_dnc)" not in text:
    inserted = False
    for needle, repl in (
        ("if (!do_routing(1", "route(check_sip_dnc);\n\n\tif (!do_routing(1"),
        ("if (!do_routing(", "route(check_sip_dnc);\n\n\tif (!do_routing("),
        ('xlog("L_WARN", "OWNSWITCH INVITE $fU', 'route(check_sip_dnc);\n\txlog("L_WARN", "OWNSWITCH INVITE $fU'),
        ('xlog("L_INFO", "OWNSWITCH: cgrates bypassed', 'route(check_sip_dnc);\n\txlog("L_INFO", "OWNSWITCH: cgrates bypassed'),
    ):
        if needle in text:
            text = text.replace(needle, repl, 1)
            inserted = True
            print("inserted route(check_sip_dnc) before:", needle[:40])
            break
    if not inserted:
        print("WARN: could not find do_routing anchor for check_sip_dnc")

# Ensure route(check_dnc) is CALLED on the outbound path (definition alone is useless).
# Custom Magnus/Ownswitch hybrids often lack apply_ruri_strip — inject before relay markers.
# NOTE: CLI/CDR fix may rewrite ACC xlog lines; keep many anchors so DNC survives CLI patches.
if "route(check_dnc);" not in text:
    inserted = False
    for needle, repl in (
        ("route(apply_ruri_strip);", "route(check_dnc);\n\troute(apply_ruri_strip);"),
        ("route(check_rate_cap);", "route(check_dnc);\n\troute(check_rate_cap);"),
        ("route(apply_sip_cid);", "route(check_dnc);\n\troute(apply_sip_cid);"),
        ("route(apply_outbound_cid);", "route(check_dnc);\n\troute(apply_outbound_cid);"),
        ("route(acc_cli_gw);", "route(check_dnc);\n\troute(acc_cli_gw);"),
        (
            'xlog("L_INFO", "OWNSWITCH acc_extra before relay',
            'route(check_dnc);\n\txlog("L_INFO", "OWNSWITCH acc_extra before relay',
        ),
        (
            'xlog("L_INFO", "ACC cli=',
            'route(check_dnc);\n\txlog("L_INFO", "ACC cli=',
        ),
        ("route(relay);", "route(check_dnc);\n\troute(relay);"),
        ("\tt_relay();", "\troute(check_dnc);\n\tt_relay();"),
    ):
        if needle in text:
            text = text.replace(needle, repl, 1)
            inserted = True
            print("inserted route(check_dnc); before:", needle[:48])
            break
    if not inserted:
        print("WARN: could not find outbound relay anchor for check_dnc — manual cfg edit needed")

# Always pin check_dnc on the live outbound path AFTER CID rewrite when possible.
# Prefer acc_cli_gw / ACC markers — do NOT prefer apply_sip_cid (runs before os_cli is set).
for anchor in (
    "route(acc_cli_gw);",
    'xlog("L_INFO", "OWNSWITCH acc_extra before relay',
    'xlog("L_INFO", "ACC cli=',
    "route(apply_trunk_attrs);",
    "route(apply_sip_dst_format);",
    "route(relay);",
):
    if anchor not in text:
        continue
    pinned = "route(check_dnc);\n\t" + anchor
    if pinned in text:
        break
    text = text.replace(anchor, pinned, 1)
    print("pinned route(check_dnc); before:", anchor[:48])
    break

# Preserve CLI ACC semantics (DNC must not leave cli=$fU) — multiline OpenSIPS-safe
CLI_BLOCK = (
    "if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) {\n"
    "\t\t$acc_extra(cli) = $dlg_val(os_cli);\n"
    "\t} else {\n"
    "\t\t$acc_extra(cli) = $fU;\n"
    "\t}"
)
if "$acc_extra(cli) = $fU;" in text:
    text = text.replace("$acc_extra(cli) = $fU;", CLI_BLOCK)
    print("preserved CLI: rewrote acc_extra(cli)=$fU → os_cli")
if "$acc_extra(src) = $fU;" in text and "OWNSWITCH acc_extra before relay" in text:
    text = text.replace("$acc_extra(src) = $fU;", "$acc_extra(src) = $dlg_val(os_src);")
    print("preserved CLI: before-relay src=$fU → os_src")

redis_so = os.path.isfile(os.path.join(mod_dir, "cachedb_redis.so"))
sql_so = os.path.isfile(os.path.join(mod_dir, "sqlops.so"))

mods = ""
if redis_so:
    # URL-encode password — raw @ : / # in secrets break redis:// and can stop OpenSIPS start
    if redis_pass:
        enc = urllib.parse.quote(redis_pass, safe="")
        url = f"redis://:{enc}@127.0.0.1:6379/{redis_db}"
    else:
        url = f"redis://127.0.0.1:6379/{redis_db}"
    mods = f'''loadmodule "cachedb_redis.so"
modparam("cachedb_redis", "cachedb_url", "redis:dnc=>{url}")
'''
    route = r'''route[check_sip_dnc] {
	if ($avp(os_dncid) == NULL || $(avp(os_dncid){s.int}) <= 0) {
		return;
	}
	$var(dncid) = $avp(os_dncid);
	$var(dnc_dst) = $(rU{re.subst,/[^0-9]//g});
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	$var(dnc_key) = "ownswitch:dnc:" + $var(dncid);
	$var(dnc_hit) = NULL;
	if (cache_raw_query("redis:dnc", "SISMEMBER $var(dnc_key) $var(dnc_dst)", "$var(dnc_hit)") && $var(dnc_hit) != NULL && $(var(dnc_hit){s.int}) == 1) {
		xlog("L_WARN", "SIP DNC hit group=$var(dncid) dst=$var(dnc_dst) — block before trunk\n");
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
	$var(dnc_alt) = NULL;
	if ($(var(dnc_dst){s.len}) == 11 && $(var(dnc_dst){s.substr,0,1}) == "1") {
		$var(dnc_alt) = $(var(dnc_dst){s.substr,1,0});
	} else if ($(var(dnc_dst){s.len}) == 10) {
		$var(dnc_alt) = "1" + $var(dnc_dst);
	}
	if ($var(dnc_alt) != NULL && $(var(dnc_alt){s.len}) >= 3) {
		$var(dnc_hit) = NULL;
		if (cache_raw_query("redis:dnc", "SISMEMBER $var(dnc_key) $var(dnc_alt)", "$var(dnc_hit)") && $var(dnc_hit) != NULL && $(var(dnc_hit){s.int}) == 1) {
			xlog("L_WARN", "SIP DNC hit (NANP alt) group=$var(dncid) dst=$var(dnc_dst) alt=$var(dnc_alt)\n");
			acc_db_request("403 Do Not Call", "acc");
			send_reply(403, "Do Not Call");
			exit;
		}
	}
}
route[check_dnc] {
	if ($avp(dr_gw_attrs) == NULL) {
		xlog("L_INFO", "DNC skip: no dr_gw_attrs (routing attrs empty)\n");
		return;
	}
	$var(dncid) = $(avp(dr_gw_attrs){param.value,dncid});
	if ($var(dncid) == NULL || $(var(dncid){s.len}) == 0 || $(var(dncid){s.int}) <= 0) {
		xlog("L_INFO", "DNC skip: no dncid= in gw attrs [$avp(dr_gw_attrs)] — re-save trunk\n");
		return;
	}
	$var(dnc_dst) = $(dlg_val(os_dst){re.subst,/[^0-9]//g});
	if ($var(dnc_dst) == NULL || $(var(dnc_dst){s.len}) < 3) {
		$var(dnc_dst) = $(rU{re.subst,/[^0-9]//g});
	}
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	$var(dnc_key) = "ownswitch:dnc:" + $var(dncid);
	xlog("L_INFO", "DNC check group=$var(dncid) dst=$var(dnc_dst) key=$var(dnc_key)\n");
	$var(dnc_hit) = NULL;
	if (cache_raw_query("redis:dnc", "SISMEMBER $var(dnc_key) $var(dnc_dst)", "$var(dnc_hit)") && $var(dnc_hit) != NULL && $(var(dnc_hit){s.int}) == 1) {
		xlog("L_WARN", "Trunk DNC hit group=$var(dncid) dst=$var(dnc_dst) — instant block (no vendor)\n");
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
	$var(dnc_alt) = NULL;
	if ($(var(dnc_dst){s.len}) == 11 && $(var(dnc_dst){s.substr,0,1}) == "1") {
		$var(dnc_alt) = $(var(dnc_dst){s.substr,1,0});
	} else if ($(var(dnc_dst){s.len}) == 10) {
		$var(dnc_alt) = "1" + $var(dnc_dst);
	}
	if ($var(dnc_alt) != NULL && $(var(dnc_alt){s.len}) >= 3) {
		$var(dnc_hit) = NULL;
		if (cache_raw_query("redis:dnc", "SISMEMBER $var(dnc_key) $var(dnc_alt)", "$var(dnc_hit)") && $var(dnc_hit) != NULL && $(var(dnc_hit){s.int}) == 1) {
			xlog("L_WARN", "Trunk DNC hit (NANP alt) group=$var(dncid) dst=$var(dnc_dst) alt=$var(dnc_alt)\n");
			acc_db_request("403 Do Not Call", "acc");
			send_reply(403, "Do Not Call");
			exit;
		}
	}
	xlog("L_INFO", "DNC miss group=$var(dncid) dst=$var(dnc_dst) (not in cache)\n");
}
'''
    mode = "redis"
elif sql_so:
    mods = f'''loadmodule "sqlops.so"
modparam("sqlops", "db_url", "{db_url}")
'''
    route = r'''route[check_sip_dnc] {
	if ($avp(os_dncid) == NULL || $(avp(os_dncid){s.int}) <= 0) {
		return;
	}
	$var(dncid) = $avp(os_dncid);
	$var(dnc_dst) = $(rU{re.subst,/[^0-9]//g});
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	$var(dnc_hit) = NULL;
	if (sql_query_one("select 1 from dnc_cache where group_id=$var(dncid) and number_digits='$var(dnc_dst)' limit 1", "$var(dnc_hit)") && $var(dnc_hit) != NULL) {
		xlog("L_WARN", "SIP DNC hit (sql) group=$var(dncid) dst=$var(dnc_dst) — block before trunk\n");
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
	$var(dnc_alt) = NULL;
	if ($(var(dnc_dst){s.len}) == 11 && $(var(dnc_dst){s.substr,0,1}) == "1") {
		$var(dnc_alt) = $(var(dnc_dst){s.substr,1,0});
	} else if ($(var(dnc_dst){s.len}) == 10) {
		$var(dnc_alt) = "1" + $var(dnc_dst);
	}
	if ($var(dnc_alt) != NULL && $(var(dnc_alt){s.len}) >= 3) {
		$var(dnc_hit) = NULL;
		if (sql_query_one("select 1 from dnc_cache where group_id=$var(dncid) and number_digits='$var(dnc_alt)' limit 1", "$var(dnc_hit)") && $var(dnc_hit) != NULL) {
			xlog("L_WARN", "SIP DNC hit (sql NANP alt) group=$var(dncid) dst=$var(dnc_dst) alt=$var(dnc_alt)\n");
			acc_db_request("403 Do Not Call", "acc");
			send_reply(403, "Do Not Call");
			exit;
		}
	}
}
route[check_dnc] {
	if ($avp(dr_gw_attrs) == NULL) {
		xlog("L_INFO", "DNC skip: no dr_gw_attrs (routing attrs empty)\n");
		return;
	}
	$var(dncid) = $(avp(dr_gw_attrs){param.value,dncid});
	if ($var(dncid) == NULL || $(var(dncid){s.len}) == 0 || $(var(dncid){s.int}) <= 0) {
		xlog("L_INFO", "DNC skip: no dncid= in gw attrs [$avp(dr_gw_attrs)] — re-save trunk\n");
		return;
	}
	$var(dnc_dst) = $(dlg_val(os_dst){re.subst,/[^0-9]//g});
	if ($var(dnc_dst) == NULL || $(var(dnc_dst){s.len}) < 3) {
		$var(dnc_dst) = $(rU{re.subst,/[^0-9]//g});
	}
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	xlog("L_INFO", "DNC check (sql) group=$var(dncid) dst=$var(dnc_dst)\n");
	$var(dnc_hit) = NULL;
	if (sql_query_one("select 1 from dnc_cache where group_id=$var(dncid) and number_digits='$var(dnc_dst)' limit 1", "$var(dnc_hit)") && $var(dnc_hit) != NULL) {
		xlog("L_WARN", "Trunk DNC hit (sql) group=$var(dncid) dst=$var(dnc_dst) — instant block (no vendor)\n");
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
	$var(dnc_alt) = NULL;
	if ($(var(dnc_dst){s.len}) == 11 && $(var(dnc_dst){s.substr,0,1}) == "1") {
		$var(dnc_alt) = $(var(dnc_dst){s.substr,1,0});
	} else if ($(var(dnc_dst){s.len}) == 10) {
		$var(dnc_alt) = "1" + $var(dnc_dst);
	}
	if ($var(dnc_alt) != NULL && $(var(dnc_alt){s.len}) >= 3) {
		$var(dnc_hit) = NULL;
		if (sql_query_one("select 1 from dnc_cache where group_id=$var(dncid) and number_digits='$var(dnc_alt)' limit 1", "$var(dnc_hit)") && $var(dnc_hit) != NULL) {
			xlog("L_WARN", "Trunk DNC hit (sql NANP alt) group=$var(dncid) dst=$var(dnc_dst) alt=$var(dnc_alt)\n");
			acc_db_request("403 Do Not Call", "acc");
			send_reply(403, "Do Not Call");
			exit;
		}
	}
	xlog("L_INFO", "DNC miss (sql) group=$var(dncid) dst=$var(dnc_dst) (not in cache)\n");
}
'''
    mode = "sqlops"
else:
    route = """route[check_sip_dnc] {
	return;
}
route[check_dnc] {
	return;
}
"""
    mode = "stub"

text = re.sub(r'(?m)^loadmodule "cachedb_redis\.so"\s*\n(?:modparam\("cachedb_redis"[^\n]*\n)*', "", text)
text = re.sub(r'(?m)^loadmodule "sqlops\.so"\s*\n(?:modparam\("sqlops"[^\n]*\n)*', "", text)
text = re.sub(r'(?ms)^route\[check_sip_dnc\]\s*\{.*?\n\}\s*', "", text)
text = re.sub(r'(?ms)^route\[check_dnc\]\s*\{.*?\n\}\s*', "", text)

if mods and 'loadmodule "drouting.so"' in text:
    text = text.replace('loadmodule "drouting.so"', 'loadmodule "drouting.so"\n' + mods, 1)
elif mods:
    text = mods + "\n" + text

if "route[relay]" in text:
    text = text.replace("route[relay]", route + "\nroute[relay]", 1)
else:
    text += "\n" + route

open(cfg, "w", encoding="utf-8").write(text)
print("Patched", cfg, mode)
PY

if ! opensips -c -f "$CFG"; then
  echo "ERROR: opensips -c failed — restoring $BAK" >&2
  cp -a "$BAK" "$CFG"
  opensips -c -f "$CFG" || true
  exit 1
fi

systemctl restart opensips
sleep 1
if ! systemctl is-active --quiet opensips; then
  echo "FATAL: opensips not active — restoring $BAK (REGISTER would fail)" >&2
  journalctl -u opensips -n 40 --no-pager >&2 || true
  cp -a "$BAK" "$CFG"
  systemctl restart opensips || true
  exit 1
fi
fail2ban-client unban --all >/dev/null 2>&1 || true
echo "DNC OpenSIPS check OK (instant 403, no vendor hit, NANP aliases). opensips=$(systemctl is-active opensips)"
echo "Verify call sites:"
grep -nE 'route\(check_dnc\)|route\(check_sip_dnc\)|route\[check_dnc\]' "$CFG" | head -n 20 || true
echo "If REGISTER broken: sudo bash /opt/ownswitch-src/deploy/fix-opensips-register.sh"
echo "Then: sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env php /opt/ownswitch/current/bin/sync-dnc-cache.php"
echo "      sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env php /opt/ownswitch/current/bin/sync-opensips-trunks.php"
echo "Re-save trunk (DNC group) + SIP user if needed. Test: journalctl -u opensips -f | grep DNC"
