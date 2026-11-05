#!/usr/bin/env bash
# Fix Eyebeam REGISTER 408 (no SIP reply) / FQDN issues.
# Run as root AFTER rebuild-sip-register-from-scratch.sh succeeded (or this will call it).
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-eyebeam-408.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
cd "$SRC"
git fetch origin main
git reset --hard origin/main

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' /etc/ownswitch/install.conf 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
PUBLIC_IP="$(curl -4 -s --max-time 5 ifconfig.me || true)"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
LOCAL="$(hostname -I || true)"
if [[ -n "$DNS_IP" && " $LOCAL " == *" $DNS_IP "* ]]; then PUBLIC_IP="$DNS_IP"; fi
[[ -n "$PUBLIC_IP" ]] || PUBLIC_IP="$DNS_IP"

echo "======== 1) DNS / process ========"
echo "DOMAIN=$DOMAIN DNS_A=$DNS_IP PUBLIC_IP=$PUBLIC_IP"
if [[ -n "$DNS_IP" && "$DNS_IP" != "$PUBLIC_IP" ]]; then
  echo "WARN: $DOMAIN resolves to $DNS_IP but server public IP is $PUBLIC_IP — Eyebeam FQDN will hit the WRONG host (408)."
fi
systemctl is-active opensips || {
  echo "OpenSIPS not running — rebuilding first..."
  bash "$SRC/deploy/rebuild-sip-register-from-scratch.sh"
}
ss -ulnp | grep 5060 || { echo "FATAL: nothing on UDP 5060"; exit 1; }

echo "======== 2) Live cfg must challenge REGISTER ========"
if ! grep -q 'www_authorize' "$CFG" 2>/dev/null; then
  echo "No digest config — running full rebuild..."
  bash "$SRC/deploy/rebuild-sip-register-from-scratch.sh"
fi
# Unlock, strengthen NAT handling for Eyebeam behind NAT
chattr -i "$CFG" 2>/dev/null || true

python3 - "$CFG" "$PUBLIC_IP" <<'PY'
import re, sys
path, public_ip = sys.argv[1], sys.argv[2]
text = open(path, encoding="utf-8", errors="replace").read()

# Ensure nathelper loaded
if 'loadmodule "nathelper.so"' not in text:
    text = text.replace(
        'loadmodule "registrar.so"',
        'loadmodule "registrar.so"\nloadmodule "nathelper.so"\nmodparam("nathelper", "received_avp", "$avp(received)")',
        1,
    )

# Replace REGISTER block NAT section: ALWAYS force_rport + fix_nated_register (Eyebeam NAT)
# Match from is_method("REGISTER") through save("location")
pat = re.compile(
    r'(if \(is_method\("REGISTER"\)\) \{)(.*?)(if \(!save\("location"\)\))',
    re.S,
)

def repl(m):
    head, body, save = m.group(1), m.group(2), m.group(3)
    # keep rewritehost + auth, replace middle with aggressive NAT
    if 'www_authorize' not in body:
        return m.group(0)
    # rebuild body up to save
    new_body = f'''
		sethost("{public_ip}");
		xlog("L_INFO", "REG $si:$sp $tU@$td\\n");
		if (!www_authorize("$td", "subscriber")) {{
			www_challenge("$td", "auth");
			exit;
		}}
		# Eyebeam / NAT: always symmetric signalling
		force_rport();
		fix_nated_register();
		'''
    return head + new_body + save

text2, n = pat.subn(repl, text, count=1)
if n != 1:
    print("WARN: could not rewrite REGISTER NAT block; leaving cfg")
else:
    text = text2
    open(path, "w", encoding="utf-8").write(text)
    print("Updated REGISTER NAT handling (force_rport + fix_nated_register always)")
PY

opensips -c -f "$CFG"
systemctl restart opensips
sleep 2
chattr +i "$CFG" 2>/dev/null || true

echo "======== 3) Firewall (server) ========"
if command -v ufw >/dev/null; then
  ufw allow 5060/udp comment 'SIP UDP' || true
  ufw allow 5060/tcp comment 'SIP TCP' || true
  ufw allow 30000:40000/udp comment 'RTP' || true
  ufw status | head -n 40 || true
fi
iptables -I INPUT -p udp --dport 5060 -j ACCEPT 2>/dev/null || true
iptables -I INPUT -p tcp --dport 5060 -j ACCEPT 2>/dev/null || true

echo "======== 4) Local digest still OK? ========"
python3 - "$DOMAIN" "$PUBLIC_IP" <<'PY'
import socket, sys
domain, ip = sys.argv[1], sys.argv[2]

def probe(host):
    msg = (
      f"REGISTER sip:{host} SIP/2.0\r\n"
      f"Via: SIP/2.0/UDP 127.0.0.1:5099;branch=z9hG4bKe;rport\r\n"
      f"Max-Forwards: 70\r\n"
      f"From: <sip:e@{host}>;tag=t\r\n"
      f"To: <sip:e@{host}>\r\n"
      f"Call-ID: e-{host}@127.0.0.1\r\n"
      f"CSeq: 1 REGISTER\r\n"
      f"Contact: <sip:e@127.0.0.1:5099>\r\n"
      f"Expires: 60\r\n"
      f"Content-Length: 0\r\n\r\n"
    )
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.settimeout(3)
    s.sendto(msg.encode(), ("127.0.0.1", 5060))
    try:
        return s.recvfrom(8192)[0].decode(errors="replace").splitlines()[0]
    except Exception as e:
        return f"NO_RESPONSE {e}"
    finally:
        s.close()

for h in (domain, ip):
    print(h, "→", probe(h))
PY

echo "======== 5) Watch for Eyebeam REGISTER (15s) — register NOW from Eyebeam ========"
echo "Eyebeam settings (use IP first to avoid DNS/408):"
echo "  Display name : owner"
echo "  User name    : owner"
echo "  Password     : (portal SIP password)"
echo "  Domain       : ${PUBLIC_IP}"
echo "  Proxy address: (leave EMPTY)"
echo "  Port         : 5060"
echo "  Protocol     : UDP"
echo
timeout 15 journalctl -u opensips -f --no-pager 2>/dev/null | grep --line-buffered -E 'REG |REGISTER|auth' || true

echo
echo "If journal showed REG from your public IP → OpenSIPS got it (not a 408 path issue)."
echo "If journal silent while Eyebeam shows 408 → Hetzner Cloud Firewall or client path blocks UDP 5060."
echo "  Hetzner console → Firewalls → allow UDP/TCP 5060 and UDP 30000-40000 inbound."
echo
echo "FQDN: only after IP works. Domain=${DOMAIN} must A-record to ${PUBLIC_IP} (now DNS=${DNS_IP})."
echo "Done."
