#!/usr/bin/env bash
# Enable OpenSIPS provisioning + diagnose/fix softphone REGISTER and trunk sync.
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-sip-register.sh | sudo bash
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_ETC="${OWN_ETC:-/etc/ownswitch}"
ENV_FILE="${OWN_ETC}/ownswitch.env"
cd "$SRC"
git fetch origin main && git reset --hard origin/main

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "${OWN_ETC}/install.conf" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
PUBLIC_IP="$(curl -4 -s --max-time 5 ifconfig.me 2>/dev/null || curl -4 -s --max-time 5 icanhazip.com 2>/dev/null || hostname -I | awk '{print $1}')"

echo "== Patch ${ENV_FILE} =="
touch "$ENV_FILE"
grep -q '^OPENSIPS_ENABLED=' "$ENV_FILE" && sed -i 's/^OPENSIPS_ENABLED=.*/OPENSIPS_ENABLED=1/' "$ENV_FILE" || echo 'OPENSIPS_ENABLED=1' >>"$ENV_FILE"
if grep -q '^OPENSIPS_SIP_DOMAIN=' "$ENV_FILE"; then
  sed -i "s/^OPENSIPS_SIP_DOMAIN=.*/OPENSIPS_SIP_DOMAIN=${DOMAIN}/" "$ENV_FILE"
else
  echo "OPENSIPS_SIP_DOMAIN=${DOMAIN}" >>"$ENV_FILE"
fi
# MI URL alias expected by PHP
if grep -q '^OPENSIPS_MI_HTTP_URL=' "$ENV_FILE"; then
  MI="$(grep '^OPENSIPS_MI_HTTP_URL=' "$ENV_FILE" | head -n1 | cut -d= -f2-)"
else
  MI="http://127.0.0.1:8888/mi"
  echo "OPENSIPS_MI_HTTP_URL=${MI}" >>"$ENV_FILE"
fi
grep -q '^OPENSIPS_MI_URL=' "$ENV_FILE" && sed -i "s|^OPENSIPS_MI_URL=.*|OPENSIPS_MI_URL=${MI}|" "$ENV_FILE" || echo "OPENSIPS_MI_URL=${MI}" >>"$ENV_FILE"
chmod 0640 "$ENV_FILE"
chown root:ownswitch "$ENV_FILE"

echo "== Deploy portal code =="
bash "$SRC/deploy/refresh-ui.sh"

# Ensure OpenSIPS is running and listening
systemctl enable --now opensips 2>/dev/null || true
systemctl restart opensips || true
sleep 1

echo "== OpenSIPS status =="
systemctl is-active opensips || true
ss -ulnp | grep -E ':5060|:5061' || ss -ulnp | head -n 20 || true
ss -ltnp | grep 8888 || true

echo "== Firewall (UFW) =="
if command -v ufw >/dev/null; then
  ufw allow 5060/udp || true
  ufw allow 5060/tcp || true
  ufw allow 5061/tcp || true
  ufw status | head -n 30 || true
fi

CUR=$(readlink -f /opt/ownswitch/current)
echo "== Migrate + sync =="
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/migrate.php" || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-trunks.php" || true

echo "== opensips.subscriber rows =="
mysql --protocol=socket -uroot opensips -e "SELECT id,username,domain,LEFT(ha1,8) AS ha1_prefix FROM subscriber LIMIT 20;" || true

echo "== ownswitch sip_accounts =="
mysql --protocol=socket -uroot ownswitch -e "SELECT id,username,domain,status FROM sip_accounts LIMIT 20;" || true

echo
echo "============================================================"
echo " Softphone REGISTER settings"
echo "============================================================"
echo "  Username : (SIP user from portal, e.g. 1001)"
echo "  Password : (set/reset in portal — shown once on create)"
echo "  Domain   : ${DOMAIN}"
echo "  Server   : ${PUBLIC_IP}   (or ${DOMAIN} if DNS A record points here)"
echo "  Port     : 5060"
echo "  Transport: UDP"
echo
echo "IMPORTANT: Edit each SIP user in the portal and set a NEW password,"
echo "then save — this rewrites HA1 for realm ${DOMAIN} and pushes to OpenSIPS."
echo
echo "Carrier / Provider trunk:"
echo "  IP peer mode → vendor allowlists ${PUBLIC_IP} (will NOT show Registered)."
echo "  Register mode → trunk Auth mode=Register + user/pass, needs uac_registrant."
echo "============================================================"
