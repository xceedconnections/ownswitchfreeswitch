#!/usr/bin/env bash
# Make softphone REGISTER work: use_domain=0, repair OpenSIPS, optional live REGISTER test.
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitch/main/deploy/fix-softphone-now.sh | sudo bash
#
# Optional live test after you set a password in the portal:
#   sudo bash /opt/ownswitch-src/deploy/fix-softphone-now.sh myuser 'MyPassword123!'
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

TEST_USER="${1:-}"
TEST_PASS="${2:-}"

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_ETC=/etc/ownswitch
ENV_FILE="${OWN_ETC}/ownswitch.env"
cd "$SRC"
git fetch origin main && git reset --hard origin/main

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "${OWN_ETC}/install.conf" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
PUB="$(curl -4 -s --max-time 5 ifconfig.me 2>/dev/null || true)"
SIP_IP="${DNS_IP:-$PUB}"

bash "$SRC/deploy/fix-opensips-provision.sh"
bash "$SRC/deploy/fix-drouting-schema.sh"

# Re-render OpenSIPS with use_domain=0 + NAT helper
bash "$SRC/deploy/install.sh" --only 50 --yes --non-interactive || true
systemctl restart opensips
sleep 1

# UFW
command -v ufw >/dev/null && {
  ufw allow 5060/udp || true
  ufw allow 5060/tcp || true
  ufw allow 30000:40000/udp || true
}

bash "$SRC/deploy/refresh-ui.sh"
CUR=$(readlink -f /opt/ownswitch/current)

# Align portal SIP domains to realm
mysql --protocol=socket -uroot ownswitch -e "UPDATE sip_accounts SET domain='${DOMAIN}';" 2>/dev/null || true

echo "== OpenSIPS listening =="
ss -ulnp | grep ':5060' || echo "FAIL: no UDP 5060 listener"
ss -tlnp | grep ':5060' || echo "WARN: no TCP 5060 listener"
systemctl is-active opensips

echo "== Config check (use_domain) =="
grep -n 'use_domain' /etc/opensips/opensips.cfg || true
grep -n '^socket=' /etc/opensips/opensips.cfg || true

echo "== subscribers =="
mysql --protocol=socket -uroot opensips -e "SELECT id,username,domain,LEFT(ha1,12) ha1 FROM subscriber;" || true

if [[ -n "$TEST_USER" && -n "$TEST_PASS" ]]; then
  echo "== Live REGISTER test as ${TEST_USER} =="
  runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" \
    php "$CUR/bin/test-sip-register.php" "$TEST_USER" "$TEST_PASS" || \
    env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/test-sip-register.php" "$TEST_USER" "$TEST_PASS" || true
  mysql --protocol=socket -uroot opensips -e "SELECT username,domain,contact,expires FROM location;" || true
fi

echo
echo "################################################################"
echo " SOFTPHONE SETTINGS (copy exactly)"
echo "################################################################"
echo " Username / Auth ID : (SIP user from portal)"
echo " Password           : reset in portal AFTER this script, then Save"
echo " Domain             : ${DOMAIN}     OR  ${SIP_IP}"
echo " Registrar / Proxy  : ${SIP_IP}"
echo " Port               : 5060"
echo " Transport          : UDP  — if timeout, set TCP"
echo
echo " Hetzner Cloud Firewall MUST allow: UDP/TCP 5060 + UDP 30000-40000"
echo
echo " After password reset, test on server:"
echo "   sudo bash $SRC/deploy/fix-softphone-now.sh USER 'PASSWORD'"
echo
echo " CARRIER → vendor Magnus:"
echo "   Tell vendor to IP-allowlist: ${SIP_IP}"
echo "   They will NOT show 'Registered' for IP trunks. That is normal."
echo "################################################################"
