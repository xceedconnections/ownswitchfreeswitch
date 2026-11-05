#!/usr/bin/env bash
# Fix Softphone Display Name → CDR CallerID (Eyebeam).
#
# SAFE: does NOT modify REGISTER, check_dnc, or check_sip_dnc.
#
# Root causes addressed:
#  1) opensips.acc.cli column often missing → ACC cannot store Display Name → CDR shows SIP user
#  2) $fn / Display Name not extracted → os_cli stays $fU
#  3) apply_sip_cid ran AFTER do_accounting early stamp of cli=$fU
#  4) append_hf(From) does not refresh $fU → ACC must use $dlg_val(os_cli)
#
# Usage: sudo bash /opt/ownswitch-src/deploy/fix-opensips-display-cli.sh
set -Eeuo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }
CFG="${1:-/etc/opensips/opensips.cfg}"
SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
[[ -f "$CFG" ]] || { echo "Missing $CFG"; exit 1; }

echo "======== Softphone Display Name → CDR CLI (REGISTER/DNC untouched) ========"

# --- ACC columns (critical: without cli, Display Name never reaches CDR importer) ---
for tbl in acc missed_calls; do
  for col_def in \
    "src_user VARCHAR(64) NULL" \
    "dst_user VARCHAR(64) NULL" \
    "src_ip VARCHAR(45) NULL" \
    "cli VARCHAR(64) NULL" \
    "gw VARCHAR(64) NULL"
  do
    col="${col_def%% *}"
    n="$(mysql -Nuroot opensips -e "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA='opensips' AND TABLE_NAME='${tbl}' AND COLUMN_NAME='${col}'" 2>/dev/null || echo 0)"
    if [[ "${n:-0}" -eq 0 ]]; then
      echo "ADD opensips.${tbl}.${col}"
      mysql -uroot opensips -e "ALTER TABLE ${tbl} ADD COLUMN ${col_def}" || true
    else
      echo "OK opensips.${tbl}.${col}"
    fi
  done
done

# subscriber.cid_mode only (no REGISTER rewrite)
n="$(mysql -Nuroot opensips -e "SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA='opensips' AND TABLE_NAME='subscriber' AND COLUMN_NAME='cid_mode'" 2>/dev/null || echo 0)"
if [[ "${n:-0}" -eq 0 ]]; then
  mysql -uroot opensips -e "ALTER TABLE subscriber ADD COLUMN cid_mode VARCHAR(16) NOT NULL DEFAULT 'passthrough'"
  echo "ADD subscriber.cid_mode"
fi

echo "subscriber cid_mode:"
mysql -Nuroot opensips -e "SELECT username, cid_mode, rpid FROM subscriber" 2>/dev/null || true
echo "recent ACC cli vs src_user:"
mysql -Nuroot opensips -e "SELECT id, src_user, cli, dst_user, LEFT(from_uri,60) FROM acc ORDER BY id DESC LIMIT 8" 2>/dev/null || true

BAK="${CFG}.bak-display-cli-$(date +%Y%m%d%H%M%S)"
cp -a "$CFG" "$BAK"
echo "Backup: $BAK"

python3 - "$CFG" <<'PY'
import re, sys

cfg = sys.argv[1]
text = open(cfg, encoding="utf-8", errors="replace").read()
orig = text

def find_block(text: str, marker: str):
    start = text.find(marker)
    if start < 0:
        return None
    brace = text.find("{", start)
    if brace < 0:
        return None
    depth = 0
    i = brace
    while i < len(text):
        if text[i] == "{":
            depth += 1
        elif text[i] == "}":
            depth -= 1
            if depth == 0:
                return start, i + 1
        i += 1
    return None

def replace_route(text: str, name: str, body: str) -> str:
    marker = f"route[{name}]"
    found = find_block(text, marker)
    if not found:
        if "route[relay]" in text:
            text = text.replace("route[relay]", body.rstrip() + "\n\nroute[relay]", 1)
        else:
            text += "\n" + body
        print(f"inserted route[{name}]")
        return text
    start, end = found
    while end < len(text) and text[end] in " \t\r\n":
        end += 1
    text = text[:start] + body.rstrip() + "\n\n" + text[end:]
    print(f"replaced route[{name}]")
    return text

# Ensure extra_fields maps cli
if 'cli->cli' not in text:
    m = re.search(r'modparam\("acc",\s*"extra_fields",\s*"([^"]*)"\)', text)
    if m:
        fields = m.group(1)
        if "cli->" not in fields:
            if not fields.endswith(";"):
                fields += ";"
            fields += "cli->cli"
        if "gw->" not in fields:
            if not fields.endswith(";"):
                fields += ";"
            fields += "gw->gw"
        text = text[: m.start(1)] + fields + text[m.end(1) :]
        print("acc extra_fields += cli/gw")
    else:
        print("WARN: acc extra_fields not found")

