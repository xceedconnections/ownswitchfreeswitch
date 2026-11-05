#!/usr/bin/env bash
# Strip quoted Eyebeam Display Name CLI; ACC stores SIP user + CLI + gateway;
# redeploy portal CDR/report/trunk-window UI.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-cid-quotes-and-cdr-ui.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
ENV_FILE=/etc/ownswitch/ownswitch.env
cd "$SRC"
git fetch origin main
git reset --hard origin/main
chattr -i "$CFG" 2>/dev/null || true

PW=""
[[ -f /etc/ownswitch/secrets/mysql_opensips_password ]] && PW="$(tr -d '\n' </etc/ownswitch/secrets/mysql_opensips_password)"
[[ -n "$PW" ]] || PW="opensips"
DB_URL="$(python3 -c "import urllib.parse,sys; print('mysql://opensips:%s@127.0.0.1/opensips'%urllib.parse.quote(sys.argv[1],safe=''))" "$PW")"

mysql --protocol=socket -uroot opensips <<'SQL' || true
ALTER TABLE acc ADD COLUMN cli VARCHAR(64) NULL;
ALTER TABLE acc ADD COLUMN gw VARCHAR(64) NULL;
ALTER TABLE missed_calls ADD COLUMN cli VARCHAR(64) NULL;
ALTER TABLE missed_calls ADD COLUMN gw VARCHAR(64) NULL;
SQL

python3 - "$CFG" "$DB_URL" <<'PY'
import re, sys
path, db_url = sys.argv[1:3]
text = open(path, encoding="utf-8", errors="replace").read()

text = re.sub(r'^[ \t]*;dst_user=.*\n?', "", text, flags=re.M)
text = re.sub(r'^[ \t]*modparam\(\s*"acc"\s*,\s*"db_extra".*\n?', "", text, flags=re.M)
text = text.replace("if (status =~ ", "if ($rs =~ ")

# Rebuild acc modparams (keep loadmodule)
text = re.sub(r'^[ \t]*modparam\(\s*"acc"\s*,.*\n', "", text, flags=re.M)
if 'loadmodule "acc.so"' not in text:
    text = re.sub(r'(loadmodule "registrar\.so".*\n)', r'\1loadmodule "acc.so"\n', text, count=1)
text = re.sub(
    r'(loadmodule "acc\.so"\s*\n)',
    r'\1'
    f'modparam("acc", "db_url", "{db_url}")\n'
    f'modparam("acc", "early_media", 0)\n'
    f'modparam("acc", "report_cancels", 1)\n'
    f'modparam("acc", "extra_fields", '
    f'"db: src->src_user; dst->dst_user; ip->src_ip; cli->cli; gw->gw")\n',
    text,
    count=1,
)

def replace_route(src, name, body):
    start = src.find(name)
    if start < 0:
        return None
    brace = src.find("{", start)
    depth = 0
    i = brace
    while i < len(src):
        if src[i] == "{":
            depth += 1
        elif src[i] == "}":
            depth -= 1
            if depth == 0:
                return src[:start] + body.rstrip() + "\n" + src[i+1:]
        i += 1
    raise SystemExit("unbalanced " + name)

sip_cid = r'''route[apply_sip_cid] {
	if ($avp(os_cid_mode) == NULL || $(avp(os_cid_mode){s.len}) == 0 || $avp(os_cid_mode) == "passthrough") {
		return;
	}
	$var(user) = $fU;
	$var(dom) = $fd;
	if ($avp(os_cid_mode) == "fixed" && $avp(os_cid) != NULL && $(avp(os_cid){s.len}) > 0) {
		$var(user) = $(avp(os_cid){re.subst,/[^0-9+]//g});
	} else if ($avp(os_cid_mode) == "display_name") {
		$var(dn) = $fn;
		if ($var(dn) != NULL && $(var(dn){s.len}) > 0) {
			$var(dn) = $(var(dn){re.subst,/[^0-9+]//g});
			if ($var(dn) =~ "^[+0-9]{3,30}$") {
				$var(user) = $var(dn);
			}
		}
	} else {
		return;
	}
	remove_hf("From");
	append_hf("From: <sip:$var(user)@$var(dom)>;tag=$ft\r\n");
	remove_hf("P-Asserted-Identity");
	append_hf("P-Asserted-Identity: <sip:$var(user)@$var(dom)>\r\n");
	remove_hf("Remote-Party-ID");
	append_hf("Remote-Party-ID: <sip:$var(user)@$var(dom)>;party=calling;privacy=off;screen=yes\r\n");
	xlog("L_INFO", "SIP CID mode=$avp(os_cid_mode) → sip:$var(user)@$var(dom)\n");
}
'''

