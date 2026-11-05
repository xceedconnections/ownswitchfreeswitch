#!/usr/bin/env bash
# Fix CDR CallerID for Softphone display name (Eyebeam Display Name → CLI).
#
# Why CDR shows SIP username:
#  1) append_hf(From) does not refresh $fU → ACC cli=$fU stays "owner"
#  2) Custom "acc_extra before relay" often does $acc_extra(src)=$fU (wrong field / stale $fU)
# Fix: dlg_val(os_cli) + force every ACC cli/src write to use os_cli / os_src.
#
# REGISTER-safe. Usage: sudo bash /opt/ownswitch-src/deploy/fix-opensips-cli-cdr.sh
set -Eeuo pipefail
CFG="${1:-/etc/opensips/opensips.cfg}"
[[ -f "$CFG" ]] || { echo "Missing $CFG"; exit 1; }
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

echo "== OpenSIPS CLI → CDR fix =="

ensure_col() {
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
ensure_col media_policy "media_policy VARCHAR(16) NOT NULL DEFAULT 'relay'"
ensure_col dtmf_pass "dtmf_pass VARCHAR(8) NOT NULL DEFAULT 'yes'"
ensure_col cid_mode "cid_mode VARCHAR(16) NOT NULL DEFAULT 'passthrough'"
ensure_col cid_min_len "cid_min_len INT UNSIGNED NOT NULL DEFAULT 0"
ensure_col cid_max_len "cid_max_len INT UNSIGNED NOT NULL DEFAULT 0"
ensure_col allow_calls "allow_calls TINYINT(1) NOT NULL DEFAULT 1"
ensure_col dst_format "dst_format VARCHAR(16) NOT NULL DEFAULT 'as_is'"
ensure_col dnc_group_id "dnc_group_id INT UNSIGNED NOT NULL DEFAULT 0"
ensure_col rpid "rpid VARCHAR(64) NULL"

echo "OpenSIPS subscriber cid_mode values:"
mysql -Nuroot opensips -e "SELECT username, cid_mode, LEFT(IFNULL(rpid,''),20) FROM subscriber LIMIT 20" 2>/dev/null || true

BAK="${CFG}.bak-cli-$(date +%Y%m%d%H%M%S)"
cp -a "$CFG" "$BAK"
echo "Backup: $BAK"

python3 - "$CFG" <<'PY'
import re, sys
cfg = sys.argv[1]
text = open(cfg, encoding="utf-8", errors="replace").read()

def replace_route(text: str, name: str, body: str) -> str:
    marker = f"route[{name}]"
    start = text.find(marker)
    if start < 0:
        if "route[relay]" in text:
            text = text.replace("route[relay]", body.rstrip() + "\n\nroute[relay]", 1)
        else:
            text += "\n" + body
        print(f"inserted route[{name}]")
        return text
    brace = text.find("{", start)
    if brace < 0:
        raise SystemExit(f"no '{{' after {marker}")
    depth = 0
    i = brace
    while i < len(text):
        if text[i] == "{":
            depth += 1
        elif text[i] == "}":
            depth -= 1
            if depth == 0:
                end = i + 1
                while end < len(text) and text[end] in " \t\r\n":
                    end += 1
                text = text[:start] + body.rstrip() + "\n\n" + text[end:]
                print(f"replaced route[{name}]")
                return text
        i += 1
    raise SystemExit(f"unbalanced braces in route[{name}]")

sip_cid = r'''route[apply_sip_cid] {
	$dlg_val(os_cli) = $fU;
	if ($avp(os_cid_mode) == NULL || $(avp(os_cid_mode){s.len}) == 0 || $avp(os_cid_mode) == "passthrough") {
		return;
	}
	$var(user) = $fU;
	$var(dom) = $fd;
	if ($avp(os_cid_mode) == "fixed" && $avp(os_cid) != NULL && $(avp(os_cid){s.len}) > 0) {
		$var(user) = $(avp(os_cid){re.subst,/[^0-9+]//g});
	} else if ($avp(os_cid_mode) == "display_name") {
		$var(dn) = $fn;
		if ($var(dn) == NULL || $(var(dn){s.len}) == 0) {
			$var(dn) = $(hdr(From){nameaddr.name});
		}
		if ($var(dn) != NULL && $(var(dn){s.len}) > 0) {
			$var(dn) = $(var(dn){re.subst,/[^0-9+]//g});
			if ($var(dn) =~ "^[+0-9]{3,30}$") {
				$var(user) = $var(dn);
			} else {
				xlog("L_WARN", "SIP CID display_name raw='$fn' — need digits for CLI\n");
				return;
			}
		} else {
			xlog("L_WARN", "SIP CID display_name empty — keeping From=$fU\n");
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
	xlog("L_INFO", "SIP CID mode=$avp(os_cid_mode) to sip:$var(user)@$var(dom) os_cli=$dlg_val(os_cli)\n");
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

trunk = r'''route[apply_trunk_attrs] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(attrs) = $avp(dr_gw_attrs);
	$var(fu) = $(var(attrs){param.value,fu});
	$var(fd) = $(var(attrs){param.value,fd});
	$var(cidadd) = $(var(attrs){param.value,cidadd});
	$var(cidrem) = $(var(attrs){param.value,cidrem});

	if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) {
		$var(user) = $dlg_val(os_cli);
	} else {
		$var(user) = $fU;
	}
	if ($var(cidrem) != NULL && $(var(cidrem){s.len}) > 0) {
		$var(cidrem) = $(var(cidrem){s.unescape.param});
		if ($(var(user){s.substr,0,$(var(cidrem){s.len})}) == $var(cidrem)) {
			$var(user) = $(var(user){s.substr,$(var(cidrem){s.len}),0});
		}
	}
	if ($var(cidadd) != NULL && $(var(cidadd){s.len}) > 0) {
		$var(user) = $(var(cidadd){s.unescape.param}) + $var(user);
	}
	if ($var(fu) != NULL && $(var(fu){s.len}) > 0) {
		$var(user) = $(var(fu){s.unescape.param});
	}
	$var(user) = $(var(user){re.subst,/[^0-9A-Za-z+_-]//g});
	$var(dom) = $fd;
	if ($var(fd) != NULL && $(var(fd){s.len}) > 0) {
		$var(dom) = $(var(fd){s.unescape.param});
	}
	if (($var(fu) != NULL && $(var(fu){s.len}) > 0) || ($var(fd) != NULL && $(var(fd){s.len}) > 0)
		|| ($var(cidadd) != NULL && $(var(cidadd){s.len}) > 0) || ($var(cidrem) != NULL && $(var(cidrem){s.len}) > 0)) {
		$dlg_val(os_cli) = $var(user);
		$acc_extra(cli) = $var(user);
		remove_hf("From");
		append_hf("From: <sip:$var(user)@$var(dom)>;tag=$ft\r\n");
		remove_hf("P-Asserted-Identity");
		append_hf("P-Asserted-Identity: <sip:$var(user)@$var(dom)>\r\n");
		xlog("L_INFO", "trunk From rewrite to sip:$var(user)@$var(dom) os_cli=$dlg_val(os_cli)\n");
	}
}
'''

cid_len = r'''route[check_cid_len] {
	if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) {
		$var(clid) = $(dlg_val(os_cli){re.subst,/[^0-9]//g});
	} else {
		$var(clid) = $(fU{re.subst,/[^0-9]//g});
	}
	$var(clen) = $(var(clid){s.len});
	if ($avp(os_cid_min) != NULL && $(avp(os_cid_min){s.int}) > 0 && $var(clen) < $(avp(os_cid_min){s.int})) {
		xlog("L_WARN", "Wrong CallerID Length min user=$au cli=$var(clid) len=$var(clen)\n");
		send_reply(484, "Wrong CallerID Length");
		exit;
	}
	if ($avp(os_cid_max) != NULL && $(avp(os_cid_max){s.int}) > 0 && $var(clen) > $(avp(os_cid_max){s.int})) {
		xlog("L_WARN", "Wrong CallerID Length max user=$au cli=$var(clid) len=$var(clen)\n");
		send_reply(484, "Wrong CallerID Length");
		exit;
	}
}
'''

if "route[apply_sip_cid]" in text:
    text = replace_route(text, "apply_sip_cid", sip_cid)
elif "route[apply_outbound_cid]" in text:
    text = replace_route(text, "apply_outbound_cid", sip_cid.replace("apply_sip_cid", "apply_outbound_cid"))
else:
    text = replace_route(text, "apply_sip_cid", sip_cid)

text = replace_route(text, "acc_cli_gw", acc_cli)
if "route(acc_cli_gw)" not in text:
    for anchor in (
        'xlog("L_INFO", "OWNSWITCH acc_extra before relay',
        "route(relay);",
        "\tt_relay();",
    ):
        pinned = "route(acc_cli_gw);\n\t" + anchor
        if anchor in text and pinned not in text:
            text = text.replace(anchor, pinned, 1)
            print("call site route(acc_cli_gw); before", anchor[:40])
            break

if "route[apply_trunk_attrs]" in text:
    text = replace_route(text, "apply_trunk_attrs", trunk)
if "route[check_cid_len]" in text:
    text = replace_route(text, "check_cid_len", cid_len)

# --- Global ACC field corrections (custom hybrid cfgs) ---
# Never store SIP username in cli via stale $fU
CLI_BLOCK = (
    "if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) {\n"
    "\t\t$acc_extra(cli) = $dlg_val(os_cli);\n"
    "\t} else {\n"
    "\t\t$acc_extra(cli) = $fU;\n"
    "\t}"
)
n = text.count("$acc_extra(cli) = $fU;")
if n:
    text = text.replace("$acc_extra(cli) = $fU;", CLI_BLOCK)
    print(f"rewrote $acc_extra(cli)=$fU → os_cli ({n} site(s))")

# Custom before-relay block: src must stay SIP user (os_src), cli = os_cli
# Old broken pattern from fix-cdr-import-now.sh:
#   $acc_extra(src) = $fU;
#   xlog(... src=$fU ...)
old_relay = re.compile(
    r'\$acc_extra\(src\)\s*=\s*\$fU;\s*\n'
    r'(\s*)\$acc_extra\(dst\)\s*=\s*\$dlg_val\(os_dst\);\s*\n'
    r'(\s*)\$acc_extra\(ip\)\s*=\s*\$si;\s*\n'
    r'(\s*)xlog\("L_INFO",\s*"OWNSWITCH acc_extra before relay src=\$fU dst=\$dlg_val\(os_dst\)\\n"\);',
)
new_relay = (
    '$acc_extra(src) = $dlg_val(os_src);\n'
    r'\1$acc_extra(dst) = $dlg_val(os_dst);\n'
    r'\1$acc_extra(ip) = $si;\n'
    r'\1if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) { $acc_extra(cli) = $dlg_val(os_cli); } else { $acc_extra(cli) = $fU; }\n'
    r'\1xlog("L_INFO", "OWNSWITCH acc_extra before relay sip=$dlg_val(os_src) cli=$acc_extra(cli) dst=$dlg_val(os_dst)\n");'
)
text2, nrelay = old_relay.subn(new_relay, text)
if nrelay:
    text = text2
    print(f"fixed OWNSWITCH acc_extra before relay block ({nrelay})")
else:
    # Looser: any before-relay xlog — inject cli from os_cli just above it
    if "OWNSWITCH acc_extra before relay" in text and "$acc_extra(cli) = $dlg_val(os_cli)" not in text:
        text = text.replace(
            'xlog("L_INFO", "OWNSWITCH acc_extra before relay',
            'if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) { $acc_extra(cli) = $dlg_val(os_cli); } else if ($acc_extra(cli) == NULL) { $acc_extra(cli) = $fU; }\n'
            '\t$acc_extra(src) = $dlg_val(os_src);\n'
            '\txlog("L_INFO", "OWNSWITCH acc_extra before relay',
            1,
        )
        print("injected os_cli into custom acc_extra before relay")

# Init os_cli next to os_src / os_dst if missing
if "$dlg_val(os_cli)" not in text.split("create_dialog", 1)[-1][:500] if "create_dialog" in text else True:
    if "$dlg_val(os_dst) = $rU;" in text and "$dlg_val(os_cli) = $fU;" not in text:
        text = text.replace(
            "$dlg_val(os_dst) = $rU;",
            "$dlg_val(os_dst) = $rU;\n\t$dlg_val(os_cli) = $fU;",
            1,
        )
        print("init os_cli after os_dst")

# load_credentials must expose cid_mode
lc = re.search(r'modparam\("auth_db",\s*"load_credentials",\s*"([^"]*)"\)', text)
if lc and "os_cid_mode" not in lc.group(1):
    creds = lc.group(1)
    if not creds.endswith(";"):
        creds += ";"
    creds += "$avp(os_cid_mode)=cid_mode;$avp(os_cid)=rpid;$avp(os_cid_min)=cid_min_len;$avp(os_cid_max)=cid_max_len"
    text = text[: lc.start(1)] + creds + text[lc.end(1) :]
    print("load_credentials: added cid_mode")

if 'is_method("REGISTER")' not in text and "is_method('REGISTER')" not in text:
    raise SystemExit("FATAL: REGISTER route missing after patch")

# Preserve / re-pin DNC on outbound path (this script must not disable DNC)
for anchor in (
    "route(acc_cli_gw);",
    'xlog("L_INFO", "OWNSWITCH acc_extra before relay',
    'xlog("L_INFO", "ACC cli=',
    "route(apply_sip_cid);",
):
    if anchor not in text:
        continue
    pinned = "route(check_dnc);\n\t" + anchor
    if "route[check_dnc]" in text and pinned not in text:
        text = text.replace(anchor, pinned, 1)
        print("re-pinned route(check_dnc); before:", anchor[:40])
        break

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
  echo "FATAL: opensips not active — restoring $BAK" >&2
  journalctl -u opensips -n 40 --no-pager >&2 || true
  cp -a "$BAK" "$CFG"
  systemctl restart opensips || true
  exit 1
fi

fail2ban-client unban --all >/dev/null 2>&1 || true

# Re-push SIP users so subscriber.cid_mode = display_name
if [[ -f /opt/ownswitch/current/bin/sync-opensips-sip-users.php ]]; then
  echo "Syncing SIP users → OpenSIPS subscriber.cid_mode…"
  sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
    php /opt/ownswitch/current/bin/sync-opensips-sip-users.php || true
fi

echo
echo "subscriber cid_mode after sync:"
mysql -Nuroot opensips -e "SELECT username, cid_mode FROM subscriber" 2>/dev/null || true
echo
echo "opensips: $(systemctl is-active opensips)"
echo
echo "Test:"
echo "  1) Re-register Eyebeam"
echo "  2) Display Name = digits only (e.g. 12138007000), no letters"
echo "  3) Call; watch: journalctl -u opensips -f | grep -E 'SIP CID|ACC cli'"
echo "  Expect: SIP CID mode=display_name … os_cli=1213…"
echo "          ACC cli=1213… sip=owner"
