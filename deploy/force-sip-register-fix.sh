#!/usr/bin/env bash
# MUST fix Domain REGISTER 483/500 and open REGISTER-without-auth on IP.
# Run on the VoIP server as root (copy-paste exactly):
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/force-sip-register-fix.sh | sudo bash
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

TEST_USER="${1:-}"
TEST_PASS="${2:-}"

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_ETC=/etc/ownswitch
ENV_FILE="${OWN_ETC}/ownswitch.env"
IC="${OWN_ETC}/install.conf"
CFG=/etc/opensips/opensips.cfg

cd "$SRC"
git fetch origin main
git reset --hard origin/main

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "$IC" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
DETECT_IP="$(curl -4 -s --max-time 5 ifconfig.me 2>/dev/null || true)"
LOCAL_IPS="$(hostname -I 2>/dev/null || true)"
PUBLIC_IP="$DETECT_IP"
if [[ -n "$DNS_IP" && " $LOCAL_IPS " == *" $DNS_IP "* ]]; then
  PUBLIC_IP="$DNS_IP"
elif [[ -z "$PUBLIC_IP" && -n "$DNS_IP" ]]; then
  PUBLIC_IP="$DNS_IP"
fi
[[ -n "$PUBLIC_IP" ]] || { echo "FATAL: no PUBLIC_IP"; exit 1; }
PRIV="$(echo "$LOCAL_IPS" | awk '{print $1}')"

echo "DOMAIN=$DOMAIN PUBLIC_IP=$PUBLIC_IP DNS=$DNS_IP LOCAL=$LOCAL_IPS"

# Persist
grep -vE '^(PRIVATE_IP|PUBLIC_IP|OWNSWITCH_DOMAIN)=' "$IC" > /tmp/ic.$$ 2>/dev/null || true
{ echo "OWNSWITCH_DOMAIN=${DOMAIN}"; echo "PUBLIC_IP=${PUBLIC_IP}"; echo "PRIVATE_IP=${PRIV}"; cat /tmp/ic.$$; } > "$IC"
rm -f /tmp/ic.$$
grep -q '^OPENSIPS_SIP_DOMAIN=' "$ENV_FILE" 2>/dev/null && sed -i "s/^OPENSIPS_SIP_DOMAIN=.*/OPENSIPS_SIP_DOMAIN=${DOMAIN}/" "$ENV_FILE" || echo "OPENSIPS_SIP_DOMAIN=${DOMAIN}" >>"$ENV_FILE"
grep -q '^SIP_PUBLIC_IP=' "$ENV_FILE" 2>/dev/null && sed -i "s/^SIP_PUBLIC_IP=.*/SIP_PUBLIC_IP=${PUBLIC_IP}/" "$ENV_FILE" || echo "SIP_PUBLIC_IP=${PUBLIC_IP}" >>"$ENV_FILE"

export OWNSWITCH_DOMAIN="$DOMAIN" PUBLIC_IP="$PUBLIC_IP" PRIVATE_IP="$PRIV" SIP_PORT="${SIP_PORT:-5060}"
bash "$SRC/deploy/fix-opensips-provision.sh" || true
bash "$SRC/deploy/install.sh" --only 50 --yes --non-interactive

# Hard assertions on rendered config
grep -q "alias=\"${DOMAIN}\"" "$CFG" || { echo "FATAL: missing alias $DOMAIN in $CFG"; exit 1; }
grep -q "alias=\"${PUBLIC_IP}\"" "$CFG" || { echo "FATAL: missing alias $PUBLIC_IP in $CFG"; exit 1; }
grep -q 'www_authorize' "$CFG" || { echo "FATAL: www_authorize missing"; exit 1; }
# REGISTER must appear before Max-Forwards processing
reg_line="$(grep -n 'is_method("REGISTER")' "$CFG" | head -1 | cut -d: -f1 || true)"
mf_line="$(grep -n 'mf_process_maxfwd_header' "$CFG" | head -1 | cut -d: -f1 || true)"
echo "REGISTER handler line=$reg_line  Max-Forwards line=$mf_line"
if [[ -n "$reg_line" && -n "$mf_line" && "$reg_line" -gt "$mf_line" ]]; then
  echo "FATAL: REGISTER handler is AFTER Max-Forwards — old cfg still live"
  exit 1
fi
grep -nE '^alias=|^socket=|calculate_ha1|www_authorize|rewritehost|is_method\("REGISTER"\)' "$CFG" | head -n 40

opensips -c -f "$CFG"
systemctl restart opensips
sleep 2
systemctl --no-pager -l status opensips | head -n 20 || true

probe() {
  local host="$1"
  PROBE_HOST="$host" python3 - <<'PY'
import os, socket
host = os.environ["PROBE_HOST"]
msg = (
  f"REGISTER sip:{host} SIP/2.0\r\n"
  f"Via: SIP/2.0/UDP 127.0.0.1:5099;branch=z9hG4bKforce;rport\r\n"
  f"Max-Forwards: 70\r\n"
  f"From: <sip:forceprobe@{host}>;tag=force\r\n"
  f"To: <sip:forceprobe@{host}>\r\n"
  f"Call-ID: force-{host}@127.0.0.1\r\n"
  f"CSeq: 1 REGISTER\r\n"
  f"Contact: <sip:forceprobe@127.0.0.1:5099>\r\n"
  f"Expires: 60\r\n"
  f"Content-Length: 0\r\n\r\n"
)
s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
s.settimeout(3)
s.sendto(msg.encode(), ("127.0.0.1", 5060))
try:
    data, _ = s.recvfrom(8192)
    line = data.decode(errors="replace").splitlines()[0]
except Exception as e:
    line = f"NO_RESPONSE {e}"
s.close()
print(line)
open("/tmp/sip_probe_code.txt","w").write(line)
PY
}

echo
echo "== Probes (BOTH must be 401 Unauthorized) =="
D_CODE="$(probe "$DOMAIN")"
I_CODE="$(probe "$PUBLIC_IP")"
echo "Domain $DOMAIN → $D_CODE"
echo "IP     $PUBLIC_IP → $I_CODE"

fail=0
[[ "$D_CODE" == SIP/2.0\ 401* || "$D_CODE" == SIP/2.0\ 407* ]] || fail=1
[[ "$I_CODE" == SIP/2.0\ 401* || "$I_CODE" == SIP/2.0\ 407* ]] || fail=1
if [[ "$fail" -eq 1 ]]; then
  echo
  echo "FATAL: OpenSIPS still not challenging REGISTER correctly."
  echo "journalctl:"
  journalctl -u opensips --no-pager -n 80 || true
  exit 1
fi

mysql --protocol=socket -uroot ownswitch -e "UPDATE sip_accounts SET domain='${DOMAIN}';" 2>/dev/null || true
bash "$SRC/deploy/refresh-ui.sh" || true
CUR="$(readlink -f /opt/ownswitch/current)"
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/diagnose-sip-portal.php" || true

command -v ufw >/dev/null && { ufw allow 5060/udp || true; ufw allow 5060/tcp || true; ufw allow 30000:40000/udp || true; }

if [[ -n "$TEST_USER" && -n "$TEST_PASS" ]]; then
  runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/test-sip-register.php" "$TEST_USER" "$TEST_PASS" || true
fi

echo
echo "################################################################"
echo " Prefer the Magnus-style fixer (plaintext auth, no Proxy):"
echo "   sudo bash $SRC/deploy/fix-softphone-register-magnus.sh"
echo " Softphone: User+Pass  Domain=${PUBLIC_IP} OR ${DOMAIN}  Proxy=BLANK  Port=5060 UDP"
echo "################################################################"
