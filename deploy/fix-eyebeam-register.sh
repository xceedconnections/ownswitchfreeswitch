#!/usr/bin/env bash
# Repair Eyebeam / softphone REGISTER against Ownswitch OpenSIPS.
# Run ON the VoIP server:
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-eyebeam-register.sh | sudo bash
# Optional live test:
#   sudo bash /opt/ownswitch-src/deploy/fix-eyebeam-register.sh USER 'PasswordHere'
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

TEST_USER="${1:-}"
TEST_PASS="${2:-}"

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_ETC=/etc/ownswitch
ENV_FILE="${OWN_ETC}/ownswitch.env"
cd "$SRC"
git fetch origin main
git reset --hard origin/main

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "${OWN_ETC}/install.conf" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
PUB="$(curl -4 -s --max-time 5 ifconfig.me 2>/dev/null || true)"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
SIP_IP="${DNS_IP:-$PUB}"

echo "======== Eyebeam REGISTER repair ========"
echo "Domain=$DOMAIN  Public/SIP IP=$SIP_IP  DNS=$DNS_IP"

# Ensure env has SIP domain + public IP
bash "$SRC/deploy/fix-opensips-provision.sh" || true
if [[ -n "$SIP_IP" ]]; then
  grep -q '^SIP_PUBLIC_IP=' "$ENV_FILE" 2>/dev/null \
    && sed -i "s/^SIP_PUBLIC_IP=.*/SIP_PUBLIC_IP=${SIP_IP}/" "$ENV_FILE" \
    || echo "SIP_PUBLIC_IP=${SIP_IP}" >>"$ENV_FILE"
  grep -q '^OPENSIPS_SIP_DOMAIN=' "$ENV_FILE" 2>/dev/null \
    && sed -i "s/^OPENSIPS_SIP_DOMAIN=.*/OPENSIPS_SIP_DOMAIN=${DOMAIN}/" "$ENV_FILE" \
    || echo "OPENSIPS_SIP_DOMAIN=${DOMAIN}" >>"$ENV_FILE"
fi

# Re-render OpenSIPS (HA1 auth + sockets) and restart
bash "$SRC/deploy/install.sh" --only 50 --yes --non-interactive || true
systemctl restart opensips
sleep 2

# Align portal domains + push subscribers (HA1) into OpenSIPS
mysql --protocol=socket -uroot ownswitch -e "UPDATE sip_accounts SET domain='${DOMAIN}' WHERE domain IS NULL OR domain='';" 2>/dev/null || true

CUR="$(readlink -f /opt/ownswitch/current)"
bash "$SRC/deploy/refresh-ui.sh" || true
CUR="$(readlink -f /opt/ownswitch/current)"

# Sync all SIP users → opensips.subscriber (HA1)
if [[ -f "$CUR/bin/sync-opensips-sip-users.php" ]]; then
  runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || \
    env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true
fi

# Firewall
command -v ufw >/dev/null && {
  ufw allow 5060/udp || true
  ufw allow 5060/tcp || true
  ufw allow 30000:40000/udp || true
}

echo
echo "== OpenSIPS auth mode (must be calculate_ha1=0 / password_column=ha1) =="
grep -nE 'calculate_ha1|password_column|use_domain|www_authorize|socket=' /etc/opensips/opensips.cfg | head -n 40

echo
echo "== Listeners =="
ss -ulnp | grep ':5060' || echo "FAIL: no UDP 5060"
ss -tlnp | grep ':5060' || echo "WARN: no TCP 5060"

echo
echo "== subscribers (password column should be unused; ha1 must be 32 hex) =="
mysql --protocol=socket -uroot opensips -e \
  "SELECT id,username,domain,LEFT(ha1,12) ha1, CHAR_LENGTH(password) pass_len FROM subscriber;" 2>/dev/null || true

echo
echo "== Recent REGISTER log lines =="
journalctl -u opensips --no-pager -n 40 2>/dev/null | grep -iE 'REGISTER|auth failed|OK' | tail -n 20 || true

if [[ -n "$TEST_USER" && -n "$TEST_PASS" ]]; then
  echo
  echo "== Live REGISTER test user=$TEST_USER =="
  runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" \
    php "$CUR/bin/test-sip-register.php" "$TEST_USER" "$TEST_PASS" || \
    env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/test-sip-register.php" "$TEST_USER" "$TEST_PASS" || true
  mysql --protocol=socket -uroot opensips -e "SELECT username,domain,contact,expires FROM location;" 2>/dev/null || true
fi

echo
echo "################################################################"
echo " EYEBEAM / softphone — copy exactly"
echo "################################################################"
echo "  Display name     : (optional)"
echo "  User name        : <SIP user from portal>"
echo "  Password         : reset in portal AFTER this script, Save, then use it"
echo "  Domain           : ${DOMAIN}"
echo "  Auth name        : same as User name"
echo "  Registrar / Proxy: ${SIP_IP}"
echo "  Port             : 5060"
echo "  Protocol         : UDP   (if timeout, try TCP)"
echo
echo " Do NOT leave Proxy blank when Domain=${DOMAIN}."
echo " If Proxy is blank, Eyebeam may send SIP to Cloudflare/web IP and never hit OpenSIPS."
echo
echo " After password reset, verify on server:"
echo "   sudo bash $SRC/deploy/fix-eyebeam-register.sh USER 'PASSWORD'"
echo " Hetzner Cloud Firewall must allow UDP/TCP 5060 + UDP 30000-40000."
echo "################################################################"
