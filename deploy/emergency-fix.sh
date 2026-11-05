#!/usr/bin/env bash
# =============================================================================
# Ownswitch emergency fix — bypasses stage quirks; does the remaining work directly.
#
# Fixes:
#   1) opensips.subscriber schema
#   2) real portal deploy (kill 503 placeholder)
#   3) first admin credentials
#   4) CDR worker symlink/unit
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitch/main/deploy/emergency-fix.sh \
#     | sudo bash
# =============================================================================
set -Eeuo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
OWN_OPT=/opt/ownswitch
OWN_VAR=/var/lib/ownswitch
OWN_ETC=/etc/ownswitch
LOG=/var/log/ownswitch-emergency-fix.log
DOMAIN="${OWNSWITCH_DOMAIN:-voip.xceedconnections.com}"
EMAIL="${OWNSWITCH_ADMIN_EMAIL:-admin@${DOMAIN}}"

exec > >(tee -a "$LOG") 2>&1
echo "==== $(date -u -Iseconds) emergency-fix ===="

export DEBIAN_FRONTEND=noninteractive
apt-get install -y -qq git curl ca-certificates rsync mysql-client >/dev/null || true

# ---------------------------------------------------------------------------
# 0. Fresh source tree (guaranteed full checkout)
# ---------------------------------------------------------------------------
if [[ ! -d "$SRC/.git" ]]; then
  rm -rf "$SRC"
  git clone --depth 1 https://github.com/xceedconnections/ownswitchfreeswitch.git "$SRC"
else
  git -C "$SRC" remote set-url origin https://github.com/xceedconnections/ownswitchfreeswitch.git
  git -C "$SRC" fetch --depth 1 origin main
  git -C "$SRC" reset --hard origin/main
  # Ensure we are not on a shallow tree missing paths
  git -C "$SRC" checkout -f origin/main -- public app bin config database composer.json 2>/dev/null || true
fi

echo "-- source check --"
ls -la "$SRC/public/index.php" "$SRC/bin/create-admin.php" "$SRC/bin/worker.php" "$SRC/bin/worker_cdr.php"
test -f "$SRC/public/index.php" || { echo "FATAL: public/index.php still missing after clone"; exit 1; }

# ---------------------------------------------------------------------------
# 1. OpenSIPS subscriber schema
# ---------------------------------------------------------------------------
echo "-- OpenSIPS schema --"
mysql --protocol=socket -uroot -e "CREATE DATABASE IF NOT EXISTS opensips CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

list_tables() {
  mysql --protocol=socket -uroot -N -B -e "SHOW TABLES FROM opensips" 2>/dev/null || true
}
echo "Tables before: $(list_tables | tr '\n' ' ')"

has_subscriber() {
  mysql --protocol=socket -uroot -N -B -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='opensips' AND table_name='subscriber'" \
    | grep -qx 1
}

if ! has_subscriber; then
  echo "Searching packaged SQL for subscriber…"
  mapfile -t SQLS < <(find /usr/share/opensips /usr/share/opensips-* /usr/share/doc/opensips* \
    -type f -name '*.sql' 2>/dev/null | sort -u || true)
  echo "Found ${#SQLS[@]} sql files"
  for f in "${SQLS[@]:-}"; do
    if grep -qiE 'create[[:space:]]+table.*[`"]?subscriber[`"]?' "$f" 2>/dev/null; then
      echo "Applying $f"
      mysql --protocol=socket -uroot opensips <"$f" 2>/tmp/osips-sql.err || {
        echo "  (non-zero apply — $(head -c 200 /tmp/osips-sql.err | tr '\n' ' '))"
      }
    fi
  done
fi

if ! has_subscriber; then
  echo "Packaged SQL insufficient — downloading OpenSIPS 3.6 standard MySQL schema from GitHub…"
  TMP=$(mktemp -d)
  # OpenSIPS scripts/mysql/standard-create.sql (auth tables live here historically)
  for url in \
    "https://raw.githubusercontent.com/OpenSIPS/opensips/3.6/scripts/mysql/standard-create.sql" \
    "https://raw.githubusercontent.com/OpenSIPS/opensips/3.6/scripts/mysql/auth_db-create.sql" \
    "https://raw.githubusercontent.com/OpenSIPS/opensips/3.5/scripts/mysql/standard-create.sql" \
    "https://raw.githubusercontent.com/OpenSIPS/opensips/3.5/scripts/mysql/auth_db-create.sql"
  do
    bn=$(basename "$url")
    if curl -fsSL "$url" -o "$TMP/$bn"; then
      echo "Fetched $bn"
      if grep -qiE 'subscriber' "$TMP/$bn"; then
        mysql --protocol=socket -uroot opensips <"$TMP/$bn" 2>/tmp/osips-sql.err || true
      fi
    fi
  done
  rm -rf "$TMP"
