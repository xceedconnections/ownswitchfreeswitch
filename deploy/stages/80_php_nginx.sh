#!/usr/bin/env bash
# Stage 80 - PHP 8.3 FPM (dedicated pool/user) + nginx (TLS front door).
# TLS: self-signed bootstrap certificate; upgraded to Let's Encrypt automatically when
#      LETSENCRYPT_EMAIL is set and OWNSWITCH_DOMAIN resolves to PUBLIC_IP.
#      Provide your own certificate with TLS_CERT_FILE / TLS_KEY_FILE.
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
stage_init "80_php_nginx"

ensure_base_layout

# ---------------------------------------------------------------------------
# 1. Packages
# ---------------------------------------------------------------------------
# Install nginx + PHP first; redis extension is optional (portal works without it).
apt_install "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-mysql" "php${PHP_VERSION}-mbstring" \
            "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-bcmath" \
            "php${PHP_VERSION}-intl" nginx
apt_install_optional "php${PHP_VERSION}-redis" "php${PHP_VERSION}-opcache" composer

php_v="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
[[ "$php_v" == "${PHP_VERSION}" ]] || die "PHP ${PHP_VERSION} expected, CLI reports ${php_v}"
php_mods="$(php -m)"
for ext in pdo_mysql mbstring xml curl zip bcmath intl; do
  grep -qix "$ext" <<<"$php_mods" || die "PHP extension missing: ${ext}"
done
grep -qix redis <<<"$php_mods" || log_warn "php-redis not loaded (optional)"
log_ok "PHP ${php_v} with required extensions"

# ---------------------------------------------------------------------------
# 2. PHP-FPM pool
# ---------------------------------------------------------------------------
ram_mb="$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo)"
children=$(( ram_mb / 256 )); (( children >= 8 )) || children=8; (( children <= 64 )) || children=64
export PHP_FPM_MAX_CHILDREN="$children"
export PHP_VERSION OWN_ETC OWN_VAR OWN_LOG OWN_OPT PHP_FPM_SOCK

pool="/etc/php/${PHP_VERSION}/fpm/pool.d"
if [[ -f "${pool}/www.conf" ]]; then
  mv -f "${pool}/www.conf" "${pool}/www.conf.disabled-by-ownswitch"
  note_change "disabled default PHP-FPM www pool"
fi
render_template "${TEMPLATE_DIR}/php-fpm-pool.conf" "${pool}/ownswitch.conf" 0644 root root
fpm_changed="${RENDER_CHANGED}"
php-fpm"${PHP_VERSION}" -t >/dev/null 2>&1 || { php-fpm"${PHP_VERSION}" -t || true; die "PHP-FPM configuration test failed"; }
svc_apply "php${PHP_VERSION}-fpm" "${fpm_changed}"

# ---------------------------------------------------------------------------
# 3. TLS certificate
# ---------------------------------------------------------------------------
TLS_DIR="/etc/ssl/ownswitch"
install -d -m 0750 -o root -g root "${TLS_DIR}"
install -d -m 0755 /var/www/letsencrypt

export TLS_CERT="${TLS_DIR}/fullchain.pem" TLS_KEY="${TLS_DIR}/privkey.pem"
export HSTS_HEADER='# HSTS disabled (self-signed bootstrap certificate)'

LE_LIVE="/etc/letsencrypt/live/${OWNSWITCH_DOMAIN}"
if [[ -n "${TLS_CERT_FILE:-}" && -n "${TLS_KEY_FILE:-}" ]]; then
  [[ -r "${TLS_CERT_FILE}" && -r "${TLS_KEY_FILE}" ]] || die "TLS_CERT_FILE/TLS_KEY_FILE not readable"
  export TLS_CERT="${TLS_CERT_FILE}" TLS_KEY="${TLS_KEY_FILE}"
  export HSTS_HEADER='add_header Strict-Transport-Security "max-age=31536000" always;'
elif [[ -r "${LE_LIVE}/fullchain.pem" ]]; then
  export TLS_CERT="${LE_LIVE}/fullchain.pem" TLS_KEY="${LE_LIVE}/privkey.pem"
  export HSTS_HEADER='add_header Strict-Transport-Security "max-age=31536000" always;'
elif [[ ! -s "${TLS_CERT}" || ! -s "${TLS_KEY}" ]]; then
  log_info "Generating self-signed bootstrap certificate for ${OWNSWITCH_DOMAIN}"
  san="DNS:${OWNSWITCH_DOMAIN}"; valid_ipv4 "${PUBLIC_IP}" && san="${san},IP:${PUBLIC_IP}"
  ( umask 077
    openssl req -x509 -newkey rsa:3072 -sha256 -nodes -days 825 \
      -subj "/CN=${OWNSWITCH_DOMAIN}/O=Ownswitch" -addext "subjectAltName=${san}" \
      -keyout "${TLS_KEY}" -out "${TLS_CERT}" >/dev/null 2>&1 )
  chmod 0640 "${TLS_KEY}"; chmod 0644 "${TLS_CERT}"
  note_change "generated self-signed certificate ${TLS_CERT}"