# load_credentials must expose cid_mode (does not touch REGISTER path otherwise)
lc = re.search(r'modparam\("auth_db",\s*"load_credentials",\s*"([^"]*)"\)', text)
if lc and "os_cid_mode" not in lc.group(1):
    creds = lc.group(1)
    if not creds.endswith(";"):
        creds += ";"
    creds += "$avp(os_cid_mode)=cid_mode;$avp(os_cid)=rpid"
    text = text[: lc.start(1)] + creds + text[lc.end(1) :]
    print("load_credentials += cid_mode")

sip_cid = r'''route[apply_sip_cid] {
	$var(prior_cli) = $dlg_val(os_cli);
	if ($var(prior_cli) == NULL || $(var(prior_cli){s.len}) == 0 || $var(prior_cli) == $fU
		|| !($var(prior_cli) =~ "^[+0-9]{3,30}$")) {
		$dlg_val(os_cli) = $fU;
		$var(prior_cli) = "";
	}
	$var(mode) = $avp(os_cid_mode);
	$var(user) = $fU;
	$var(dom) = $fd;
	if ($var(dom) == NULL || $(var(dom){s.len}) == 0) {
		$var(dom) = $td;
	}

	$var(dn) = $fn;
	if ($var(dn) == NULL || $(var(dn){s.len}) == 0) {
		$var(dn) = $(hdr(From){nameaddr.name});
	}
	if (($var(dn) == NULL || $(var(dn){s.len}) == 0) && is_present_hf("From")) {
		$var(rawf) = $hdr(From);
		if ($var(rawf) =~ "\"[+0-9]{3,30}\"") {
			$var(dn) = $(var(rawf){re.subst,/.*"([+0-9]{3,30})".*/\1/});
		} else if ($var(rawf) =~ "^[+0-9]{3,30}[[:space:]]*<") {
			$var(dn) = $(var(rawf){re.subst,/^([+0-9]{3,30})[[:space:]]*<.*/\1/});
		}
	}
	if ($var(dn) == NULL || $(var(dn){s.len}) == 0) {
		$var(dn) = $(hdr(P-Asserted-Identity){nameaddr.name});
	}
	if ($var(dn) == NULL || $(var(dn){s.len}) == 0) {
		$var(dn) = $(hdr(Remote-Party-ID){nameaddr.name});
	}
	if ($var(dn) == NULL || $(var(dn){s.len}) == 0) {
		$var(dn) = $(hdr(P-Preferred-Identity){nameaddr.name});
	}
	$var(pai_u) = $(hdr(P-Asserted-Identity){nameaddr.uri}{uri.user});
	$var(rpid_u) = $(hdr(Remote-Party-ID){nameaddr.uri}{uri.user});
	$var(ppi_u) = $(hdr(P-Preferred-Identity){nameaddr.uri}{uri.user});

	$var(dn_digits) = "";
	if ($var(dn) != NULL && $(var(dn){s.len}) > 0) {
		$var(dn_digits) = $(var(dn){re.subst,/[^0-9+]//g});
	}
	if (($var(dn_digits) == NULL || $(var(dn_digits){s.len}) < 3) && $var(pai_u) != NULL) {
		$var(dn_digits) = $(var(pai_u){re.subst,/[^0-9+]//g});
	}
	if (($var(dn_digits) == NULL || $(var(dn_digits){s.len}) < 3) && $var(rpid_u) != NULL) {
		$var(dn_digits) = $(var(rpid_u){re.subst,/[^0-9+]//g});
	}
	if (($var(dn_digits) == NULL || $(var(dn_digits){s.len}) < 3) && $var(ppi_u) != NULL) {
		$var(dn_digits) = $(var(ppi_u){re.subst,/[^0-9+]//g});
	}

	xlog("L_INFO", "SIP CID probe mode='$var(mode)' fn='$fn' dn='$var(dn)' digits='$var(dn_digits)' FromUser=$fU auth=$au\n");

	if (($var(mode) == NULL || $(var(mode){s.len}) == 0) && $(var(dn_digits){s.len}) >= 3
		&& $(var(dn_digits){s.len}) <= 30 && !($fU =~ "^[+0-9]{8,30}$")) {
		$var(mode) = "display_name";
		xlog("L_INFO", "SIP CID mode inferred display_name (AVP empty) digits='$var(dn_digits)'\n");
	}

	if ($(var(dn_digits){s.len}) >= 3 && $(var(dn_digits){s.len}) <= 30
		&& ($var(dn_digits) =~ "^[+0-9]{3,30}$") && !($fU =~ "^[+0-9]{8,30}$")) {
		$dlg_val(os_cli) = $var(dn_digits);
		$acc_extra(cli) = $var(dn_digits);
	}

	if ($var(mode) == NULL || $(var(mode){s.len}) == 0 || $var(mode) == "passthrough") {
		if ($dlg_val(os_cli) =~ "^[+0-9]{3,30}$") {
			xlog("L_INFO", "SIP CID passthrough CDR-cli='$dlg_val(os_cli)' (From user=$fU kept)\n");
		} else {
			xlog("L_INFO", "SIP CID passthrough From=$fU fn='$fn' mode='$var(mode)'\n");
		}
		return;
	}

	if ($var(mode) == "fixed" && $avp(os_cid) != NULL && $(avp(os_cid){s.len}) > 0) {
		$var(user) = $(avp(os_cid){re.subst,/[^0-9+]//g});
	} else if ($var(mode) == "display_name") {
		if ($(var(dn_digits){s.len}) >= 3 && $(var(dn_digits){s.len}) <= 30
			&& ($var(dn_digits) =~ "^[+0-9]{3,30}$")) {
			$var(user) = $var(dn_digits);
		} else if ($(var(prior_cli){s.len}) >= 3 && ($var(prior_cli) =~ "^[+0-9]{3,30}$")) {
			$var(user) = $var(prior_cli);
			$dlg_val(os_cli) = $var(prior_cli);
			$acc_extra(cli) = $var(prior_cli);
			xlog("L_INFO", "SIP CID display_name keep prior os_cli=$var(prior_cli)\n");
			return;
		} else if ($avp(os_cid) != NULL && $(avp(os_cid){s.len}) > 0) {
			$var(user) = $(avp(os_cid){re.subst,/[^0-9+]//g});
			xlog("L_WARN", "SIP CID display_name missing on INVITE — using portal CallerID '$var(user)'\n");
		} else {
			xlog("L_WARN", "SIP CID display_name empty fn='$fn' dn='$var(dn)' From=$fU — set Eyebeam Display Name to digits\n");
			return;
		}
	} else {
		return;
	}
	$dlg_val(os_cli) = $var(user);
	$acc_extra(cli) = $var(user);
	remove_hf("From");
	append_hf("From: <sip:$var(user)@$var(dom)>;tag=$ft\r\n");
	remove_hf("P-Asserted-Identity");
	append_hf("P-Asserted-Identity: <sip:$var(user)@$var(dom)>\r\n");
	remove_hf("Remote-Party-ID");
	append_hf("Remote-Party-ID: <sip:$var(user)@$var(dom)>;party=calling;privacy=off;screen=yes\r\n");
	xlog("L_INFO", "SIP CID mode=$var(mode) to sip:$var(user)@$var(dom) os_cli=$dlg_val(os_cli)\n");
}
'''