fi

if ! has_subscriber; then
  echo "Creating minimal subscriber table (OpenSIPS auth_db compatible)…"
  mysql --protocol=socket -uroot opensips <<'SQL'
CREATE TABLE IF NOT EXISTS version (
  table_name CHAR(32) NOT NULL,
  table_version INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY t (table_name)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS subscriber (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  username CHAR(64) NOT NULL DEFAULT '',
  domain CHAR(64) NOT NULL DEFAULT '',
  password CHAR(64) NOT NULL DEFAULT '',
  ha1 CHAR(128) NOT NULL DEFAULT '',
  ha1_sha256 CHAR(64) NOT NULL DEFAULT '',
  ha1_sha512t256 CHAR(64) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY account_idx (username, domain)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

INSERT IGNORE INTO version (table_name, table_version) VALUES ('subscriber', 8);
SQL
fi

has_subscriber || { echo "FATAL: still no opensips.subscriber"; list_tables; exit 1; }
echo "subscriber OK"
# Ensure opensips DB user can read it
if [[ -f /etc/ownswitch/secrets/mysql_opensips_password ]]; then
  PW=$(cat /etc/ownswitch/secrets/mysql_opensips_password)
  mysql --protocol=socket -uroot <<SQL
CREATE USER IF NOT EXISTS 'opensips'@'localhost' IDENTIFIED BY '${PW}';
CREATE USER IF NOT EXISTS 'opensips'@'127.0.0.1' IDENTIFIED BY '${PW}';
ALTER USER 'opensips'@'localhost' IDENTIFIED BY '${PW}';
ALTER USER 'opensips'@'127.0.0.1' IDENTIFIED BY '${PW}';
GRANT ALL PRIVILEGES ON opensips.* TO 'opensips'@'localhost';
GRANT ALL PRIVILEGES ON opensips.* TO 'opensips'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
fi
systemctl restart opensips || journalctl -u opensips -n 20 --no-pager || true

# ---------------------------------------------------------------------------
# 2. Deploy real application release
# ---------------------------------------------------------------------------
echo "-- deploy portal --"
install -d -m 0755 "$OWN_OPT/releases"
install -d -m 0750 -o ownswitch -g ownswitch "$OWN_VAR/storage/cache" "$OWN_VAR/storage/uploads" 2>/dev/null || {
  getent passwd ownswitch >/dev/null || useradd --system --home-dir "$OWN_OPT" --shell /usr/sbin/nologin --user-group ownswitch
  install -d -m 0750 -o ownswitch -g ownswitch "$OWN_VAR/storage/cache" "$OWN_VAR/storage/uploads"
}

REL="$OWN_OPT/releases/manual-$(date -u +%Y%m%d%H%M%S)"
mkdir -p "$REL"
rsync -a --delete \
  --exclude '.git/' --exclude 'deploy/' --exclude 'docs/' --exclude 'tests/' \
  --exclude 'node_modules/' --exclude '.env' --exclude '.env.*' --exclude '*.md' \
  "$SRC/" "$REL/"

test -f "$REL/public/index.php" || { echo "FATAL: rsync failed to copy public/index.php"; exit 1; }
rm -f "$REL/.ownswitch-placeholder"
ln -sfn "$OWN_VAR/storage" "$REL/storage"
chown -R root:ownswitch "$REL"
# other needs +x on the release root so nginx (www-data) can reach public/
chmod 0751 "$REL"
find "$REL" -mindepth 1 -maxdepth 1 ! -name public ! -name storage -exec chmod -R u=rwX,g=rX,o= {} +
chmod -R u=rwX,go=rX "$REL/public"
chmod 0755 /opt/ownswitch /opt/ownswitch/releases 2>/dev/null || true
id www-data >/dev/null 2>&1 && usermod -aG ownswitch www-data 2>/dev/null || true

# Atomic switch
ln -sfn "$REL" "$OWN_OPT/current"
rm -f "$OWN_OPT/current/.ownswitch-placeholder" 2>/dev/null || true
# Also remove marker if it was inside the release dir we linked
rm -f "$REL/.ownswitch-placeholder"

# Env file for PHP
if [[ ! -f "$OWN_ETC/ownswitch.env" ]]; then
  echo "WARN: $OWN_ETC/ownswitch.env missing — stage 90 normally creates it. Running stage 90 env render…"
  bash "$SRC/deploy/install.sh" --only 90 --yes --non-interactive || true
fi

# Point APP_SRC permanently
grep -v '^APP_SRC=' "$OWN_ETC/install.conf" > /tmp/ic 2>/dev/null || true
echo "APP_SRC=$SRC" >> /tmp/ic
mv /tmp/ic "$OWN_ETC/install.conf"

# Migrations
if [[ -f "$REL/bin/migrate.php" ]]; then
  echo "Running migrations…"
  runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$OWN_ETC/ownswitch.env" \
    php "$REL/bin/migrate.php" || php "$REL/bin/migrate.php" || true
fi

systemctl reload php8.3-fpm || systemctl restart php8.3-fpm
systemctl reload nginx || systemctl restart nginx

# ---------------------------------------------------------------------------
# 3. Admin user
# ---------------------------------------------------------------------------
echo "-- admin --"
PASS="${OWN_SWITCH_ADMIN_PASS:-$(openssl rand -base64 18 | tr -d '/+=' | head -c 18)Aa1!}"
set +e
printf '%s' "$PASS" | runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$OWN_ETC/ownswitch.env" \
  php "$REL/bin/create-admin.php" --email="$EMAIL" --password-stdin
rc=$?
set -e
if [[ $rc -eq 0 || $rc -eq 10 ]]; then
  umask 077
  cat > /root/ownswitch-credentials.txt <<EOF
Ownswitch admin
URL:      https://${DOMAIN}/
Email:    ${EMAIL}
Password: ${PASS}
Note:     exit ${rc} (0=created, 10=already existed — password above may be new only if created)
Generated: $(date -u -Iseconds)
EOF
  chmod 600 /root/ownswitch-credentials.txt
  echo "Wrote /root/ownswitch-credentials.txt"
else
  echo "create-admin failed rc=$rc — try manually:"
  echo "  sudo OWNSWITCH_ENV_FILE=$OWN_ETC/ownswitch.env php $REL/bin/create_admin.php --email=$EMAIL --password='YourPass123!'"
fi

# ---------------------------------------------------------------------------
# 4. Worker
# ---------------------------------------------------------------------------
echo "-- worker --"
if [[ -f "$REL/bin/worker_cdr.php" && ! -f "$REL/bin/worker.php" ]]; then
  cat > "$REL/bin/worker.php" <<'PHP'
<?php
$args = array_slice($argv, 1) ?: ['--loop'];
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/worker_cdr.php').' '.implode(' ', array_map('escapeshellarg', $args)), $rc);
exit($rc);
PHP
  chmod 755 "$REL/bin/worker.php"
fi
systemctl daemon-reload || true
systemctl enable --now ownswitch-worker 2>/dev/null || true
systemctl enable --now ownswitch-scheduler.timer ownswitch-backup.timer 2>/dev/null || true

# ---------------------------------------------------------------------------
# 5. Verify
# ---------------------------------------------------------------------------
echo "-- verify --"
echo "current -> $(readlink -f $OWN_OPT/current)"
ls -la "$OWN_OPT/current/public/index.php"
ls "$OWN_OPT/current/.ownswitch-placeholder" 2>/dev/null && echo "STILL PLACEHOLDER" || echo "placeholder gone OK"
has_subscriber && echo "subscriber OK" || echo "subscriber MISSING"
echo "HTTP:"
curl -ksI "https://127.0.0.1/" -H "Host: ${DOMAIN}" | head -n 12
echo
# Hit login path
CODE=$(curl -ks -o /tmp/ow.html -w '%{http_code}' "https://127.0.0.1/login" -H "Host: ${DOMAIN}" || true)
echo "GET /login -> HTTP $CODE"
head -c 200 /tmp/ow.html; echo
[[ -f /root/ownswitch-credentials.txt ]] && cat /root/ownswitch-credentials.txt
echo "Log: $LOG"