fi

# ---------------------------------------------------------------------------
# 4. nginx vhost
# ---------------------------------------------------------------------------
export APP_ROOT="${OWN_OPT}/current"
export OWNSWITCH_DOMAIN INTERNAL_HTTP_ADDR

render_template "${TEMPLATE_DIR}/nginx-ownswitch.conf" /etc/nginx/sites-available/ownswitch.conf 0644 root root
nginx_changed="${RENDER_CHANGED}"
ln -sfn /etc/nginx/sites-available/ownswitch.conf /etc/nginx/sites-enabled/ownswitch.conf
if [[ -e /etc/nginx/sites-enabled/default ]]; then
  rm -f /etc/nginx/sites-enabled/default; nginx_changed=1; note_change "removed nginx default site"
fi

# nginx must be able to resolve the (possibly not yet existing) docroot: create a placeholder
# `current` only if nothing is deployed yet; stage 90 replaces it atomically.
if [[ ! -e "${OWN_OPT}/current" ]]; then
  install -d -m 0755 "${OWN_OPT}/bootstrap/public"
  [[ -f "${OWN_OPT}/bootstrap/public/index.php" ]] || printf '<?php http_response_code(503); echo "Ownswitch: application not deployed yet\\n";\n' >"${OWN_OPT}/bootstrap/public/index.php"
  ln -sfn "${OWN_OPT}/bootstrap" "${OWN_OPT}/current"
fi

nginx -t 2>&1 || die "nginx configuration test failed"
svc_apply nginx "${nginx_changed}"
systemctl reload nginx || true

# ---------------------------------------------------------------------------
# 5. Let's Encrypt (optional, only when it can possibly succeed)
# ---------------------------------------------------------------------------
if [[ -n "${LETSENCRYPT_EMAIL}" && "${TLS_CERT}" == "${TLS_DIR}/fullchain.pem" && -z "${TLS_CERT_FILE:-}" ]]; then
  resolved="$(getent ahostsv4 "${OWNSWITCH_DOMAIN}" 2>/dev/null | awk 'NR==1{print $1}' || true)"
  if [[ "${OWNSWITCH_DOMAIN}" != *.* || "${resolved}" != "${PUBLIC_IP}" ]]; then
    log_warn "Skipping Let's Encrypt: ${OWNSWITCH_DOMAIN} does not resolve to ${PUBLIC_IP} (resolved: ${resolved:-none})"
  else
    apt_install certbot
    if certbot certonly --webroot -w /var/www/letsencrypt -d "${OWNSWITCH_DOMAIN}" \
         --non-interactive --agree-tos -m "${LETSENCRYPT_EMAIL}" --keep-until-expiring; then
      install -d /etc/letsencrypt/renewal-hooks/deploy
      printf '#!/bin/sh\nsystemctl reload nginx\n' >/etc/letsencrypt/renewal-hooks/deploy/ownswitch-nginx
      chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/ownswitch-nginx
      export TLS_CERT="${LE_LIVE}/fullchain.pem" TLS_KEY="${LE_LIVE}/privkey.pem"
      export HSTS_HEADER='add_header Strict-Transport-Security "max-age=31536000" always;'
      render_template "${TEMPLATE_DIR}/nginx-ownswitch.conf" /etc/nginx/sites-available/ownswitch.conf 0644 root root
      nginx -t 2>&1 && systemctl reload nginx
      note_change "Let's Encrypt certificate installed for ${OWNSWITCH_DOMAIN}"
    else
      log_warn "certbot failed - keeping the self-signed certificate. Re-run stage 80 after fixing DNS/port 80."
    fi
  fi
fi

# ---------------------------------------------------------------------------
# 6. Smoke test
# ---------------------------------------------------------------------------
code="$(curl -ks -o /dev/null -w '%{http_code}' --max-time 10 --resolve "${OWNSWITCH_DOMAIN}:443:127.0.0.1" "https://${OWNSWITCH_DOMAIN}/" || true)"
log_info "GET https://${OWNSWITCH_DOMAIN}/ -> HTTP ${code}"
[[ "$code" =~ ^[0-9]{3}$ && "$code" != "000" ]] || die "nginx did not answer on 443"
log_ok "PHP ${php_v}-FPM + nginx ready"
