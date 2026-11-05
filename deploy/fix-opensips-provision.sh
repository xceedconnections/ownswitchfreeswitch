#!/usr/bin/env bash
# Fix "OpenSIPS provisioning failed" — sync DB password, grants, schema, then test write.
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-opensips-provision.sh | sudo bash
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
bash "$SRC/deploy/refresh-ui.sh"

[[ -f "$SECRET" ]] || { echo "Missing $SECRET"; exit 1; }
PW="$(tr -d '\n' <"$SECRET")"
[[ -n "$PW" ]] || { echo "Empty opensips password secret"; exit 1; }

echo "== Sync env password from secret =="
grep -q '^OPENSIPS_DB_PASSWORD=' "$ENV_FILE" \
  && sed -i "s|^OPENSIPS_DB_PASSWORD=.*|OPENSIPS_DB_PASSWORD=${PW}|" "$ENV_FILE" \
  || echo "OPENSIPS_DB_PASSWORD=${PW}" >>"$ENV_FILE"
grep -q '^OPENSIPS_ENABLED=' "$ENV_FILE" && sed -i 's/^OPENSIPS_ENABLED=.*/OPENSIPS_ENABLED=1/' "$ENV_FILE" || echo 'OPENSIPS_ENABLED=1' >>"$ENV_FILE"
DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "${OWN_ETC}/install.conf" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
grep -q '^OPENSIPS_SIP_DOMAIN=' "$ENV_FILE" && sed -i "s/^OPENSIPS_SIP_DOMAIN=.*/OPENSIPS_SIP_DOMAIN=${DOMAIN}/" "$ENV_FILE" || echo "OPENSIPS_SIP_DOMAIN=${DOMAIN}" >>"$ENV_FILE"
chmod 0640 "$ENV_FILE"
chown root:ownswitch "$ENV_FILE"

echo "== Reset MySQL opensips@localhost password + grants =="
mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_OPENSIPS}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'opensips'@'localhost' IDENTIFIED BY '${PW}';
CREATE USER IF NOT EXISTS 'opensips'@'127.0.0.1' IDENTIFIED BY '${PW}';
ALTER USER 'opensips'@'localhost' IDENTIFIED BY '${PW}';
ALTER USER 'opensips'@'127.0.0.1' IDENTIFIED BY '${PW}';
GRANT ALL PRIVILEGES ON \`${DB_OPENSIPS}\`.* TO 'opensips'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_OPENSIPS}\`.* TO 'opensips'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

echo "== Ensure subscriber + location tables =="
if ! mysql --protocol=socket -uroot -N -e "SELECT 1 FROM information_schema.tables WHERE table_schema='${DB_OPENSIPS}' AND table_name='subscriber'" | grep -q 1; then
  f=$(find /usr/share/opensips /usr/share/doc /usr/share/opensips-mysql-module -name 'standard-create.sql' 2>/dev/null | head -n1 || true)
  [[ -n "${f:-}" ]] && mysql --protocol=socket -uroot "${DB_OPENSIPS}" <"$f" || true
fi
if ! mysql --protocol=socket -uroot -N -e "SELECT 1 FROM information_schema.tables WHERE table_schema='${DB_OPENSIPS}' AND table_name='location'" | grep -q 1; then
  f=$(find /usr/share/opensips /usr/share/doc /usr/share/opensips-mysql-module \( -name 'usrloc-create.sql' -o -name '*usrloc*create*.sql' \) 2>/dev/null | head -n1 || true)
  if [[ -n "${f:-}" ]]; then
    echo "Applying ${f}"
    mysql --protocol=socket -uroot "${DB_OPENSIPS}" <"$f" || true
  fi
fi
if ! mysql --protocol=socket -uroot -N -e "SELECT 1 FROM information_schema.tables WHERE table_schema='${DB_OPENSIPS}' AND table_name='location'" | grep -q 1; then
  echo "Creating opensips.location fallback (usrloc write-through)"
  mysql --protocol=socket -uroot "${DB_OPENSIPS}" <<'SQL'
CREATE TABLE IF NOT EXISTS location (
  contact_id BIGINT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  username CHAR(64) DEFAULT '' NOT NULL,
  domain CHAR(64) DEFAULT NULL,
  contact TEXT NOT NULL,
  received CHAR(255) DEFAULT NULL,
  path CHAR(255) DEFAULT NULL,
  expires INT(10) UNSIGNED NOT NULL,
  q FLOAT(10,2) DEFAULT 1.0 NOT NULL,
  callid CHAR(255) DEFAULT 'Default-Call-ID' NOT NULL,
  cseq INT(11) DEFAULT 13 NOT NULL,
  last_modified DATETIME DEFAULT '1900-01-01 00:00:01' NOT NULL,
  flags INT(11) DEFAULT 0 NOT NULL,
  cflags CHAR(255) DEFAULT NULL,
  user_agent CHAR(255) DEFAULT '' NOT NULL,
  socket CHAR(64) DEFAULT NULL,
  methods INT(11) DEFAULT NULL,
  sip_instance CHAR(255) DEFAULT NULL,
  kv_store TEXT(512) DEFAULT NULL,
  attr CHAR(255) DEFAULT NULL
) ENGINE=InnoDB;
INSERT INTO version (table_name, table_version)
  SELECT 'location', '1013' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM version WHERE table_name='location');
SQL
fi
mysql --protocol=socket -uroot "${DB_OPENSIPS}" -e "SHOW TABLES LIKE 'subscriber'; SHOW TABLES LIKE 'location';" || true
systemctl restart opensips 2>/dev/null || true

echo "== PHP connect test as ownswitch =="
CUR=$(readlink -f /opt/ownswitch/current)
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php -r '
require "'"$CUR"'/app/bootstrap.php";
$p = Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner::fromConfig();
echo "enabled=" . ($p->enabled() ? "yes" : "no") . " err=" . $p->initError() . "\n";
if ($p->enabled()) {
  $p->upsertSubscriber("__ownswitch_probe__", "probe.invalid", "x", "y");
  $p->removeSubscriber("__ownswitch_probe__", "probe.invalid");
  echo "upsert probe OK\n";
}
' || true

echo "== Sync SIP users =="
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true

systemctl reload php8.3-fpm 2>/dev/null || systemctl restart php8.3-fpm || true

echo
echo "Done. Re-edit the SIP user, set a NEW password, Save."
echo "If it still fails, the portal warning will now show the exact MySQL/OpenSIPS error."
