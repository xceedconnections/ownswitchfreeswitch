#!/usr/bin/env bash
# Make SIP UDP/5060 reachable for softphones (default SIP transport).
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-udp-5060.sh | sudo bash
#
# Optional (opens Hetzner Cloud Firewall from the API):
#   echo 'YOUR_TOKEN' | sudo tee /etc/ownswitch/hcloud.token
#   curl -fsSL .../fix-udp-5060.sh | sudo bash
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_ETC=/etc/ownswitch
SIP_PORT="${SIP_PORT:-5060}"
RTP_FROM="${RTP_FROM:-30000}"
RTP_TO="${RTP_TO:-40000}"

cd "$SRC"
git fetch origin main >/dev/null 2>&1 || true
git reset --hard origin/main >/dev/null 2>&1 || true

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "${OWN_ETC}/install.conf" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
PUB="$(curl -4 -s --max-time 5 ifconfig.me 2>/dev/null || curl -4 -s --max-time 5 icanhazip.com 2>/dev/null || true)"
LOCAL_IPS=" $(hostname -I) "
SIP_IP="$PUB"
if [[ -n "$DNS_IP" && "$LOCAL_IPS" == *" $DNS_IP "* ]]; then SIP_IP="$DNS_IP"; fi
SIP_IP="${SIP_IP:-$DNS_IP}"
PRIV="$(hostname -I | awk '{print $1}')"

echo "======== fix-udp-5060 ========"
echo "Domain=$DOMAIN  PUBLIC=$SIP_IP  PRIVATE=$PRIV"

# --- Persist PUBLIC_IP ---
if [[ -f "${OWN_ETC}/install.conf" && -n "$SIP_IP" ]]; then
  grep -vE '^(PUBLIC_IP|PRIVATE_IP|SIP_PORT)=' "${OWN_ETC}/install.conf" > /tmp/ic.$$ || true
  {
    echo "PRIVATE_IP=${PRIV}"
    echo "PUBLIC_IP=${SIP_IP}"
    echo "SIP_PORT=${SIP_PORT}"
  } >> /tmp/ic.$$
  mv /tmp/ic.$$ "${OWN_ETC}/install.conf"
fi

export DEBIAN_FRONTEND=noninteractive
apt-get install -y opensips 2>/dev/null || true

# Re-render OpenSIPS (0.0.0.0 + advertise public)
bash "$SRC/deploy/install.sh" --only 50 --yes --non-interactive || true

# Force correct sockets in live cfg
if [[ -f /etc/opensips/opensips.cfg && -n "$SIP_IP" ]]; then
  SIP_PORT="$SIP_PORT" SIP_IP="$SIP_IP" python3 - <<'PY'
import os, re
from pathlib import Path
p = Path("/etc/opensips/opensips.cfg")
text = p.read_text()
port = os.environ["SIP_PORT"]
ip = os.environ["SIP_IP"]
udp = f"socket=udp:0.0.0.0:{port} as {ip}:{port}"
tcp = f"socket=tcp:0.0.0.0:{port} as {ip}:{port}"
# remove existing socket lines in global section (before first loadmodule)
parts = text.split("loadmodule", 1)
head, tail = parts[0], ("loadmodule" + parts[1] if len(parts) > 1 else "")
head = re.sub(r"(?m)^socket=.*\n?", "", head)
if "####### Modules #########" in head:
    head = head.replace("####### Modules #########", f"{udp}\n{tcp}\n\n####### Modules #########", 1)
else:
    head = head.rstrip() + f"\n{udp}\n{tcp}\n\n"
text = head + tail
if 'loadmodule "proto_udp.so"' not in text:
    text = text.replace('loadmodule "db_mysql.so"', 'loadmodule "db_mysql.so"\nloadmodule "proto_udp.so"', 1)
if 'loadmodule "proto_tcp.so"' not in text:
    text = text.replace('loadmodule "proto_udp.so"', 'loadmodule "proto_udp.so"\nloadmodule "proto_tcp.so"', 1)
p.write_text(text)
print(udp)
print(tcp)
PY
  if ! opensips -c -f /etc/opensips/opensips.cfg >/tmp/os-c.out 2>&1; then
    echo "WARN: opensips -c failed after socket patch; re-running stage 50"
    cat /tmp/os-c.out || true
    bash "$SRC/deploy/install.sh" --only 50 --yes --non-interactive || true
  else
    echo "opensips.cfg syntax OK"
  fi
fi

# --- Host firewall ---
if command -v ufw >/dev/null 2>&1; then
  ufw allow "${SIP_PORT}/udp" comment 'Ownswitch SIP UDP' || true
  ufw allow "${SIP_PORT}/tcp" comment 'Ownswitch SIP TCP' || true
  ufw allow "${RTP_FROM}:${RTP_TO}/udp" comment 'Ownswitch RTP' || true
  ufw status verbose 2>/dev/null | head -40 || true
