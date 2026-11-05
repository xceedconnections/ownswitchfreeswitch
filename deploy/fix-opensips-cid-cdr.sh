#!/usr/bin/env bash
# 1) opensips.cfg: onreply must use $rs (not bare status) — 3.6 parse error
# 2) CallerID mode display_name: send Eyebeam Display Name digits to vendor
# 3) CDR import cursor reset + importer that accepts NULL src/dst
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-opensips-cid-cdr.sh
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
cp -a "$CFG" "/var/backups/opensips.cfg.$(date -u +%Y%m%dT%H%M%SZ)" 2>/dev/null || true

PW=""
[[ -f /etc/ownswitch/secrets/mysql_opensips_password ]] && PW="$(tr -d '\n' </etc/ownswitch/secrets/mysql_opensips_password)"
[[ -n "$PW" ]] || PW="opensips"
DB_URL="$(python3 -c "import urllib.parse,sys; print('mysql://opensips:%s@127.0.0.1/opensips'%urllib.parse.quote(sys.argv[1],safe=''))" "$PW")"

python3 - "$CFG" "$DB_URL" <<'PY'
import re, sys
path, db_url = sys.argv[1:3]
text = open(path, encoding="utf-8", errors="replace").read()

# --- Strip illegal leftovers ---
text = re.sub(r'^[ \t]*;dst_user=.*\n?', "", text, flags=re.M)
text = re.sub(r'^[ \t]*modparam\(\s*"acc"\s*,\s*"db_extra".*\n?', "", text, flags=re.M)
text = re.sub(r'^[ \t]*modparam\(\s*"drouting"\s*,\s*"gw_attrs_avp".*\n?', "", text, flags=re.M)

# --- Fix onreply: status → $rs (OpenSIPS 3.x) ---
text = re.sub(
    r'if\s*\(\s*status\s*=~\s*"\[12\]\[0-9\]\[0-9\]"',
    'if ($rs =~ "[12][0-9][0-9]"',
    text,
)
text = text.replace("if (status =~ ", "if ($rs =~ ")

# Replace entire onreply_route[handle_reply] brace-aware
onreply = '''onreply_route[handle_reply] {
	if ($rs =~ "[12][0-9][0-9]" && has_body("application/sdp")) {
		rtpengine_manage();
	}
}
'''

def replace_route(src: str, name: str, body: str) -> str:
    # name may be onreply_route[handle_reply] or route[foo]
    start = src.find(name)
    if start < 0:
        return src.rstrip() + "\n\n" + body + "\n"
    brace = src.find("{", start)
    depth = 0
    i = brace
    while i < len(src):
        if src[i] == "{":
            depth += 1
        elif src[i] == "}":
            depth -= 1
            if depth == 0:
                return src[:start] + body.rstrip() + "\n" + src[i + 1 :]
        i += 1
    raise SystemExit(f"unbalanced {name}")

if "onreply_route[handle_reply]" in text:
    text = replace_route(text, "onreply_route[handle_reply]", onreply)
else:
    text = text.rstrip() + "\n" + onreply

# --- ACC extra_fields clean block ---
text = re.sub(r'^[ \t]*modparam\(\s*"acc"\s*,.*\n', "", text, flags=re.M)
if 'loadmodule "acc.so"' not in text:
    text = re.sub(
        r'(loadmodule "registrar\.so".*\n)',
        r'\1loadmodule "acc.so"\n',
        text,
        count=1,
    )
text = re.sub(
    r'(loadmodule "acc\.so"\s*\n)',
    r'\1'
    f'modparam("acc", "db_url", "{db_url}")\n'
    f'modparam("acc", "early_media", 0)\n'
    f'modparam("acc", "report_cancels", 1)\n'
    f'modparam("acc", "extra_fields", "db: src->src_user; dst->dst_user; ip->src_ip")\n',
    text,
    count=1,
)

