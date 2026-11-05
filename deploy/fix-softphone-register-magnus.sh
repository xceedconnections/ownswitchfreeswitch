#!/usr/bin/env bash
# MagnusBilling-style softphone REGISTER on OpenSIPS:
#   username + password + Domain=IP OR any FQDN pointed at this server
#   NO Outbound Proxy required
#   REGISTER WITHOUT digest must get 401 (never open 200)
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-softphone-register-magnus.sh | sudo bash
# Optional:  sudo bash .../fix-softphone-register-magnus.sh <sip_user> <sip_pass>
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

echo "== Who owns UDP/TCP 5060 (before) =="
ss -ulnp 'sport = :5060' 2>/dev/null || ss -ulnp | grep 5060 || true
ss -tlnp 'sport = :5060' 2>/dev/null || true
systemctl show opensips -p FragmentPath -p ExecStart -p MainPID -p ActiveState 2>/dev/null || true

grep -vE '^(PRIVATE_IP|PUBLIC_IP|OWNSWITCH_DOMAIN)=' "$IC" > /tmp/ic.$$ 2>/dev/null || true
{ echo "OWNSWITCH_DOMAIN=${DOMAIN}"; echo "PUBLIC_IP=${PUBLIC_IP}"; echo "PRIVATE_IP=${PRIV}"; cat /tmp/ic.$$; } > "$IC"
rm -f /tmp/ic.$$
grep -q '^OPENSIPS_SIP_DOMAIN=' "$ENV_FILE" 2>/dev/null && sed -i "s/^OPENSIPS_SIP_DOMAIN=.*/OPENSIPS_SIP_DOMAIN=${DOMAIN}/" "$ENV_FILE" || echo "OPENSIPS_SIP_DOMAIN=${DOMAIN}" >>"$ENV_FILE"
grep -q '^SIP_PUBLIC_IP=' "$ENV_FILE" 2>/dev/null && sed -i "s/^SIP_PUBLIC_IP=.*/SIP_PUBLIC_IP=${PUBLIC_IP}/" "$ENV_FILE" || echo "SIP_PUBLIC_IP=${PUBLIC_IP}" >>"$ENV_FILE"
grep -q '^OPENSIPS_ENABLED=' "$ENV_FILE" 2>/dev/null && sed -i 's/^OPENSIPS_ENABLED=.*/OPENSIPS_ENABLED=1/' "$ENV_FILE" || echo 'OPENSIPS_ENABLED=1' >>"$ENV_FILE"

export OWNSWITCH_DOMAIN="$DOMAIN" PUBLIC_IP="$PUBLIC_IP" PRIVATE_IP="$PRIV" SIP_PORT="${SIP_PORT:-5060}"

# Ensure template in SRC has digest REGISTER (fail early if git pull stale)
grep -q 'www_authorize("$td"' "$SRC/deploy/templates/opensips.cfg.tpl" \
  || grep -q 'www_authorize(' "$SRC/deploy/templates/opensips.cfg.tpl" \
  || { echo "FATAL: SRC template missing www_authorize — git pull failed?"; exit 1; }
grep -qE 'calculate_ha1",[ ]*1' "$SRC/deploy/templates/opensips.cfg.tpl" \
  || { echo "FATAL: SRC template calculate_ha1!=1"; exit 1; }

bash "$SRC/deploy/fix-opensips-provision.sh" || true
bash "$SRC/deploy/install.sh" --only 50 --yes --non-interactive

# Hard assertions on LIVE cfg
[[ -f "$CFG" ]] || { echo "FATAL: missing $CFG"; exit 1; }
cp -a "$CFG" "/var/backups/ownswitch-config/opensips.cfg.pre-magnus.$(date -u +%Y%m%dT%H%M%SZ)" 2>/dev/null || true

grep -qE 'calculate_ha1",[ ]*1' "$CFG" \
  || { echo "FATAL: live $CFG calculate_ha1 is not 1"; grep -n calculate_ha1 "$CFG" || true; exit 1; }
grep -qE 'password_column",[ ]*"password"' "$CFG" \
  || { echo "FATAL: password_column must be password"; grep -n password_column "$CFG" || true; exit 1; }
