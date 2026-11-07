#!/usr/bin/env bash
# Portal Settings → SSL. Install a Let's Encrypt certificate for one domain.
#   ownswitch-ssl status
#   ownswitch-ssl install EMAIL DOMAIN
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "message=Run as root"; exit 1; }

CONF="/etc/ownswitch/ssl.conf"
NGINX="/etc/nginx/sites-available/ownswitch.conf"
WEBROOT="/var/www/letsencrypt"

read_conf() {
  local key="$1"
  [[ -f "$CONF" ]] || return 0
  grep -E "^${key}=" "$CONF" | head -1 | cut -d= -f2- || true
}

cert_path() {
  local domain="$1"
  local from_nginx=""
  if [[ -f "$NGINX" ]]; then
    from_nginx="$(grep -E '^\s*ssl_certificate\s+' "$NGINX" | head -1 | awk '{print $2}' | tr -d ';' || true)"
  fi
  if [[ -n "$from_nginx" && -s "$from_nginx" ]]; then
    printf '%s\n' "$from_nginx"
    return 0
  fi
  if [[ -n "$domain" && -s "/etc/letsencrypt/live/${domain}/fullchain.pem" ]]; then
    printf '%s\n' "/etc/letsencrypt/live/${domain}/fullchain.pem"
    return 0
  fi
  printf '\n'
}

emit_status() {
  local domain email cert
  domain="$(read_conf SSL_DOMAIN)"
  email="$(read_conf SSL_EMAIL)"
  cert="$(cert_path "$domain")"
  echo "ok=1"
  echo "domain=${domain}"
  echo "email=${email}"
  if [[ -z "$cert" ]]; then
    echo "installed=0"
    echo "not_after="
    echo "issuer="
    echo "subject="
    return 0
  fi
  echo "installed=1"
  echo "cert=${cert}"
  local end issuer subject
  end="$(openssl x509 -in "$cert" -noout -enddate 2>/dev/null | cut -d= -f2- || true)"
  if [[ -n "$end" ]]; then
    date -u -d "$end" +'not_after=%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || echo "not_after=${end}"
  else
    echo "not_after="
  fi
  issuer="$(openssl x509 -in "$cert" -noout -issuer 2>/dev/null | sed 's/^issuer=//' || true)"
  subject="$(openssl x509 -in "$cert" -noout -subject 2>/dev/null | sed 's/^subject=//' || true)"
  echo "issuer=${issuer}"
  echo "subject=${subject}"
}

valid_email() { [[ "$1" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]]; }
valid_domain() { [[ "$1" =~ ^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$ ]]; }

point_nginx() {
  local cert="$1" key="$2"
  [[ -f "$NGINX" ]] || { echo "message=Nginx site is missing"; return 1; }
  cp -a "$NGINX" "${NGINX}.bak.ssl.$(date +%s)"
  sed -i -E "s#^[[:space:]]*ssl_certificate[[:space:]]+[^;]+;#    ssl_certificate     ${cert};#" "$NGINX"
  sed -i -E "s#^[[:space:]]*ssl_certificate_key[[:space:]]+[^;]+;#    ssl_certificate_key ${key};#" "$NGINX"
  if ! nginx -t; then
    echo "message=nginx -t failed, previous site config restored"
    local bak
    bak="$(ls -1t "${NGINX}.bak.ssl."* 2>/dev/null | head -1 || true)"
    [[ -n "$bak" ]] && cp -a "$bak" "$NGINX"
    return 1
  fi
  systemctl reload nginx
}

cmd="${1:-status}"
case "$cmd" in
  status)
    emit_status
    ;;
  install)
    email="${2:-}"
    domain="${3:-}"
    valid_email "$email" || { echo "message=Enter a valid email address"; exit 1; }
    valid_domain "$domain" || { echo "message=Enter the domain that points at this server"; exit 1; }
    install -d -m 0755 "$WEBROOT" /etc/ownswitch
    if ! command -v certbot >/dev/null 2>&1; then
      apt-get update -qq
      DEBIAN_FRONTEND=noninteractive apt-get install -y certbot
    fi
    if ! certbot certonly --webroot -w "$WEBROOT" -d "$domain" \
        --non-interactive --agree-tos -m "$email" --keep-until-expiring; then
      echo "message=Let's Encrypt did not issue a certificate. Confirm the domain points at this server and port 80 is open."
      exit 1
    fi
    live="/etc/letsencrypt/live/${domain}"
    point_nginx "${live}/fullchain.pem" "${live}/privkey.pem"
    install -d /etc/letsencrypt/renewal-hooks/deploy
    cat >/etc/letsencrypt/renewal-hooks/deploy/ownswitch-nginx <<'EOF'
#!/bin/sh
systemctl reload nginx
EOF
    chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/ownswitch-nginx
    umask 027
    cat >"$CONF" <<EOF
SSL_EMAIL=${email}
SSL_DOMAIN=${domain}
EOF
    chmod 0640 "$CONF"
    chown root:ownswitch "$CONF" 2>/dev/null || true
    echo "message=Certificate installed for ${domain}"
    emit_status
    ;;
  *)
    echo "message=Unknown command"
    exit 1
    ;;
esac
