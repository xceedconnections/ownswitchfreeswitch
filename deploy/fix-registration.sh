#!/usr/bin/env bash
# Master fix for SIP user REGISTER + carrier trunk (IP or Register-mode).
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-registration.sh | sudo bash
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_ETC=/etc/ownswitch
ENV_FILE="${OWN_ETC}/ownswitch.env"
cd "$SRC"
git fetch origin main && git reset --hard origin/main

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "${OWN_ETC}/install.conf" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
PUB="$(curl -4 -s --max-time 5 ifconfig.me 2>/dev/null || curl -4 -s --max-time 5 icanhazip.com 2>/dev/null || true)"
# Prefer DNS A if it is assigned on this host
LOCAL_IPS=" $(hostname -I) "
SIP_IP="$PUB"
if [[ -n "$DNS_IP" && "$LOCAL_IPS" == *" $DNS_IP "* ]]; then SIP_IP="$DNS_IP"; fi
SIP_IP="${SIP_IP:-$DNS_IP}"

echo "Domain=$DOMAIN  SIP_IP=$SIP_IP  DNS=$DNS_IP  detected=$PUB"

# Persist public IP for OpenSIPS advertise
if [[ -f "${OWN_ETC}/install.conf" && -n "$SIP_IP" ]]; then
  grep -vE '^(PUBLIC_IP|PRIVATE_IP)=' "${OWN_ETC}/install.conf" > /tmp/ic.$$ || true
  PRIV="$(hostname -I | awk '{print $1}')"
  echo "PRIVATE_IP=${PRIV}" >> /tmp/ic.$$
  echo "PUBLIC_IP=${SIP_IP}" >> /tmp/ic.$$
  mv /tmp/ic.$$ "${OWN_ETC}/install.conf"
fi

# --- DB / env ---
bash "$SRC/deploy/fix-opensips-provision.sh"
bash "$SRC/deploy/fix-drouting-schema.sh"

# Migrations as root (triggers) then as app user
mysql --protocol=socket -uroot ownswitch < "$SRC/database/migrations/009_ledger_guard.sql" 2>/dev/null || true
mysql --protocol=socket -uroot ownswitch -e "INSERT IGNORE INTO schema_migrations (migration, checksum) VALUES ('009_ledger_guard.sql', SHA2(LOAD_FILE('$SRC/database/migrations/009_ledger_guard.sql'),256));" 2>/dev/null || true

bash "$SRC/deploy/refresh-ui.sh"
CUR=$(readlink -f /opt/ownswitch/current)
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/migrate.php" || \
  env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/migrate.php" || true

# Mark ledger applied if triggers exist
mysql --protocol=socket -uroot ownswitch -e "
INSERT IGNORE INTO schema_migrations (migration, checksum)
SELECT '009_ledger_guard.sql', 'manual-root'
WHERE EXISTS (SELECT 1 FROM information_schema.triggers WHERE trigger_schema='ownswitch' AND trigger_name='trg_ledger_no_update');
" 2>/dev/null || true

# --- OpenSIPS with 0.0.0.0 sockets + UAC ---
export DEBIAN_FRONTEND=noninteractive
apt-get install -y opensips-uac-module opensips-nathelper-module 2>/dev/null || true
# Import uac schema
if ! mysql --protocol=socket -uroot -N -e "SELECT 1 FROM information_schema.tables WHERE table_schema='opensips' AND table_name='uac_registrant'" | grep -q 1; then
  f=$(find /usr/share/opensips /usr/share/doc -iname '*uac*registrant*.sql' 2>/dev/null | head -n1 || true)
  [[ -n "${f:-}" ]] && mysql --protocol=socket -uroot opensips <"$f" || true
fi
if ! mysql --protocol=socket -uroot -N -e "SELECT 1 FROM information_schema.tables WHERE table_schema='opensips' AND table_name='uac_registrant'" | grep -q 1; then
  mysql --protocol=socket -uroot opensips <<'SQL'
