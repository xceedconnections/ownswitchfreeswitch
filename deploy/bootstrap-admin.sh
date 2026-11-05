#!/usr/bin/env bash
# Fix DB env key names, migrate, create admin. Portal login is already serving.
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitch/main/deploy/bootstrap-admin.sh | sudo bash
set -Eeuo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
ENVF=/etc/ownswitch/ownswitch.env
CUR=$(readlink -f /opt/ownswitch/current)
DOMAIN=$(grep -E '^OWNSWITCH_DOMAIN=' /etc/ownswitch/install.conf 2>/dev/null | cut -d= -f2- || echo voip.xceedconnections.com)
EMAIL="${OWNSWITCH_ADMIN_EMAIL:-admin@${DOMAIN}}"
PASS="${OWN_SWITCH_ADMIN_PASS:-$(openssl rand -base64 16 | tr -d '/+=' | head -c 16)Aa1!}"

git -C "$SRC" fetch --depth 1 origin main >/dev/null 2>&1 || true
git -C "$SRC" reset --hard origin/main >/dev/null 2>&1 || true

# Sync fixed PHP config into live release
cp -f "$SRC/config/database.php" "$CUR/config/database.php"

# Normalize env keys so PHP reads the portal DB correctly
if [[ -f "$ENVF" ]]; then
  # If only DB_NAME exists, also set DB_DATABASE
  if grep -q '^DB_NAME=' "$ENVF" && ! grep -q '^DB_DATABASE=' "$ENVF"; then
    grep '^DB_NAME=' "$ENVF" | sed 's/^DB_NAME=/DB_DATABASE=/' >>"$ENVF"
  fi
  if grep -q '^DB_USER=' "$ENVF" && ! grep -q '^DB_USERNAME=' "$ENVF"; then
    grep '^DB_USER=' "$ENVF" | sed 's/^DB_USER=/DB_USERNAME=/' >>"$ENVF"
  fi
  # APP_KEY required
  if ! grep -qE '^APP_KEY=.+' "$ENVF"; then
    echo "APP_KEY=$(openssl rand -hex 32)" >>"$ENVF"
  fi
  # SESSION secure for HTTPS
  grep -q '^SESSION_SECURE=' "$ENVF" || echo 'SESSION_SECURE=true' >>"$ENVF"
  chmod 640 "$ENVF"
  chown root:ownswitch "$ENVF"
else
  echo "FATAL: $ENVF missing. Re-run: sudo APP_SRC=$SRC $SRC/deploy/install.sh --only 90 --yes"
  exit 1
fi

echo "Env DB settings:"
grep -E '^DB_(HOST|PORT|DATABASE|USERNAME|NAME|USER)=' "$ENVF" | sed 's/PASSWORD=.*/PASSWORD=***/'

systemctl reload php8.3-fpm || systemctl restart php8.3-fpm

echo "Migrating…"
cd "$CUR"
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENVF" php bin/migrate.php --create-db 2>&1 || \
  env OWNSWITCH_ENV_FILE="$ENVF" php bin/migrate.php --create-db 2>&1 || true

echo "Creating admin ${EMAIL}…"
set +e
OUT=$(printf '%s' "$PASS" | env OWNSWITCH_ENV_FILE="$ENVF" php "$CUR/bin/create-admin.php" --email="$EMAIL" --name=Admin --password-stdin 2>&1)
RC=$?
set -e
echo "$OUT"
# Also try create_admin.php if hyphenated failed
if [[ $RC -ne 0 && $RC -ne 10 ]]; then
  set +e
  OUT=$(env OWNSWITCH_ENV_FILE="$ENVF" php "$CUR/bin/create_admin.php" --email="$EMAIL" --name=Admin --password="$PASS" 2>&1)
  RC=$?
  set -e
  echo "$OUT"
fi

umask 077
cat > /root/ownswitch-credentials.txt <<EOF
Ownswitch admin
URL:      https://${DOMAIN}/login
Email:    ${EMAIL}
Password: ${PASS}
create-admin exit: ${RC} (0=created 10=exists)
Generated: $(date -u -Iseconds)
EOF
chmod 600 /root/ownswitch-credentials.txt

echo
echo "=== credentials ==="
cat /root/ownswitch-credentials.txt
echo
echo "=== probes ==="
curl -ks -o /dev/null -w 'GET /      -> %{http_code}\n' "https://127.0.0.1/" -H "Host: ${DOMAIN}"
curl -ks -o /dev/null -w 'GET /login -> %{http_code}\n' "https://127.0.0.1/login" -H "Host: ${DOMAIN}"