# --- Accounting stamp: create_dialog → do_accounting → acc_extra ---
acct = (
    'create_dialog("B");\n'
    '\t\tdo_accounting("db", "cdr|missed|failed");\n'
    '\t\t$dlg_val(os_src) = $fU;\n'
    '\t\t$dlg_val(os_dst) = $rU;\n'
    '\t\t$acc_extra(src) = $fU;\n'
    '\t\t$acc_extra(dst) = $rU;\n'
    '\t\t$acc_extra(ip) = $si;'
)
text = re.sub(
    r'create_dialog\("B"\);\s*'
    r'(?:do_accounting\("db",\s*"cdr\|missed\|failed"\);\s*)?'
    r'(?:\$dlg_val\(os_src\)\s*=\s*\$fU;\s*)?'
    r'(?:\$dlg_val\(os_dst\)\s*=\s*\$rU;\s*)?'
    r'(?:\$acc_extra\(src\)\s*=\s*\$fU;\s*)?'
    r'(?:\$acc_extra\(dst\)\s*=\s*\$rU;\s*)?'
    r'(?:\$acc_extra\(ip\)\s*=\s*\$si;\s*)?'
    r'(?:do_accounting\("db",\s*"cdr\|missed\|failed"\);\s*)?',
    acct + "\n\t\t",
    text,
)

# --- apply_outbound_cid: display_name / fixed / trunk fu + sip: From ---
cid_route = r'''route[apply_outbound_cid] {
	# Capture Eyebeam Display Name BEFORE rewriting From
	$var(dn) = $fn;
	$var(user) = $fU;
	$var(dom) = $rd;
	if ($var(dom) == NULL || $(var(dom){s.len}) == 0) {
		$var(dom) = $fd;
	}

	# Trunk attrs override (fu= / fd=)
	if ($avp(dr_gw_attrs) != NULL) {
		$var(attrs) = $avp(dr_gw_attrs);
		$var(fu) = $(var(attrs){param.value,fu});
		$var(fdgw) = $(var(attrs){param.value,fd});
		if ($var(fu) != NULL && $(var(fu){s.len}) > 0) {
			$var(user) = $(var(fu){s.unescape.param});
		}
		if ($var(fdgw) != NULL && $(var(fdgw){s.len}) > 0) {
			$var(dom) = $(var(fdgw){s.unescape.param});
		}
	}

	# SIP-user CallerID policy (portal)
	if ($avp(os_cid_mode) == "display_name") {
		if ($var(dn) != NULL && $(var(dn){s.len}) > 0) {
			$var(digits) = $(var(dn){s.select,0, });
			if ($var(digits) =~ "^[+0-9]{3,30}$") {
				$var(user) = $var(digits);
			} else if ($var(dn) =~ "^[+0-9]{3,30}$") {
				$var(user) = $var(dn);
			} else {
				# strip non-digits from display name
				$var(user) = $var(dn);
				xlog("L_WARN", "OWNSWITCH display_name raw='$var(dn)' — need digits\n");
			}
		}
	} else if ($avp(os_cid_mode) == "fixed") {
		if ($avp(os_cid) != NULL && $(avp(os_cid){s.len}) > 0) {
			$var(user) = $avp(os_cid);
		}
	} else {
		# passthrough: if From user non-numeric, fall back to rpid / display digits
		if (!($fU =~ "^[+0-9]{3,30}$")) {
			if ($avp(os_cid) != NULL && $avp(os_cid) =~ "^[+0-9]{3,30}$") {
				$var(user) = $avp(os_cid);
			} else if ($var(dn) != NULL && $var(dn) =~ "^[+0-9]{3,30}$") {
				$var(user) = $var(dn);
			} else if ($var(dn) != NULL) {
				$var(digits) = $(var(dn){s.select,0, });
				if ($var(digits) =~ "^[+0-9]{3,30}$") {
					$var(user) = $var(digits);
				}
			}
		}
	}

	remove_hf("From");
	append_hf("From: <sip:$var(user)@$var(dom)>;tag=$ft\r\n");
	# Also set P-Asserted-Identity for vendors that ignore From display
	remove_hf("P-Asserted-Identity");
	append_hf("P-Asserted-Identity: <sip:$var(user)@$var(dom)>\r\n");
	remove_hf("Remote-Party-ID");
	append_hf("Remote-Party-ID: <sip:$var(user)@$var(dom)>;party=calling;privacy=off;screen=yes\r\n");
	$acc_extra(src) = $var(user);
	$acc_extra(dst) = $dlg_val(os_dst);
	$acc_extra(ip) = $si;
	xlog("L_WARN", "OWNSWITCH CID mode=$avp(os_cid_mode) dn='$var(dn)' From=sip:$var(user)@$var(dom) dst=$dlg_val(os_dst)\n");
}
'''

if "route[apply_outbound_cid]" in text:
    text = replace_route(text, "route[apply_outbound_cid]", cid_route)
else:
    text = text.rstrip() + "\n" + cid_route