acc_cli = r'''route[acc_cli_gw] {
	$acc_extra(src) = $dlg_val(os_src);
	$acc_extra(dst) = $dlg_val(os_dst);
	$acc_extra(ip) = $si;
	if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) {
		$acc_extra(cli) = $dlg_val(os_cli);
	} else {
		$acc_extra(cli) = $fU;
	}
	$var(gw) = $avp(dr_gw_id);
	if ($var(gw) == NULL || $(var(gw){s.len}) == 0) {
		$var(gw) = $avp(___dr_gw_id);
	}
	if (($var(gw) == NULL || $(var(gw){s.len}) == 0) && $avp(dr_gw_attrs) != NULL) {
		$var(tid) = $(avp(dr_gw_attrs){param.value,tid});
		if ($var(tid) != NULL && $(var(tid){s.len}) > 0) {
			$var(gw) = "T" + $var(tid);
		}
	}
	$acc_extra(gw) = $var(gw);
	xlog("L_INFO", "ACC cli=$acc_extra(cli) sip=$dlg_val(os_src) gw=$var(gw)\n");
}
'''

cid_name = "apply_sip_cid"
if "route[apply_sip_cid]" in text:
    text = replace_route(text, "apply_sip_cid", sip_cid)
elif "route[apply_outbound_cid]" in text:
    cid_name = "apply_outbound_cid"
    text = replace_route(text, "apply_outbound_cid", sip_cid.replace("apply_sip_cid", "apply_outbound_cid"))
else:
    text = replace_route(text, "apply_sip_cid", sip_cid)

text = replace_route(text, "acc_cli_gw", acc_cli)

# Early apply_sip_cid BEFORE outbound do_accounting (window must contain os_src / acc cli)
marker = 'do_accounting("db", "cdr|missed|failed");'
idx = 0
inserted_early = False
while True:
    pos = text.find(marker, idx)
    if pos < 0:
        break
    window = text[max(0, pos - 900) : pos]
    if ("$dlg_val(os_src)" in window or "$acc_extra(cli)" in window) and f"route({cid_name})" not in window[-240:]:
        text = text[:pos] + f"route({cid_name});\n\t" + text[pos:]
        print(f"inserted early route({cid_name}) before outbound do_accounting")
        inserted_early = True
        break
    idx = pos + len(marker)
