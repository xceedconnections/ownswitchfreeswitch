#!/usr/bin/env bash
# =============================================================================
# Ownswitch — hard-repair broken HTTP/HTTPS portal (PR_END_OF_FILE_ERROR /
# empty reply on :80/:443), then migrations + admin password.
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-portal-web.sh \
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
TLS_DIR="/etc/ssl/ownswitch"
PUBLIC_IP="$(curl -4 -fsS --max-time 5 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')"

log() { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }
die() { printf 'FATAL: %s\n' "$*" >&2; exit 1; }

[[ -n "$PASS" ]] || die "Set OWN_SWITCH_ADMIN_PASS"
(( ${#PASS} >= 12 )) || die "Admin password must be at least 12 characters"

export DEBIAN_FRONTEND=noninteractive
export OWNSWITCH_DOMAIN="$DOMAIN"
export OWNSWITCH_ADMIN_EMAIL="$EMAIL"
export OWN_SWITCH_ADMIN_PASS="$PASS"
export APP_SRC="$SRC"
export PUBLIC_IP
export NON_INTERACTIVE=1

log "Installing packages…"
apt-get update -qq || true
apt-get install -y git curl ca-certificates rsync openssl mysql-client \
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
[[ -f "${SRC}/public/index.php" ]] || die "public/index.php missing"

# --- stop broken listeners ---
log "Stopping nginx (clear broken listeners)…"
systemctl stop nginx 2>/dev/null || true
# Kill stray nginx masters that leave :80/:443 half-dead
pkill -9 nginx 2>/dev/null || true
sleep 1

# Show who still owns 80/443 (should be empty)
log "Listeners before repair:"
ss -lntp '( sport = :80 or sport = :443 )' || true

# --- self-signed cert (always regenerate if missing/empty) ---
install -d -m 0750 "$TLS_DIR"
CERT="${TLS_DIR}/fullchain.pem"
KEY="${TLS_DIR}/privkey.pem"
if [[ ! -s "$CERT" || ! -s "$KEY" ]]; then
  log "Generating self-signed certificate for ${DOMAIN}…"
  san="DNS:${DOMAIN}"
  [[ -n "$PUBLIC_IP" ]] && san="${san},IP:${PUBLIC_IP}"
  ( umask 077
    openssl req -x509 -newkey rsa:3072 -sha256 -nodes -days 825 \
      -subj "/CN=${DOMAIN}/O=Ownswitch" -addext "subjectAltName=${san}" \
      -keyout "$KEY" -out "$CERT" )
  chmod 0640 "$KEY"; chmod 0644 "$CERT"
fi
openssl x509 -in "$CERT" -noout -subject || die "TLS cert unreadable: ${CERT}"

# --- force stage 80 (PHP pool + nginx vhost) ---
log "Running stage 80 (PHP-FPM + nginx)…"
# Prefer self-signed paths for a known-good bootstrap; LE can be re-run later.
unset TLS_CERT_FILE TLS_KEY_FILE || true
bash "${SRC}/deploy/install.sh" --only 80 --yes --non-interactive

# Ensure enabled site + no default conflict
ln -sfn /etc/nginx/sites-available/ownswitch.conf /etc/nginx/sites-enabled/ownswitch.conf
rm -f /etc/nginx/sites-enabled/default

# If LE paths are configured but missing, fall back to self-signed in the live conf
if grep -q '/etc/letsencrypt/live/' /etc/nginx/sites-available/ownswitch.conf 2>/dev/null; then
  if [[ ! -s "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]]; then
    log "Let's Encrypt files missing — rewriting vhost to self-signed…"
    sed -i -E \
      -e "s|ssl_certificate[[:space:]]+[^;]+;|ssl_certificate     ${CERT};|" \
      -e "s|ssl_certificate_key[[:space:]]+[^;]+;|ssl_certificate_key ${KEY};|" \
      /etc/nginx/sites-available/ownswitch.conf
  fi
fi

nginx -t || {
  log "nginx -t failed — dumping conf head:"
  head -n 80 /etc/nginx/sites-available/ownswitch.conf || true
  die "nginx configuration invalid"
}

systemctl enable nginx php8.3-fpm >/dev/null 2>&1 || true
systemctl restart php8.3-fpm
systemctl restart nginx
sleep 1
systemctl is-active --quiet nginx || { journalctl -u nginx -n 40 --no-pager; die "nginx not active"; }
systemctl is-active --quiet php8.3-fpm || { journalctl -u php8.3-fpm -n 40 --no-pager; die "php8.3-fpm not active"; }

log "Listeners after repair:"
ss -lntp '( sport = :80 or sport = :443 )' || true

# Local smoke (bypass public firewall)
HTTP_CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 8 http://127.0.0.1/ -H "Host: ${DOMAIN}" || true)"
HTTPS_CODE="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 8 https://127.0.0.1/ -H "Host: ${DOMAIN}" || true)"
log "local HTTP  -> ${HTTP_CODE} (expect 301)"
log "local HTTPS -> ${HTTPS_CODE} (expect 200/302/503)"
[[ "$HTTPS_CODE" =~ ^[0-9]{3}$ && "$HTTPS_CODE" != "000" ]] || die "nginx still not answering HTTPS locally"

# --- deploy app + migrations + admin ---
log "Running stage 90 (app deploy)…"
export SKIP_ADMIN=1
bash "${SRC}/deploy/install.sh" --only 90 --yes --non-interactive

CUR="$(readlink -f "${OWN_OPT}/current" 2>/dev/null || true)"
[[ -f "${CUR}/public/index.php" ]] || die "current/public/index.php missing"
[[ -f "$ENV_FILE" ]] || die "${ENV_FILE} missing"

log "Migrations…"
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "${CUR}/bin/migrate.php" \
  || env OWNSWITCH_ENV_FILE="$ENV_FILE" php "${CUR}/bin/migrate.php" \
  || die "migrate failed"

TABLES="$(mysql --protocol=socket -uroot -N -B -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='ownswitch'" || echo 0)"
log "ownswitch tables: ${TABLES}"
[[ "${TABLES}" -gt 5 ]] || die "ownswitch DB still empty"

log "Admin ${EMAIL}…"
printf '%s' "$PASS" | runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" \
  php "${CUR}/bin/create-admin.php" --email="$EMAIL" --password-stdin --reset-password \
  || die "create-admin failed"

install -d -m 0750 -o ownswitch -g ownswitch "${OWN_VAR}/storage/cache" "${OWN_VAR}/storage/uploads" 2>/dev/null || true
chmod 0755 /opt/ownswitch /opt/ownswitch/releases 2>/dev/null || true
chmod 0751 "$CUR" 2>/dev/null || true
chmod -R go+rX "${CUR}/public" 2>/dev/null || true
id www-data >/dev/null 2>&1 && usermod -aG ownswitch www-data 2>/dev/null || true

systemctl restart php8.3-fpm
systemctl reload nginx

# ufw allow web if active
if command -v ufw >/dev/null && ufw status 2>/dev/null | grep -qi 'Status: active'; then
  ufw allow 80/tcp >/dev/null 2>&1 || true
  ufw allow 443/tcp >/dev/null 2>&1 || true
fi

umask 077
cat > /root/ownswitch-credentials.txt <<EOF
Ownswitch admin
URL:      https://${DOMAIN}/login
Email:    ${EMAIL}
Password: ${PASS}
Note:     Browser may warn about self-signed TLS until Let's Encrypt is issued.
Generated: $(date -u -Iseconds)
EOF
chmod 600 /root/ownswitch-credentials.txt

LOGIN_CODE="$(curl -sk -o /tmp/ow-login.html -w '%{http_code}' --max-time 10 https://127.0.0.1/login -H "Host: ${DOMAIN}" || true)"
log "GET /login -> HTTP ${LOGIN_CODE}"
head -c 200 /tmp/ow-login.html; echo

[[ "$LOGIN_CODE" == "200" ]] || {
  tail -n 50 /var/log/nginx/ownswitch.error.log 2>/dev/null || true
  tail -n 50 /var/log/ownswitch/php-fpm-error.log 2>/dev/null || true
  die "login page not 200 (got ${LOGIN_CODE})"
}

echo
echo "OK — portal web stack repaired."
echo "  Open:  https://${DOMAIN}/login"
echo "  Email: ${EMAIL}"
echo "  Pass:  (in /root/ownswitch-credentials.txt)"
echo "  If Firefox still shows a cert warning: accept the temporary self-signed cert, or run stage 80 again after DNS A points here for Let's Encrypt."