acc_cli = r'''route[acc_cli_gw] {
	$acc_extra(src) = $dlg_val(os_src);
	$acc_extra(dst) = $dlg_val(os_dst);
	$acc_extra(ip) = $si;
	$acc_extra(cli) = $fU;
	$acc_extra(gw) = $avp(___dr_gw_id);
	xlog("L_INFO", "ACC cli=$fU sip=$dlg_val(os_src) gw=$avp(___dr_gw_id)\n");
}
'''

# Prefer template route names; also rewrite legacy apply_outbound_cid if present
if "route[apply_sip_cid]" in text:
    text = replace_route(text, "route[apply_sip_cid]", sip_cid)
elif "route[apply_outbound_cid]" in text:
    text = replace_route(text, "route[apply_outbound_cid]", sip_cid.replace("apply_sip_cid", "apply_outbound_cid"))
else:
    text = text.rstrip() + "\n\n" + sip_cid + "\n"

if "route[acc_cli_gw]" in text:
    text = replace_route(text, "route[acc_cli_gw]", acc_cli)
else:
    # Insert before relay route if possible
    if "route[relay]" in text:
        text = text.replace("route[relay]", acc_cli + "\nroute[relay]", 1)
    else:
        text = text.rstrip() + "\n\n" + acc_cli + "\n"

# Ensure route(acc_cli_gw) is called after trunk attrs / before relay (outbound)
if "route(acc_cli_gw)" not in text:
    text = re.sub(
        r'(route\(apply_trunk_attrs\);\s*)',
        r'\1\n\troute(acc_cli_gw);\n',
        text,
        count=1,
    )
    if "route(acc_cli_gw)" not in text:
        text = re.sub(
            r'(route\(apply_sip_cid\);\s*)',
            r'\1\n\troute(acc_cli_gw);\n',
            text,
            count=1,
        )
    if "route(acc_cli_gw)" not in text:
        text = re.sub(
            r'(route\(apply_outbound_cid\);\s*)',
            r'\1\n\troute(acc_cli_gw);\n',
            text,
            count=1,
        )

# Strip junk from trunk From user
text = re.sub(
    r'(\$var\(user\) = \$\(var\(fu\)\{s\.unescape\.param\}\);\s*)',
    r'\1\n\t$var(user) = $(var(user){re.subst,/[^0-9A-Za-z+_-]//g});\n',
    text,
    count=1,
)

# Dialog ACC seed keeps SIP username (do not drop do_accounting)
if '$dlg_val(os_src)' not in text:
    text = text.replace(
        'create_dialog("B");',
        'create_dialog("B");\n\t$dlg_val(os_src) = $fU;\n\t$dlg_val(os_dst) = $rU;\n\t$acc_extra(src) = $fU;\n\t$acc_extra(dst) = $rU;\n\t$acc_extra(ip) = $si;',
        1,
    )
elif '$acc_extra(src)' not in text:
    text = text.replace(
        '$dlg_val(os_dst) = $rU;',
        '$dlg_val(os_dst) = $rU;\n\t$acc_extra(src) = $fU;\n\t$acc_extra(dst) = $rU;\n\t$acc_extra(ip) = $si;',
        1,
    )

open(path, "w", encoding="utf-8").write(text)
if "if (status =~" in text or "db_extra" in text:
    raise SystemExit("FATAL leftover bad tokens")
print("OK opensips.cfg")
PY

opensips -c -f "$CFG"
systemctl restart opensips
sleep 2
systemctl is-active opensips

bash "$SRC/deploy/refresh-ui.sh"
CUR="$(readlink -f /opt/ownswitch/current)"
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true

PDB=$(grep -E '^DB_DATABASE=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)
PDB="${PDB:-ownswitch}"
mysql --protocol=socket -uroot "$PDB" -e "UPDATE opensips_acc_import_state SET last_id=0;" 2>/dev/null || true
systemctl restart ownswitch-worker 2>/dev/null || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/worker_cdr.php" --limit=500 || true

echo
echo "################################################################"
echo " Display Name quotes stripped → vendor sees 12138007474 (no quotes)."
echo " Re-register Eyebeam, dial once. Syslog:"
echo "   SIP CID mode=display_name → sip:12138007474@..."
echo "   ACC cli=12138007474 sip=<sipuser> gw=T..."
echo " Portal: Customer + SIP + CallerID + SIP code + Trunk."
echo " Trunk group edit: 15m / 30m / 1h window selector."
echo "################################################################"
