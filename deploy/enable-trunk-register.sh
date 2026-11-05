#!/usr/bin/env bash
# Install OpenSIPS UAC registrant support + re-render cfg + sync trunks.
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitch/main/deploy/enable-trunk-register.sh | sudo bash
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
cd "$SRC"
git fetch origin main && git reset --hard origin/main

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq || true
apt-get install -y opensips-uac-module || apt-get install -y opensips-module-uac || true

# OpenSIPS 3.6+ uses table `registrant` (not legacy uac_registrant alone)
mysql --protocol=socket -uroot opensips <<'SQL' || true
CREATE TABLE IF NOT EXISTS registrant (
  id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  registrar CHAR(255) DEFAULT '' NOT NULL,
  proxy CHAR(255) DEFAULT NULL,
  aor CHAR(255) DEFAULT '' NOT NULL,
  third_party_registrant CHAR(255) DEFAULT NULL,
  username CHAR(64) DEFAULT NULL,
  password CHAR(64) DEFAULT NULL,
  binding_URI CHAR(255) DEFAULT '' NOT NULL,
  binding_params CHAR(64) DEFAULT NULL,
  expiry INT(11) UNSIGNED DEFAULT 0 NOT NULL,
  forced_socket CHAR(64) DEFAULT NULL,
  cluster_shtag CHAR(64) DEFAULT NULL,
  state INT DEFAULT 0 NOT NULL,
  description CHAR(64) DEFAULT NULL,
  KEY aor_idx (aor)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
SQL
# Also import package schema if present (legacy name)
if ! mysql --protocol=socket -uroot -N -e "SELECT 1 FROM information_schema.tables WHERE table_schema='opensips' AND table_name='uac_registrant'" | grep -q 1; then
  schema=$(find /usr/share/opensips /usr/share/doc/opensips* -name '*uac*registrant*.sql' -o -name '*registrant*.sql' 2>/dev/null | head -n1 || true)
  if [[ -n "${schema:-}" ]]; then
    mysql --protocol=socket -uroot opensips <"$schema" || true
  fi
fi

# Re-run OpenSIPS stage WITH UAC registrant enabled
export OWNSWITCH_ENABLE_UAC_REGISTRANT=1
bash "$SRC/deploy/install.sh" --only 50 --yes --non-interactive || true
# If still down, diagnose module init
if ! systemctl is-active --quiet opensips; then
  bash "$SRC/deploy/fix-opensips-module-init.sh" || true
fi

# Portal release with new PHP sync code
bash "$SRC/deploy/refresh-ui.sh"

CUR=$(readlink -f /opt/ownswitch/current)
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
  php "$CUR/bin/migrate.php" || php "$CUR/bin/migrate.php" || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
  php "$CUR/bin/sync-opensips-trunks.php" || php "$CUR/bin/sync-opensips-trunks.php" || true

# Force UAC reload (MI name is reg_* when uac_registrant.so is loaded)
curl -s -X POST http://127.0.0.1:8888/mi -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","method":"reg_reload","id":1}' 2>/dev/null || true
opensips-cli -x mi reg_reload 2>/dev/null || opensips-cli -x mi uac_reg_reload 2>/dev/null || true
if ! opensips-cli -x mi reg_list 2>/dev/null | head -n 40; then
  echo "NOTE: reg_list unavailable — uac_registrant may not be loaded yet (re-run stage 50 after FAILOVER_SIP_STATUS fix)."
  mysql --protocol=socket -uroot opensips -e "SELECT id,registrar,aor,username,state FROM registrant LIMIT 20;" 2>/dev/null \
    || mysql --protocol=socket -uroot opensips -e "SELECT l_uuid,l_username,r_domain,auth_proxy FROM uac_registrant LIMIT 20;" 2>/dev/null \
    || true
fi

echo
echo "Public SIP IP (give this to your Magnus vendor for IP peering):"
hostname -I | awk '{print $1}'
curl -4 -s ifconfig.me 2>/dev/null || curl -4 -s icanhazip.com 2>/dev/null || true
echo
echo "Done. For IP peer trunks: vendor must allowlist the public IP above — they will NOT show Registered."
echo "For Register trunks: Auth mode=Register + user/pass → save → MI reg_list state 3 = REGISTERED."
