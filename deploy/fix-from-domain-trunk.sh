#!/usr/bin/env bash
# After From-user rewrite, Asterisk still 403s when From domain is the
# customer FQDN (voip.xceed…). Use gateway host ($rd after do_routing) as From domain.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-from-domain-trunk.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
cd "$SRC"
git fetch origin main
git reset --hard origin/main
chattr -i "$CFG" 2>/dev/null || true

echo "======== Current gateways ========"
mysql --protocol=socket -uroot opensips -e \
  "SELECT gwid, address, strip, pri_prefix, attrs, description FROM dr_gateways;" 2>/dev/null || true

python3 - "$CFG" <<'PY'
import re, sys
path = sys.argv[1]
text = open(path, encoding="utf-8", errors="replace").read()

new_route = r'''
# Numeric CLI + From domain = trunk host (Asterisk/Magnus reject customer FQDN in From)
route[apply_outbound_cid] {
	$var(user) = $fU;
	$var(dom) = $rd;
	if ($var(dom) == NULL || $(var(dom){s.len}) == 0) {
		$var(dom) = $fd;
	}
	$var(forced) = 0;

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

	if (!($fU =~ "^[+0-9]{3,30}$")) {
		if ($avp(os_cid) != NULL && $(avp(os_cid){s.len}) > 0 && $avp(os_cid) =~ "^[+0-9]{3,30}$") {
			$var(user) = $avp(os_cid);
			$var(forced) = 1;
		}
	}
	if ($avp(os_cid_mode) == "fixed" && $avp(os_cid) != NULL && $(avp(os_cid){s.len}) > 0) {
		$var(user) = $avp(os_cid);
		$var(forced) = 1;
	}

	# Always set From to user@trunk-host when dialing off-net (do_routing already set $rd)
	remove_hf("From");
	append_hf("From: <sip:$var(user)@$var(dom)>;tag=$ft\r\n");
	xlog("L_WARN", "OWNSWITCH outbound From → sip:$var(user)@$var(dom) (dst=$rU gw=$rd)\n");
}
'''

def replace_route(src: str, name: str, body: str) -> str:
    key = f"route[{name}]"
    start = src.find(key)
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
    raise SystemExit(f"unbalanced braces in {key}")

if "route[apply_outbound_cid]" in text:
    text = replace_route(text, "apply_outbound_cid", new_route.strip())
    print("replaced route[apply_outbound_cid] (brace-aware)")
else:
    text = text.rstrip() + "\n" + new_route
    print("appended route[apply_outbound_cid]")

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
        print("hooked after do_routing")

open(path, "w", encoding="utf-8").write(text)
PY

opensips -c -f "$CFG"
systemctl restart opensips
sleep 2
systemctl is-active opensips

echo
echo "################################################################"
echo " Re-register Eyebeam, dial ONE test call."
echo " Then run:"
echo "   grep -E 'OWNSWITCH outbound|status:|403|486|183|200' /var/log/syslog | tail -n 40"
echo
echo " Expect: OWNSWITCH outbound From → DID@<trunk-ip-or-host>"
echo " NOT …@voip.xceedconnections.com"
echo
echo " CallerID note: owner rpid is 12138007447 — same as the number you dial."
echo " Set CallerID to a DID your Asterisk trunk accepts as CLI (not the dialed number)."
echo "################################################################"
