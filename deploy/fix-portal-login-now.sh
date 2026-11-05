#!/usr/bin/env bash
# =============================================================================
# Ownswitch — fix portal HTTP 500 / login (migrations + admin + PHP/nginx).
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-portal-login-now.sh \
#     | sudo OWN_SWITCH_ADMIN_PASS='Openaccount@123.' bash
# =============================================================================
set -Eeuo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_OPT="${OWNSWITCH_OPT:-/opt/ownswitch}"
OWN_ETC="${OWNSWITCH_ETC:-/etc/ownswitch}"
OWN_VAR="${OWNSWITCH_VAR:-/var/lib/ownswitch}"
ENV_FILE="${OWN_ETC}/ownswitch.env"
DOMAIN="${OWNSWITCH_DOMAIN:-voip.xceedconnections.com}"
EMAIL="${OWNSWITCH_ADMIN_EMAIL:-admin@${DOMAIN}}"
PASS="${OWN_SWITCH_ADMIN_PASS:-${OWNSWITCH_ADMIN_PASS:-}}"
REPO="https://github.com/xceedconnections/ownswitchfreeswitch.git"

log() { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }
die() { printf 'FATAL: %s\n' "$*" >&2; exit 1; }

[[ -n "$PASS" ]] || die "Set OWN_SWITCH_ADMIN_PASS (e.g. Openaccount@123.)"
(( ${#PASS} >= 12 )) || die "Admin password must be at least 12 characters"

export DEBIAN_FRONTEND=noninteractive
apt-get install -y -qq git curl ca-certificates rsync mysql-client \
  php8.3-cli php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-zip php8.3-bcmath php8.3-intl nginx >/dev/null || apt-get install -y \
  git curl ca-certificates rsync mysql-client \
  php8.3-cli php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-zip php8.3-bcmath php8.3-intl nginx

# --- source ---
if [[ ! -d "${SRC}/.git" ]]; then
  rm -rf "$SRC"
  git clone --depth 1 "$REPO" "$SRC"
else
  git -C "$SRC" remote set-url origin "$REPO"
  git -C "$SRC" fetch origin main
  git -C "$SRC" reset --hard origin/main
fi
[[ -f "${SRC}/public/index.php" ]] || die "public/index.php missing in ${SRC}"

# Ensure PHP/nginx stages exist if this host never reached stage 80.
if ! systemctl is-active --quiet php8.3-fpm 2>/dev/null || ! systemctl is-active --quiet nginx 2>/dev/null; then
  log "Running deploy stages 80 (PHP/nginx)…"
  bash "${SRC}/deploy/install.sh" --only 80 --yes --non-interactive || true
fi

# Ensure env + release via stage 90 (migrations + deploy). Skip admin here; we reset below.
log "Running deploy stage 90 (app deploy + migrations)…"
export OWNSWITCH_DOMAIN="$DOMAIN"
export OWNSWITCH_ADMIN_EMAIL="$EMAIL"
export OWN_SWITCH_ADMIN_PASS="$PASS"
export APP_SRC="$SRC"
export SKIP_ADMIN=1
bash "${SRC}/deploy/install.sh" --only 90 --yes --non-interactive

CUR="$(readlink -f "${OWN_OPT}/current" 2>/dev/null || true)"
[[ -f "${CUR}/public/index.php" ]] || die "portal release missing at ${OWN_OPT}/current"
[[ -f "$ENV_FILE" ]] || die "${ENV_FILE} missing after stage 90"

# Force migrations again (idempotent) in case stage 90 skipped them.
log "Applying migrations…"
if [[ -f "${CUR}/bin/migrate.php" ]]; then
  runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "${CUR}/bin/migrate.php" \
    || env OWNSWITCH_ENV_FILE="$ENV_FILE" php "${CUR}/bin/migrate.php" \
    || die "migrate.php failed"
fi

TABLES="$(mysql --protocol=socket -uroot -N -B -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='ownswitch'" 2>/dev/null || echo 0)"
log "ownswitch tables: ${TABLES}"
[[ "${TABLES}" -gt 5 ]] || die "ownswitch DB still empty (${TABLES} tables) — migrations did not apply"

# Create or reset admin password
log "Setting admin ${EMAIL}…"
set +e
printf '%s' "$PASS" | runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" \
  php "${CUR}/bin/create-admin.php" --email="$EMAIL" --password-stdin --reset-password
rc=$?
set -e
[[ $rc -eq 0 ]] || die "create-admin failed rc=${rc}"

install -d -m 0750 -o ownswitch -g ownswitch "${OWN_VAR}/storage/cache" "${OWN_VAR}/storage/uploads" 2>/dev/null || true
chmod 0755 /opt/ownswitch /opt/ownswitch/releases 2>/dev/null || true
chmod 0751 "$CUR" 2>/dev/null || true
chmod -R go+rX "${CUR}/public" 2>/dev/null || true
id www-data >/dev/null 2>&1 && usermod -aG ownswitch www-data 2>/dev/null || true

systemctl restart php8.3-fpm
nginx -t
systemctl reload nginx || systemctl restart nginx

umask 077
cat > /root/ownswitch-credentials.txt <<EOF
Ownswitch admin
URL:      https://${DOMAIN}/login
Email:    ${EMAIL}
Password: ${PASS}
Generated: $(date -u -Iseconds)
EOF
chmod 600 /root/ownswitch-credentials.txt

CODE="$(curl -ks -o /tmp/ow-login.html -w '%{http_code}' "https://127.0.0.1/login" -H "Host: ${DOMAIN}" || true)"
log "GET /login -> HTTP ${CODE}"
head -c 240 /tmp/ow-login.html 2>/dev/null; echo
[[ "$CODE" == "200" ]] || {
  log "Still not 200 — last PHP errors:"
  tail -n 40 /var/log/ownswitch/php-fpm-error.log 2>/dev/null || true
  tail -n 40 /var/log/nginx/ownswitch.error.log 2>/dev/null || true
  die "portal login still broken (HTTP ${CODE})"
}

echo
echo "OK — portal login should work:"
echo "  URL:   https://${DOMAIN}/login"
echo "  Email: ${EMAIL}"
echo "  Pass:  (see /root/ownswitch-credentials.txt)"
