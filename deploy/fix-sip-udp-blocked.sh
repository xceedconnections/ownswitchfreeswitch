#!/usr/bin/env bash
# UDP 5060 is filtered/dead while TCP 5060 works → Eyebeam UDP REGISTER = 408.
# Run as root:
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-sip-udp-blocked.sh
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
PORT=5060

echo "======== Listeners BEFORE ========"
ss -ulnp | grep -E ":${PORT}\\b" || echo "NO UDP ${PORT}"
ss -tlnp | grep -E ":${PORT}\\b" || echo "NO TCP ${PORT}"
systemctl is-active opensips || echo "opensips inactive"

echo "======== Open UDP/TCP ${PORT} (ufw + iptables + nft) ========"
if command -v ufw >/dev/null; then
  ufw allow ${PORT}/udp || true
  ufw allow ${PORT}/tcp || true
  ufw allow 30000:40000/udp || true
  ufw status verbose | head -n 50 || true
fi
# iptables
iptables -C INPUT -p udp --dport ${PORT} -j ACCEPT 2>/dev/null || iptables -I INPUT 1 -p udp --dport ${PORT} -j ACCEPT
iptables -C INPUT -p tcp --dport ${PORT} -j ACCEPT 2>/dev/null || iptables -I INPUT 1 -p tcp --dport ${PORT} -j ACCEPT
iptables -C INPUT -p udp --dport ${PORT} -j ACCEPT 2>/dev/null || true
# remove DROP rules for 5060/udp if present
iptables -S INPUT | grep -E "dport ${PORT}" || true
# nftables
if command -v nft >/dev/null; then
  nft list ruleset 2>/dev/null | grep -E "5060|sip" | head -n 40 || true
fi

# Ensure OpenSIPS digest cfg exists
if [[ ! -f "$CFG" ]] || ! grep -q 'www_authorize' "$CFG"; then
  echo "Digest cfg missing — running rebuild..."
  bash "$SRC/deploy/rebuild-sip-register-from-scratch.sh"
fi

# Ensure BOTH udp and tcp sockets in cfg
chattr -i "$CFG" 2>/dev/null || true
if ! grep -qE "^socket=udp:" "$CFG"; then
  echo "FATAL: no udp socket in $CFG"
  grep -n '^socket=' "$CFG" || true
  exit 1
fi
if ! grep -qE "^socket=tcp:" "$CFG"; then
  # insert tcp socket after first udp socket
  sed -i "/^socket=udp:/a socket=tcp:0.0.0.0:${PORT} as ${PUBLIC_IP}:${PORT}" "$CFG"
fi
# Advertise public IP
sed -i -E "s|^socket=udp:0.0.0.0:${PORT} as [^:]+:${PORT}|socket=udp:0.0.0.0:${PORT} as ${PUBLIC_IP}:${PORT}|" "$CFG" || true
sed -i -E "s|^socket=tcp:0.0.0.0:${PORT} as [^:]+:${PORT}|socket=tcp:0.0.0.0:${PORT} as ${PUBLIC_IP}:${PORT}|" "$CFG" || true

# Aggressive NAT for Eyebeam
if grep -q 'nathelper.so' "$CFG" && ! grep -q 'fix_nated_register' "$CFG"; then
  sed -i 's/force_rport();/force_rport();\n\t\tfix_nated_register();/' "$CFG" || true
fi

opensips -c -f "$CFG"
systemctl enable opensips
systemctl restart opensips
sleep 2
chattr +i "$CFG" 2>/dev/null || true

echo "======== Listeners AFTER ========"
ss -ulnp | grep -E ":${PORT}\\b" || echo "NO UDP ${PORT} — OpenSIPS UDP broken"
ss -tlnp | grep -E ":${PORT}\\b" || echo "NO TCP ${PORT}"
systemctl --no-pager -l status opensips | head -n 20 || true

echo "======== Local UDP probe (must be 401) ========"
python3 - "$PUBLIC_IP" <<'PY'
import socket, sys
host = sys.argv[1]
msg = (
  f"REGISTER sip:{host} SIP/2.0\r\n"
  f"Via: SIP/2.0/UDP 127.0.0.1:5099;branch=z9hG4bKu;rport\r\n"
  f"Max-Forwards: 70\r\n"
  f"From: <sip:u@{host}>;tag=t\r\n"
  f"To: <sip:u@{host}>\r\n"
  f"Call-ID: u@{host}\r\n"
  f"CSeq: 1 REGISTER\r\n"
  f"Contact: <sip:u@127.0.0.1:5099>\r\n"
  f"Expires: 60\r\n"
  f"Content-Length: 0\r\n\r\n"
)
s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
s.settimeout(3)
s.sendto(msg.encode(), ("127.0.0.1", 5060))
try:
    print(s.recvfrom(8192)[0].decode(errors="replace").splitlines()[0])
except Exception as e:
    print("LOCAL_UDP_FAIL", e)
s.close()
PY

echo
echo "################################################################"
echo " External probe from this network timed out on UDP earlier."
echo " Eyebeam IMMEDIATE workaround (works while UDP is filtered):"
echo "   Domain       : ${PUBLIC_IP}"
echo "   Proxy        : (empty)"
echo "   Port         : 5060"
echo "   Protocol     : TCP     <<<< change from UDP"
echo "   User/Pass    : owner / portal password"
echo
echo " If TCP registers and UDP still 408 → host/network still drops UDP 5060."
echo " Check: iptables -L -n | grep 5060 ; nft list ruleset | grep 5060"
echo " Also Hetzner 'Firewalls' attached to the server (separate from 'not running')."
echo "################################################################"