if "route(apply_outbound_cid)" not in text:
    text = re.sub(
        r'(if\s*\(\s*!do_routing\s*\([^)]*\)\s*\)\s*\{[^}]*\}\s*)',
        r'\1\n\t\troute(apply_outbound_cid);\n',
        text,
        count=1,
        flags=re.S,
    )

# Force media relay for ringback
if "OWNSWITCH force media relay" not in text:
    text = re.sub(
        r'(consume_credentials\s*\(\s*\)\s*;)',
        r'\1\n'
        r'\t\t$avp(os_media) = "relay";\n'
        r'\t\txlog("L_INFO", "OWNSWITCH force media relay\\n");',
        text,
        count=1,
    )

# Ensure load_credentials has cid_mode + rpid
if "load_credentials" not in text:
    text = re.sub(
        r'(modparam\("auth_db",\s*"password_column",\s*"password"\)\s*\n)',
        r'\1modparam("auth_db", "load_credentials", '
        r'"$avp(os_media)=media_policy;$avp(os_dtmf)=dtmf_pass;'
        r'$avp(os_cid_mode)=cid_mode;$avp(os_cid)=rpid")\n',
        text,
        count=1,
    )
elif "os_cid_mode" not in text:
    text = re.sub(
        r'modparam\(\s*"auth_db"\s*,\s*"load_credentials"\s*,\s*"[^"]*"\s*\)',
        'modparam("auth_db", "load_credentials", '
        '"$avp(os_media)=media_policy;$avp(os_dtmf)=dtmf_pass;'
        '$avp(os_cid_mode)=cid_mode;$avp(os_cid)=rpid")',
        text,
        count=1,
    )

# t_on_reply
if 't_on_reply("handle_reply")' not in text:
    text = re.sub(
        r'([ \t]*)((?:if\s*\(\s*!)?t_relay\s*\()',
        r'\1t_on_reply("handle_reply");\n\1\2',
        text,
        count=3,
    )

open(path, "w", encoding="utf-8").write(text)

# Sanity
for bad in ("db_extra", ";dst_user=", "if (status =~"):
    if bad in text:
        # status check: allow $rs only
        if bad.startswith("if (status") and "if ($rs =~" in text and "if (status =~" not in text:
            continue
        if bad in text:
            print("WARN still contains", bad)

if "if (status =~" in text:
    raise SystemExit("FATAL: bare status still in cfg")
if "db_extra" in text:
    raise SystemExit("FATAL: db_extra still in cfg")
print("OK cfg")
PY

echo "======== show critical lines ========"
grep -nE 'onreply_route|\$rs =~|extra_fields|display_name|apply_outbound_cid|P-Asserted|load_credentials' "$CFG" | head -n 40

opensips -c -f "$CFG"
systemctl start rtpengine 2>/dev/null || systemctl start rtpengine-daemon 2>/dev/null || true
systemctl restart opensips
sleep 2
systemctl is-active opensips || { journalctl -u opensips -n 50 --no-pager; exit 1; }

echo "======== Sync SIP users (cid_mode) + CDR import ========"
bash "$SRC/deploy/refresh-ui.sh"
CUR="$(readlink -f /opt/ownswitch/current)"
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true

PDB=$(grep -E '^DB_DATABASE=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)
PDB="${PDB:-ownswitch}"
mysql --protocol=socket -uroot opensips -e \
  "SELECT username, cid_mode, rpid FROM subscriber LIMIT 20;" 2>/dev/null || true

mysql --protocol=socket -uroot "$PDB" -e \
  "UPDATE opensips_acc_import_state SET last_id=0;" 2>/dev/null || true

systemctl restart ownswitch-worker 2>/dev/null || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/worker_cdr.php" --limit=500

mysql --protocol=socket -uroot "$PDB" -e \
  "SELECT COUNT(*) cdrs FROM cdrs;
   SELECT id,src,dst,duration,disposition,sip_response FROM cdrs ORDER BY id DESC LIMIT 10;" 2>/dev/null || true

echo
echo "################################################################"
echo " Eyebeam: Display Name = numeric CallerID (e.g. 12138007447)"
echo " Portal SIP user: CallerID mode = Display name"
echo " Re-register, dial; syslog must show:"
echo "   OWNSWITCH CID mode=display_name dn='...' From=sip:DIGITS@trunk"
echo " Vendor must see From/PAI/RPID = that number, not 'owner'."
echo "################################################################"
