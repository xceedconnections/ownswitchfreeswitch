#!/usr/bin/env bash
# Fix "Database error; the change was not saved" when trunk auth_mode columns are missing.
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitch/main/deploy/fix-trunk-schema.sh | sudo bash
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
cd "$SRC"
git fetch origin main && git reset --hard origin/main
bash "$SRC/deploy/refresh-ui.sh"

CUR=$(readlink -f /opt/ownswitch/current)
echo "== migrate =="
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
  php "$CUR/bin/migrate.php" || php "$CUR/bin/migrate.php"

echo "== columns on carrier_trunks =="
mysql --protocol=socket -uroot ownswitch -e "SHOW COLUMNS FROM carrier_trunks LIKE 'auth%'; SHOW COLUMNS FROM carrier_trunks LIKE 'outbound%'; SHOW COLUMNS FROM carrier_trunks LIKE 'register%';" \
  || mysql --protocol=socket -uroot -e "SHOW DATABASES;"

echo "== sync opensips =="
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
  php "$CUR/bin/sync-opensips-trunks.php" || php "$CUR/bin/sync-opensips-trunks.php" || true

echo "Done. Re-save the trunk in the portal."