CREATE TABLE IF NOT EXISTS uac_registrant (
  id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  l_uuid CHAR(64) NOT NULL,
  l_username CHAR(64) NOT NULL,
  l_domain CHAR(128) DEFAULT '' NOT NULL,
  r_username CHAR(64) DEFAULT NULL,
  r_domain CHAR(128) DEFAULT NULL,
  realm CHAR(64) DEFAULT NULL,
  auth_username CHAR(64) DEFAULT NULL,
  auth_password CHAR(64) DEFAULT NULL,
  auth_proxy CHAR(128) DEFAULT NULL,
  expires INT(11) DEFAULT 0 NOT NULL,
  forced_socket CHAR(64) DEFAULT NULL,
  contact_addr CHAR(255) DEFAULT NULL,
  UNIQUE KEY (l_uuid)
);
SQL
fi

# OpenSIPS sockets + host/cloud UDP path (required for default softphone UDP)
bash "$SRC/deploy/fix-udp-5060.sh" || true
systemctl restart opensips
sleep 2

# Align SIP account domains
mysql --protocol=socket -uroot ownswitch -e "UPDATE sip_accounts SET domain='${DOMAIN}';" 2>/dev/null || true

# Force register-mode on trunks that have username+secret (user asked for user/pass register)
mysql --protocol=socket -uroot ownswitch -e "
UPDATE carrier_trunks
SET auth_mode='register'
WHERE auth_username IS NOT NULL AND auth_username<>'' AND auth_secret_enc IS NOT NULL AND auth_secret_enc<>'' AND (auth_mode IS NULL OR auth_mode IN ('ip','invite',''));
" 2>/dev/null || true

runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-trunks.php" || true

# Reload UAC
curl -s -X POST http://127.0.0.1:8888/mi -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","method":"reg_reload","id":1}' || true
opensips-cli -x mi reg_reload 2>/dev/null || opensips-cli -x mi uac_reg_reload 2>/dev/null || true

echo
echo "======== STATUS ========"
systemctl is-active opensips || true
ss -ulnp | grep 5060 || echo "NO UDP 5060"
ss -tlnp | grep 5060 || echo "NO TCP 5060"
grep -E '^socket=' /etc/opensips/opensips.cfg || true
grep use_domain /etc/opensips/opensips.cfg || true
mysql --protocol=socket -uroot opensips -e "
SELECT COUNT(*) subscribers FROM subscriber;
SELECT username,domain FROM subscriber;
SELECT COUNT(*) contacts FROM location;
SELECT COUNT(*) gateways FROM dr_gateways;
SELECT gwid,address FROM dr_gateways;
SELECT COUNT(*) registrants FROM uac_registrant;
SELECT l_uuid,l_username,r_domain,auth_proxy FROM uac_registrant;
" 2>/dev/null || true

echo
echo "######################################################################"
echo " A) SIP USER (softphone) — Transport=UDP port 5060"
echo "######################################################################"
echo " 1. Softphone: User/Pass from portal · Domain=${DOMAIN} · Proxy=${SIP_IP}"
echo "      Port=5060 · Transport=UDP"
echo " 2. If UDP still times out from the internet: put Hetzner API token in"
echo "      /etc/ownswitch/hcloud.token and re-run: sudo bash $SRC/deploy/fix-udp-5060.sh"
echo "    Or Console → Firewalls → allow inbound UDP 5060 (Cloud FW ≠ ufw)."
echo " 3. System Status must show registered_contacts > 0"
echo
echo " B) CARRIER → vendor Magnus"
echo "######################################################################"
echo " IP peer: vendor allowlists ${SIP_IP} — Magnus will NEVER show Registered."
echo " User/pass: Auth mode=Register; need rows in uac_registrant above."
echo "   journalctl -u opensips -n 100 --no-pager | grep -iE 'regist|uac'"
echo "######################################################################"
