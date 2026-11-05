#!/usr/bin/env bash
# Prove where SIP breaks: local OpenSIPS vs path to the public internet.
#   sudo bash /opt/ownswitch-src/deploy/diagnose-sip-path.sh
# Or:  curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/diagnose-sip-path.sh | sudo bash
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' /etc/ownswitch/install.conf 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
PUB="$(curl -4 -s --max-time 5 ifconfig.me 2>/dev/null || true)"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"

echo "======== HOST ========"
echo "hostname=$(hostname)  pub=${PUB:-?}  dns(${DOMAIN})=${DNS_IP:-?}"
echo "opensips=$(systemctl is-active opensips 2>/dev/null || echo down)"
echo
echo "======== LISTEN 5060 ========"
ss -ulnp | grep -E ':5060\b' || echo "FAIL: OpenSIPS not listening UDP 5060"
ss -tlnp | grep -E ':5060\b' || echo "WARN: no TCP 5060"
echo
grep -E '^socket=' /etc/opensips/opensips.cfg 2>/dev/null || echo "no opensips.cfg sockets"
echo
echo "======== LOCAL FIREWALL ========"
if command -v ufw >/dev/null; then ufw status verbose 2>/dev/null | head -30 || true; else echo "ufw not installed"; fi
echo "--- iptables INPUT (first 25) ---"
iptables -L INPUT -n -v 2>/dev/null | head -25 || true
echo "--- nft (first 15) ---"
nft list ruleset 2>/dev/null | head -15 || echo "nft empty/unavailable"
echo
echo "======== OPENSIPS DB ========"
mysql --protocol=socket -uroot opensips -e "
SELECT COUNT(*) subscribers FROM subscriber;
SELECT username,domain FROM subscriber;
SELECT COUNT(*) registered_contacts FROM location;
SELECT username,contact,expires FROM location LIMIT 20;
SELECT COUNT(*) gateways FROM dr_gateways;
SELECT gwid,address FROM dr_gateways;
SELECT COUNT(*) registrants FROM uac_registrant;
SELECT l_uuid,l_username,r_domain,auth_proxy,expires FROM uac_registrant;
" 2>/dev/null || echo "opensips DB query failed"
echo
echo "======== LOCAL UDP SIP OPTIONS (must get SIP reply) ========"
python3 - <<'PY' || true
import socket, time
msg = (
    "OPTIONS sip:127.0.0.1 SIP/2.0\r\n"
    "Via: SIP/2.0/UDP 127.0.0.1:5099;branch=z9hG4bKdiag\r\n"
    "From: <sip:diag@local>;tag=diag1\r\n"
    "To: <sip:diag@local>\r\n"
    "Call-ID: diag-local@127.0.0.1\r\n"
    "CSeq: 1 OPTIONS\r\n"
    "Max-Forwards: 70\r\n"
    "Content-Length: 0\r\n\r\n"
).encode()
s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
s.settimeout(2.0)
try:
    s.sendto(msg, ("127.0.0.1", 5060))
    data, _ = s.recvfrom(4096)
    print("LOCAL UDP OK:", data.decode(errors="replace").split("\r\n", 1)[0])
except Exception as e:
    print("LOCAL UDP FAIL:", e)
finally:
    s.close()
PY

echo
echo "======== LOCAL TCP SIP OPTIONS ========"
python3 - <<'PY' || true
import socket
msg = (
    "OPTIONS sip:127.0.0.1 SIP/2.0\r\n"
    "Via: SIP/2.0/TCP 127.0.0.1:5099;branch=z9hG4bKdiagt\r\n"
    "From: <sip:diag@local>;tag=diag2\r\n"
    "To: <sip:diag@local>\r\n"
    "Call-ID: diag-local-tcp@127.0.0.1\r\n"
    "CSeq: 1 OPTIONS\r\n"
    "Max-Forwards: 70\r\n"
    "Content-Length: 0\r\n\r\n"
).encode()
s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
s.settimeout(2.0)
try:
    s.connect(("127.0.0.1", 5060))
    s.sendall(msg)
    data = s.recv(4096)
    print("LOCAL TCP OK:", data.decode(errors="replace").split("\r\n", 1)[0] if data else "(empty)")
except Exception as e:
    print("LOCAL TCP FAIL:", e)
finally:
    s.close()
PY

echo
echo "======== PUBLIC PATH (from this host toward PUB:5060 via external hairpin may fail) ========"
echo "From your PC we already measured: TCP 5060 OPEN, UDP 5060 TIMEOUT."
echo
echo "======== SOFTPHONE (use NOW) ========"
echo "  Transport = TCP"
echo "  Port      = 5060"
echo "  Domain    = ${DOMAIN}"
echo "  Proxy/IP  = ${PUB:-$DNS_IP}"
echo "  Reset SIP password in portal if unsure, then register."
echo
echo "======== VENDOR MAGNUS TRUNK ========"
echo "  IP peer: Magnus will NOT show Registered — only allowlist this server IP ${PUB:-?}."
echo "  User/pass: trunk Auth mode must be Register; uac_registrant rows above must be non-zero."
echo "  journalctl -u opensips -n 80 --no-pager | grep -iE 'regist|uac|auth'"
echo
echo "======== IF LOCAL UDP OK BUT INTERNET UDP DEAD ========"
echo "  Path block outside the OS (Hetzner Cloud Firewall attached to the server,"
echo "  or another network ACL). Detach Cloud Firewall OR allow inbound UDP 5060 + TCP 5060"
echo "  + UDP 30000-40000. Softphones can use TCP meanwhile."
echo "===================================="