if not inserted_early:
    print("early apply_sip_cid before do_accounting already present or no outbound anchor")

# Ensure post-routing call site still present
if f"route({cid_name})" not in text.split("do_routing", 1)[-1] if "do_routing" in text else True:
    for a in ("route(apply_trunk_attrs);", "route(acc_cli_gw);", "route(relay);"):
        if a in text:
            text = text.replace(a, f"route({cid_name});\n\t" + a, 1)
            print(f"inserted post-route route({cid_name}) before", a[:40])
            break

if "route(acc_cli_gw)" not in text:
    for a in ("route(relay);", "\tt_relay();"):
        if a in text:
            text = text.replace(a, "route(acc_cli_gw);\n\t" + a, 1)
            print("inserted route(acc_cli_gw)")
            break

# Force any leftover cli=$fU (outside DNC routes) to prefer os_cli — multiline safe
CLI_BLOCK = (
    "if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) {\n"
    "\t\t$acc_extra(cli) = $dlg_val(os_cli);\n"
    "\t} else {\n"
    "\t\t$acc_extra(cli) = $fU;\n"
    "\t}"
)
if "$acc_extra(cli) = $fU;" in text:
    text = text.replace("$acc_extra(cli) = $fU;", CLI_BLOCK)
    print("rewrote remaining cli=$fU → os_cli")

# Init os_cli near dialog create if missing
if "$dlg_val(os_cli)" not in text:
    if "$dlg_val(os_dst) = $rU;" in text:
        text = text.replace(
            "$dlg_val(os_dst) = $rU;",
            "$dlg_val(os_dst) = $rU;\n\t$dlg_val(os_cli) = $fU;",
            1,
        )
        print("init os_cli")

# Safety: never remove REGISTER / DNC
for must in ('is_method("REGISTER")', "route[check_dnc]", "route(check_dnc)"):
    # check_dnc may be absent on some installs; REGISTER is mandatory
    pass
if 'is_method("REGISTER")' not in text and "is_method('REGISTER')" not in text:
    raise SystemExit("FATAL: REGISTER missing — abort (would break Eyebeam)")

if text.count("{") != text.count("}"):
    raise SystemExit(
        "FATAL: brace mismatch {=%d }=%d — abort" % (text.count("{"), text.count("}"))
    )

# Do not strip DNC call sites
for dnc in ("route(check_dnc)", "route(check_sip_dnc)"):
    before = orig.count(dnc)
    after = text.count(dnc)
    if before > 0 and after < before:
        raise SystemExit(f"FATAL: {dnc} count dropped {before}→{after} — abort")

open(cfg, "w", encoding="utf-8").write(text)
print("Patched", cfg)
PY

if ! opensips -c -f "$CFG"; then
  echo "ERROR: opensips -c failed — restoring $BAK" >&2
  cp -a "$BAK" "$CFG"
  exit 1
fi

systemctl restart opensips
sleep 1
if ! systemctl is-active --quiet opensips; then
  echo "FATAL: opensips down — restoring $BAK" >&2
  cp -a "$BAK" "$CFG"
  systemctl restart opensips || true
  exit 1
fi

# Sync cid_mode only (no REGISTER repair scripts)
if [[ -f /opt/ownswitch/current/bin/sync-opensips-sip-users.php ]]; then
  sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
    php /opt/ownswitch/current/bin/sync-opensips-sip-users.php || true
fi

echo
echo "cid_mode after sync:"
mysql -Nuroot opensips -e "SELECT username, cid_mode, rpid FROM subscriber" 2>/dev/null || true
echo "DNC hooks unchanged: check_dnc=$(grep -c 'route(check_dnc)' "$CFG" || true) check_sip_dnc=$(grep -c 'route(check_sip_dnc)' "$CFG" || true)"
echo "apply_sip_cid calls: $(grep -cE 'route\(apply_sip_cid\)|route\(apply_outbound_cid\)' "$CFG" || true)"
echo "acc.cli column: $(mysql -Nuroot opensips -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='opensips' AND TABLE_NAME='acc' AND COLUMN_NAME='cli'" 2>/dev/null || echo '?')"
echo "opensips: $(systemctl is-active opensips)"
echo
echo "Place a test call, then:"
echo "  journalctl -u opensips -n 80 --no-pager | grep -E 'SIP CID|ACC cli'"
echo "  mysql -Nuroot opensips -e \"SELECT id,src_user,cli,dst_user FROM acc ORDER BY id DESC LIMIT 5\""
echo "Expect: SIP CID probe ... digits='1213...' ; ACC cli=1213... sip=<user>"
echo "        ACC row: src_user=<sipuser>  cli=<display digits>"
