#!/usr/bin/env bash
# Fix trunk OpenSIPS sync (missing dr_gateways / dr_rules / bad DB password).
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-drouting-schema.sh | sudo bash
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_ETC="${OWN_ETC:-/etc/ownswitch}"
ENV_FILE="${OWN_ETC}/ownswitch.env"
SECRET="${OWN_ETC}/secrets/mysql_opensips_password"
DB_OPENSIPS="$(grep -E '^OPENSIPS_DB_DATABASE=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)"
DB_OPENSIPS="${DB_OPENSIPS:-opensips}"

cd "$SRC"
git fetch origin main && git reset --hard origin/main
bash "$SRC/deploy/fix-opensips-provision.sh" || true

echo "== Apply drouting schema if missing =="
need_gw=0 need_rules=0
mysql --protocol=socket -uroot -N -e "SELECT 1 FROM information_schema.tables WHERE table_schema='${DB_OPENSIPS}' AND table_name='dr_gateways'" | grep -q 1 || need_gw=1
mysql --protocol=socket -uroot -N -e "SELECT 1 FROM information_schema.tables WHERE table_schema='${DB_OPENSIPS}' AND table_name='dr_rules'" | grep -q 1 || need_rules=1

if (( need_gw || need_rules )); then
  f=$(find /usr/share/opensips /usr/share/doc /usr/share/opensips-mysql-module \
      -type f \( -iname '*drouting*create*.sql' -o -iname 'drouting-create.sql' \) 2>/dev/null | head -n1 || true)
  if [[ -n "${f:-}" ]]; then
    echo "Applying $f"
    mysql --protocol=socket -uroot "${DB_OPENSIPS}" <"$f" || true
  else
    echo "Creating minimal dr_gateways / dr_rules..."
    mysql --protocol=socket -uroot "${DB_OPENSIPS}" <<'SQL'
CREATE TABLE IF NOT EXISTS dr_gateways (
  id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  gwid CHAR(64) DEFAULT '' NOT NULL,
  type INT UNSIGNED DEFAULT 0 NOT NULL,
  address CHAR(128) DEFAULT '' NOT NULL,
  strip INT UNSIGNED DEFAULT 0 NOT NULL,
  pri_prefix CHAR(16) DEFAULT NULL,
  attrs CHAR(255) DEFAULT NULL,
  probe_mode INT UNSIGNED DEFAULT 0 NOT NULL,
  state INT UNSIGNED DEFAULT 0 NOT NULL,
  socket CHAR(128) DEFAULT NULL,
  description CHAR(128) DEFAULT '' NOT NULL,
  UNIQUE KEY dr_gw_idx (gwid)
);
CREATE TABLE IF NOT EXISTS dr_rules (
  ruleid INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  groupid CHAR(255) NOT NULL,
  prefix CHAR(64) NOT NULL,
  timerec CHAR(255) DEFAULT '' NOT NULL,
  priority INT DEFAULT 0 NOT NULL,
  routeid CHAR(255) DEFAULT NULL,
  gwlist CHAR(255) NOT NULL,
  sort_alg CHAR(1) DEFAULT 'N' NOT NULL,
  sort_profile INT DEFAULT NULL,
  attrs CHAR(255) DEFAULT NULL,
  description CHAR(128) DEFAULT '' NOT NULL
);
SQL
  fi
fi

mysql --protocol=socket -uroot "${DB_OPENSIPS}" -e "SHOW TABLES LIKE 'dr_%'; DESCRIBE dr_gateways; DESCRIBE dr_rules;" || true

# Optional UAC for Register-mode trunks
apt-get install -y opensips-uac-module 2>/dev/null || true
if [[ ! -f /usr/lib/*/opensips/modules/uac_registrant.so ]] && [[ ! -f /usr/lib/x86_64-linux-gnu/opensips/modules/uac_registrant.so ]]; then
  echo "NOTE: uac_registrant.so still missing — use Auth mode = IP peer (vendor allowlists your IP)."
fi

echo "== Deploy code + sync trunks =="
bash "$SRC/deploy/refresh-ui.sh"
CUR=$(readlink -f /opt/ownswitch/current)
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-trunks.php" || \
  env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-trunks.php" || true

echo "== Result =="
mysql --protocol=socket -uroot "${DB_OPENSIPS}" -e "SELECT gwid,address,description FROM dr_gateways; SELECT ruleid,groupid,prefix,gwlist FROM dr_rules LIMIT 20;" || true

systemctl reload php8.3-fpm 2>/dev/null || systemctl restart php8.3-fpm || true
echo "Done. Re-save the trunk. Use Auth mode = IP peer unless vendor requires REGISTER."
