#!/usr/bin/env bash
# INVITE auth OK + drouting OK, but Eyebeam shows Forbidden because the
# GATEWAY (Asterisk) replies 403 — usually From: owner@... (non-numeric CLI).
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-trunk-403-forbidden.sh
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

echo "======== Gateways (who sent Asterisk 403) ========"
mysql --protocol=socket -uroot opensips -e \
  "SELECT id, gwid, address, strip, pri_prefix, LEFT(attrs,80) attrs, description FROM dr_gateways;
   SELECT ruleid, groupid, prefix, gwlist, priority FROM dr_rules LIMIT 20;" 2>/dev/null || true

echo "======== SIP user CID columns ========"
mysql --protocol=socket -uroot opensips -e \
  "SELECT username, rpid, cid_mode, media_policy, dtmf_pass FROM subscriber LIMIT 20;" 2>/dev/null || \
mysql --protocol=socket -uroot opensips -e \
  "SELECT username, LEFT(password,3) p FROM subscriber LIMIT 10;" 2>/dev/null || true

python3 - "$CFG" <<'PY'
import re, sys
path = sys.argv[1]
text = open(path, encoding="utf-8", errors="replace").read()
orig = text

# Ensure load_credentials pulls rpid / cid_mode (needed for From rewrite)
if "load_credentials" not in text:
    text = re.sub(
        r'(modparam\("auth_db",\s*"password_column",\s*"password"\)\s*\n)',
        r'\1modparam("auth_db", "load_credentials", '
        r'"$avp(os_media)=media_policy;$avp(os_dtmf)=dtmf_pass;'
        r'$avp(os_cid_mode)=cid_mode;$avp(os_cid)=rpid")\n',
        text,
        count=1,
    )
    print("added load_credentials")

route_outbound = r'''
# Numeric CLI for Asterisk/Magnus trunks (non-numeric From → 403 Forbidden)
route[apply_outbound_cid] {
	$var(user) = $fU;
	$var(dom) = $fd;
	$var(forced) = 0;

	# 1) Trunk attrs fu= / fd= win
	if ($avp(dr_gw_attrs) != NULL) {
		$var(attrs) = $avp(dr_gw_attrs);
		$var(fu) = $(var(attrs){param.value,fu});
		$var(fd) = $(var(attrs){param.value,fd});
		if ($var(fu) != NULL && $(var(fu){s.len}) > 0) {
			$var(user) = $(var(fu){s.unescape.param});
			$var(forced) = 1;
		}
		if ($var(fd) != NULL && $(var(fd){s.len}) > 0) {
			$var(dom) = $(var(fd){s.unescape.param});
			$var(forced) = 1;
		}
	}

	# 2) Portal CallerID (rpid) when From user is not a phone number
	if ($var(forced) == 0 && !($fU =~ "^[+0-9]{3,30}$")) {
		if ($avp(os_cid) != NULL && $(avp(os_cid){s.len}) > 0 && $avp(os_cid) =~ "^[+0-9]{3,30}$") {
			$var(user) = $avp(os_cid);
			$var(forced) = 1;
		}
	}

	# 3) cid_mode=fixed always
	if ($var(forced) == 0 && $avp(os_cid_mode) == "fixed" && $avp(os_cid) != NULL && $(avp(os_cid){s.len}) > 0) {
		$var(user) = $avp(os_cid);
		$var(forced) = 1;
	}

	if ($var(forced) == 1) {
		remove_hf("From");
		append_hf("From: <sip:$var(user)@$var(dom)>;tag=$ft\r\n");
		xlog("L_WARN", "OWNSWITCH outbound From $fU → sip:$var(user)@$var(dom)\n");
	} else if (!($fU =~ "^[+0-9]{3,30}$")) {
		xlog("L_WARN", "OWNSWITCH From=$fU is non-numeric — trunk may 403. Set SIP user CallerID or trunk From user.\n");
	}
}
'''

if "route[apply_outbound_cid]" not in text:
    text = text.rstrip() + "\n" + route_outbound
    print("appended route[apply_outbound_cid]")

# Call it after successful do_routing, before t_relay
if "route(apply_outbound_cid)" not in text:
    text2, n = re.subn(
        r'(if\s*\(\s*!do_routing\s*\([^)]*\)\s*\)\s*\{[^}]*\}\s*)',
        r'\1\n\t\troute(apply_outbound_cid);\n',
        text,
        count=1,
        flags=re.S,
    )
    if n:
        text = text2
        print("inserted route(apply_outbound_cid) after do_routing")
    else:
        # fallback: before first t_relay that follows do_routing
        idx = text.find("do_routing(1")
        if idx > 0:
            # find t_relay after that
            tr = text.find("t_relay()", idx)
            if tr > 0:
                # insert before the if (!t_relay or bare t_relay line
                line_start = text.rfind("\n", 0, tr) + 1
                text = (
                    text[:line_start]
                    + "\t\troute(apply_outbound_cid);\n"
                    + text[line_start:]
                )
                print("inserted route(apply_outbound_cid) before t_relay")
            else:
                print("WARN: no t_relay after do_routing")
        else:
            print("WARN: do_routing not found")

open(path, "w", encoding="utf-8").write(text)
print("wrote" if text != orig else "unchanged?", path)
PY

opensips -c -f "$CFG"
systemctl restart opensips
sleep 2
systemctl is-active opensips || { journalctl -u opensips -n 40 --no-pager; exit 1; }

echo "======== Sync trunks + SIP users (refresh rpid/attrs) ========"
CUR="$(readlink -f /opt/ownswitch/current)"
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-trunks.php" || true

mysql --protocol=socket -uroot opensips -e \
  "SELECT username, rpid, cid_mode FROM subscriber WHERE username='owner';
   SELECT gwid, address, LEFT(attrs,100) attrs FROM dr_gateways;" 2>/dev/null || true

echo
echo "################################################################"
echo " OpenSIPS was fine — Asterisk trunk returned 403 Forbidden."
echo " We now rewrite non-numeric From (owner) → portal CallerID (rpid)"
echo " or trunk From user (attrs fu=)."
echo
echo " Eyebeam: re-register, dial again."
echo " Watch:  grep OWNSWITCH /var/log/syslog | tail -n 20"
echo
echo " If still 403:"
echo "  1) Portal → SIP user 'owner' → set CallerID to a DID the trunk allows"
echo "     (mode Fixed), Save, then sync-opensips-sip-users.php"
echo "  2) Portal → Trunk → set From user to that DID, Save, sync trunks"
echo "  3) Confirm Asterisk allowlists this server's IP 62.238.34.202"
echo "################################################################"