fi

iptables -C INPUT -p udp --dport "$SIP_PORT" -j ACCEPT 2>/dev/null || iptables -I INPUT 1 -p udp --dport "$SIP_PORT" -j ACCEPT || true
iptables -C INPUT -p tcp --dport "$SIP_PORT" -j ACCEPT 2>/dev/null || iptables -I INPUT 1 -p tcp --dport "$SIP_PORT" -j ACCEPT || true
iptables -C INPUT -p udp --dport "${RTP_FROM}:${RTP_TO}" -j ACCEPT 2>/dev/null || iptables -I INPUT 1 -p udp --dport "${RTP_FROM}:${RTP_TO}" -j ACCEPT || true

if command -v nft >/dev/null 2>&1 && nft list tables 2>/dev/null | grep -q 'inet filter'; then
  nft list chain inet filter input 2>/dev/null | grep -q "udp dport ${SIP_PORT} accept" \
    || nft insert rule inet filter input udp dport "${SIP_PORT}" accept 2>/dev/null || true
  nft list chain inet filter input 2>/dev/null | grep -q "tcp dport ${SIP_PORT} accept" \
    || nft insert rule inet filter input tcp dport "${SIP_PORT}" accept 2>/dev/null || true
  nft insert rule inet filter input udp dport "${RTP_FROM}-${RTP_TO}" accept 2>/dev/null || true
fi

sysctl -w net.ipv4.conf.all.rp_filter=0 >/dev/null 2>&1 || true
sysctl -w net.ipv4.conf.default.rp_filter=0 >/dev/null 2>&1 || true
mkdir -p /etc/sysctl.d
cat >/etc/sysctl.d/99-ownswitch-sip.conf <<EOF
net.ipv4.conf.all.rp_filter=0
net.ipv4.conf.default.rp_filter=0
EOF

if systemctl is-active --quiet fail2ban 2>/dev/null; then
  for j in $(fail2ban-client status 2>/dev/null | sed -n 's/.*Jail list:\s*//p' | tr ',' ' '); do
    fail2ban-client set "$j" unbanip --all 2>/dev/null || true
  done
fi

# --- Hetzner Cloud Firewall API ---
TOKEN="${HCLOUD_TOKEN:-}"
[[ -z "$TOKEN" && -f "${OWN_ETC}/hcloud.token" ]] && TOKEN="$(tr -d ' \r\n' < "${OWN_ETC}/hcloud.token")"
[[ -z "$TOKEN" && -f "${OWN_ETC}/ownswitch.env" ]] && TOKEN="$(grep -E '^HCLOUD_TOKEN=' "${OWN_ETC}/ownswitch.env" 2>/dev/null | cut -d= -f2- | tr -d '"' || true)"

if [[ -n "${TOKEN:-}" ]]; then
  echo "======== Hetzner Cloud Firewall API ========"
  META_ID="$(curl -s --max-time 2 http://169.254.169.254/hetzner/v1/metadata/instance-id 2>/dev/null || true)"
  if TOKEN="$TOKEN" SIP_IP="$SIP_IP" META_ID="$META_ID" SIP_PORT="$SIP_PORT" RTP_FROM="$RTP_FROM" RTP_TO="$RTP_TO" python3 - <<'PY'
import json, os, urllib.error, urllib.request

token = os.environ["TOKEN"]
sip = os.environ["SIP_PORT"]
rtp_a, rtp_b = os.environ["RTP_FROM"], os.environ["RTP_TO"]
pub = os.environ.get("SIP_IP", "")
meta = os.environ.get("META_ID", "")

def api(method, path, body=None):
    data = None if body is None else json.dumps(body).encode()
    req = urllib.request.Request(
        "https://api.hetzner.cloud/v1" + path,
        data=data,
        method=method,
        headers={"Authorization": "Bearer " + token, "Content-Type": "application/json"},
    )
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.load(r)

try:
    servers = api("GET", "/servers").get("servers") or []
except urllib.error.HTTPError as e:
    print("API error listing servers:", e)
    raise SystemExit(1)

server_id = None
for s in servers:
    ips = []
    pn = s.get("public_net") or {}
    for k in ("ipv4", "ipv6"):
        o = pn.get(k) or {}
        if isinstance(o, dict) and o.get("ip"):
            ips.append(o["ip"])
    for n in s.get("private_net") or []:
        if n.get("ip"):
            ips.append(n["ip"])
    if meta and str(s.get("id")) == str(meta):
        server_id = s["id"]
        break
    if pub and pub in ips:
        server_id = s["id"]
        break

if not server_id:
    print("Server not found for IP", pub)
    raise SystemExit(1)
print("server id=", server_id)