grep -q 'www_authorize("$td"' "$CFG" || grep -q 'www_authorize(' "$CFG" \
  || { echo "FATAL: www_authorize missing in live cfg"; exit 1; }

reg_line="$(grep -n 'is_method("REGISTER")' "$CFG" | head -1 | cut -d: -f1 || true)"
mf_line="$(grep -n 'mf_process_maxfwd_header' "$CFG" | head -1 | cut -d: -f1 || true)"
echo "REGISTER handler line=$reg_line  Max-Forwards line=$mf_line"
if [[ -n "$reg_line" && -n "$mf_line" && "$reg_line" -gt "$mf_line" ]]; then
  echo "FATAL: REGISTER handler is AFTER Max-Forwards"
  exit 1
fi

echo "== Live REGISTER block =="
sed -n "${reg_line},$((reg_line + 35))p" "$CFG" 2>/dev/null || true

opensips -c -f "$CFG"

# Hard restart — drop stale workers that might still run old routes
systemctl stop opensips || true
sleep 1
fuser -k 5060/udp 2>/dev/null || true
fuser -k 5060/tcp 2>/dev/null || true
sleep 1
systemctl start opensips
sleep 2
systemctl --no-pager -l status opensips | head -n 25 || true

echo "== Who owns UDP 5060 (after) =="
ss -ulnp 'sport = :5060' 2>/dev/null || ss -ulnp | grep 5060 || true

CUR="$(readlink -f /opt/ownswitch/current)"
bash "$SRC/deploy/refresh-ui.sh" || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true

mysql --protocol=socket -uroot opensips -e \
  "SELECT username, domain, IF(password IS NULL OR password='', 'EMPTY', 'set') AS pwd FROM subscriber LIMIT 20;" \
  2>/dev/null || true

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
PY
}

echo
echo "== Probes (MUST be 401/407 — 200 without auth is FATAL) =="
D_CODE="$(probe "$DOMAIN")"
I_CODE="$(probe "$PUBLIC_IP")"
echo "Domain $DOMAIN → $D_CODE"
echo "IP     $PUBLIC_IP → $I_CODE"

fail=0
[[ "$D_CODE" == SIP/2.0\ 401* || "$D_CODE" == SIP/2.0\ 407* ]] || fail=1
[[ "$I_CODE" == SIP/2.0\ 401* || "$I_CODE" == SIP/2.0\ 407* ]] || fail=1
if [[ "$fail" -eq 1 ]]; then
  echo
  echo "FATAL: OpenSIPS still not digest-challenging REGISTER."
  echo "If you see 200 OK → wrong/old config still active or another daemon answers SIP."
  echo "--- ss ---"
  ss -ulnp | grep -E '5060|opensips' || true
  echo "--- ExecStart ---"
  systemctl cat opensips 2>/dev/null | head -n 40 || true
  echo "--- REGISTER in $CFG ---"
  grep -nE 'REGISTER|www_authorize|save\(|calculate_ha1' "$CFG" | head -n 40 || true
  echo "--- journal ---"
  journalctl -u opensips --no-pager -n 80 || true
  exit 1
fi

command -v ufw >/dev/null && { ufw allow 5060/udp || true; ufw allow 5060/tcp || true; ufw allow 30000:40000/udp || true; }

if [[ -n "$TEST_USER" && -n "$TEST_PASS" ]]; then
  if [[ "$TEST_USER" == "OWNER_USER" || "$TEST_PASS" == "OWNER_PASS" ]]; then
    echo "WARN: replace OWNER_USER/OWNER_PASS with the real portal SIP username and password"
  else
    runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/test-sip-register.php" "$TEST_USER" "$TEST_PASS" || true
  fi
fi

runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/diagnose-sip-portal.php" || true

echo
echo "################################################################"
echo " Digest REGISTER OK (401 challenge confirmed for Domain + IP)"
echo " Softphone (NO Proxy):"
echo "   User     = <portal SIP username>"
echo "   Password = <portal SIP password>"
echo "   Domain   = ${PUBLIC_IP}   OR   ${DOMAIN}   OR any FQDN → ${PUBLIC_IP}"
echo "   Port     = 5060 UDP"
echo "   Proxy    = leave BLANK"
echo " Then: Portal → SIP Users → set password → Save"
echo "################################################################"
