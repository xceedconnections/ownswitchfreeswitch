#!/usr/bin/env bash
# Asterisk 416 = From missing sip: scheme. Force-replace apply_outbound_cid.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-from-sip-scheme.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
cd "$SRC"
git fetch origin main
git reset --hard origin/main
chattr -i "$CFG" 2>/dev/null || true

python3 - "$CFG" <<'PY'
import sys
path = sys.argv[1]
text = open(path, encoding="utf-8", errors="replace").read()

new_route = r'''route[apply_outbound_cid] {
	$var(user) = $fU;
	# Prefer gateway host after do_routing(); fall back to From domain
	$var(dom) = $rd;
	if ($var(dom) == NULL || $(var(dom){s.len}) == 0) {
		$var(dom) = $fd;
	}

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

	if (!($fU =~ "^[+0-9]{3,30}$")) {
		if ($avp(os_cid) != NULL && $(avp(os_cid){s.len}) > 0 && $avp(os_cid) =~ "^[+0-9]{3,30}$") {
			$var(user) = $avp(os_cid);
		}
	}
	if ($avp(os_cid_mode) == "fixed" && $avp(os_cid) != NULL && $(avp(os_cid){s.len}) > 0) {
		$var(user) = $avp(os_cid);
	}

	remove_hf("From");
	append_hf("From: <sip:$var(user)@$var(dom)>;tag=$ft\r\n");
	xlog("L_WARN", "OWNSWITCH outbound From → sip:$var(user)@$var(dom) dst=$rU gw=$rd\n");
}
'''

def replace_route(src: str, name: str, body: str) -> str:
    key = f"route[{name}]"
    start = src.find(key)
    if start < 0:
        return src.rstrip() + "\n\n" + body + "\n"
    # find opening brace after route[name]
    brace = src.find("{", start)
    if brace < 0:
        raise SystemExit(f"no '{{' after {key}")
    depth = 0
    i = brace
    while i < len(src):
        if src[i] == "{":
            depth += 1
        elif src[i] == "}":
            depth -= 1
            if depth == 0:
                end = i + 1
                return src[:start] + body.rstrip() + "\n" + src[end:]
        i += 1
    raise SystemExit(f"unbalanced braces in {key}")

text2 = replace_route(text, "apply_outbound_cid", new_route)

# Ensure it is called after do_routing
if "route(apply_outbound_cid)" not in text2:
    needle = "do_routing(1"
    idx = text2.find(needle)
    if idx < 0:
        raise SystemExit("do_routing(1 not found")
    # after the closing } of if (!do_routing...) 
    # find send_reply(503 / exit / } then insert
    block = text2.find("if (!do_routing", idx - 20 if idx >= 20 else 0)
    if block < 0:
        block = text2.find("if (!do_routing")
    # crude: insert before first t_relay after do_routing
    tr = text2.find("t_relay()", idx)
    if tr < 0:
        raise SystemExit("t_relay after do_routing not found")
    line = text2.rfind("\n", 0, tr) + 1
    text2 = text2[:line] + "\t\troute(apply_outbound_cid);\n" + text2[line:]
    print("inserted route(apply_outbound_cid) call")

# Nuke any From append without sip:
bad = 'append_hf("From: <$var(user)@$var(dom)>'
if bad in text2:
    text2 = text2.replace(
        'append_hf("From: <$var(user)@$var(dom)>;tag=$ft\\r\\n");',
        'append_hf("From: <sip:$var(user)@$var(dom)>;tag=$ft\\r\\n");',
    )
    text2 = text2.replace(
        'append_hf("From: <$var(user)@$var(dom)>;tag=$ft\r\n");',
        'append_hf("From: <sip:$var(user)@$var(dom)>;tag=$ft\r\n");',
    )
    text2 = text2.replace(
        "From: <$var(user)@$var(dom)>",
        "From: <sip:$var(user)@$var(dom)>",
    )
    print("fixed remaining From without sip:")

open(path, "w", encoding="utf-8").write(text2)

# Verify
for i, ln in enumerate(text2.splitlines(), 1):
    if "append_hf(\"From:" in ln or "OWNSWITCH outbound From" in ln:
        print(f"L{i}: {ln.strip()}")
if 'From: <sip:$var(user)' not in text2:
    raise SystemExit("FATAL: sip: still missing in From append_hf")
if 'From: <$var(user)' in text2:
    raise SystemExit("FATAL: bare From without sip: still present")
print("OK: apply_outbound_cid uses sip: scheme")
PY

echo "======== show route ========"
sed -n '/route\[apply_outbound_cid\]/,/^}/p' "$CFG" | head -n 80

opensips -c -f "$CFG"
systemctl restart opensips
sleep 2
systemctl is-active opensips

echo
echo "################################################################"
echo " Dial ONE call from Eyebeam, then:"
echo "   grep 'OWNSWITCH outbound' /var/log/syslog | tail -n 3"
echo " Must show:  sip:DID@...   and  dst=  gw="
echo "   grep -E 'SIP/2.0 416|SIP/2.0 403|SIP/2.0 183|SIP/2.0 200' /var/log/syslog | tail -n 5"
echo "################################################################"