srv = api("GET", f"/servers/{server_id}")["server"]
fw_ids = set()
for fw in (srv.get("public_net") or {}).get("firewalls") or []:
    if isinstance(fw, dict) and fw.get("id") is not None:
        fw_ids.add(int(fw["id"]))
for fw in api("GET", "/firewalls").get("firewalls") or []:
    for t in fw.get("applied_to") or []:
        if t.get("type") == "server" and (t.get("server") or {}).get("id") == server_id:
            fw_ids.add(int(fw["id"]))

print("attached firewalls:", sorted(fw_ids))
if not fw_ids:
    print("No Cloud Firewall attached.")
    raise SystemExit(0)

needed = [
    ("in", "udp", sip, ["0.0.0.0/0", "::/0"], "Ownswitch SIP UDP"),
    ("in", "tcp", sip, ["0.0.0.0/0", "::/0"], "Ownswitch SIP TCP"),
    ("in", "udp", f"{rtp_a}-{rtp_b}", ["0.0.0.0/0", "::/0"], "Ownswitch RTP"),
]

for fw_id in sorted(fw_ids):
    fw = api("GET", f"/firewalls/{fw_id}")["firewall"]
    rules = list(fw.get("rules") or [])
    changed = False
    for direction, proto, port, src, desc in needed:
        if any(r.get("direction") == direction and r.get("protocol") == proto and str(r.get("port")) == str(port) for r in rules):
            continue
        rules.append({
            "direction": direction,
            "protocol": proto,
            "port": str(port),
            "source_ips": src,
            "description": desc,
        })
        changed = True
        print(f"firewall {fw_id}: + {proto}/{port}")
    if changed:
        api("POST", f"/firewalls/{fw_id}/actions/set_rules", {"rules": rules})
        print(f"firewall {fw_id}: applied")
    else:
        print(f"firewall {fw_id}: already has SIP/RTP rules")
PY
  then
    echo "Hetzner Cloud Firewall updated (or none attached)."
  else
    echo "WARN: Hetzner API update failed — check token (Read & Write on Firewalls)."
  fi
else
  echo
  echo "NOTE: No HCLOUD_TOKEN found. If Cloud Firewall is attached, UDP may stay blocked"
  echo "      even when ufw is inactive. Fix:"
  echo "        echo 'TOKEN' | sudo tee /etc/ownswitch/hcloud.token && re-run this script"
  echo "      OR Hetzner Console → Firewalls → this server → allow inbound:"
  echo "        UDP ${SIP_PORT}, TCP ${SIP_PORT}, UDP ${RTP_FROM}-${RTP_TO}"
  echo "      OR remove the firewall from the server."
fi

# --- Restart + verify ---
systemctl enable opensips >/dev/null 2>&1 || true
systemctl restart opensips
sleep 2
systemctl is-active opensips || { journalctl -u opensips -n 40 --no-pager; exit 1; }

echo
echo "======== LISTEN ========"
ss -ulnp | grep -E ":${SIP_PORT}\\b" || { echo "FAIL: no UDP ${SIP_PORT}"; journalctl -u opensips -n 50 --no-pager; exit 1; }
ss -tlnp | grep -E ":${SIP_PORT}\\b" || true
grep -E '^socket=' /etc/opensips/opensips.cfg || true

rm -f /tmp/ownswitch-udp-local.ok
python3 - <<PY
import socket
msg = (
    "OPTIONS sip:127.0.0.1 SIP/2.0\r\n"
    "Via: SIP/2.0/UDP 127.0.0.1:5099;branch=z9hG4bKfixudp\r\n"
    "From: <sip:fix@local>;tag=u1\r\n"
    "To: <sip:fix@local>\r\n"
    "Call-ID: fix-udp-local@127.0.0.1\r\n"
    "CSeq: 1 OPTIONS\r\n"
    "Max-Forwards: 70\r\n"
    "Content-Length: 0\r\n\r\n"
).encode()
s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
s.settimeout(2.5)
try:
    s.sendto(msg, ("127.0.0.1", int("${SIP_PORT}")))
    data, _ = s.recvfrom(4096)
    print("LOCAL UDP OK:", data.decode(errors="replace").split("\r\n", 1)[0])
    open("/tmp/ownswitch-udp-local.ok", "w").write("ok")
except Exception as e:
    print("LOCAL UDP FAIL:", e)
finally:
    s.close()
PY

echo
echo "======== RESULT ========"
if [[ -f /tmp/ownswitch-udp-local.ok ]]; then
  echo "OpenSIPS answers UDP locally on :${SIP_PORT}."
  echo "Softphone: Domain=${DOMAIN}  IP=${SIP_IP}  Port=${SIP_PORT}  Transport=UDP"
  echo "If internet softphones still fail: Cloud Firewall / ACL — use HCLOUD_TOKEN path above."
else
  echo "OpenSIPS did not answer local UDP — journalctl -u opensips -n 100"
  exit 1
fi
echo "==============================="
