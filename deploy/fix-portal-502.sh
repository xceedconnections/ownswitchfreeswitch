#!/usr/bin/env bash
# Recover Ownswitch portal from nginx 502 / PHP-FPM down after Update now.
# Usage: sudo bash /opt/ownswitch-src/deploy/fix-portal-502.sh
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Run as root"; exit 1; }

OWN_OPT="${OWN_OPT:-/opt/ownswitch}"
SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
CUR="$(readlink -f "$OWN_OPT/current" 2>/dev/null || true)"

echo "== Fix portal 502 =="
echo "current=$CUR"

# Ensure current points somewhere valid
if [[ -z "$CUR" || ! -f "$CUR/public/index.php" ]]; then
  echo "WARN: /opt/ownswitch/current broken — pointing at latest release"
  LATEST="$(ls -1dt "$OWN_OPT"/releases/ui-* 2>/dev/null | head -1 || true)"
  if [[ -n "$LATEST" && -f "$LATEST/public/index.php" ]]; then
    ln -sfn "$LATEST" "$OWN_OPT/current"
    CUR="$(readlink -f "$OWN_OPT/current")"
    echo "Switched current → $CUR"
  else
    echo "FATAL: no valid release; run: sudo bash $SRC/deploy/refresh-ui.sh"
    exit 1
  fi
fi

# Permissions nginx needs to traverse into public
chmod 0755 /opt/ownswitch /opt/ownswitch/releases 2>/dev/null || true
chmod 0751 "$CUR" 2>/dev/null || true
chmod -R go+rX "$CUR/public" 2>/dev/null || true
if id www-data >/dev/null 2>&1; then
  usermod -aG ownswitch www-data 2>/dev/null || true
fi

# Nginx root
NGINX_CONF="/etc/nginx/sites-available/ownswitch.conf"
if [[ -f "$NGINX_CONF" ]]; then
  sed -i -E 's#root[[:space:]]+[^;]+/public;#root /opt/ownswitch/current/public;#g' "$NGINX_CONF" || true
fi

php_svc() {
  for s in php8.3-fpm php8.2-fpm php8.1-fpm php-fpm; do
    if systemctl list-unit-files "${s}.service" 2>/dev/null | grep -q "$s"; then
      echo "$s"
      return 0
    fi
    if systemctl status "$s" >/dev/null 2>&1; then
      echo "$s"
      return 0
    fi
  done
  # fallback detect by socket
  if [[ -S /run/php/php8.3-fpm.sock ]]; then echo php8.3-fpm; return 0; fi
  if [[ -S /run/php/php8.2-fpm.sock ]]; then echo php8.2-fpm; return 0; fi
  echo php8.3-fpm
}

PHP_SVC="$(php_svc)"
echo "PHP-FPM service: $PHP_SVC"

systemctl restart "$PHP_SVC"
systemctl is-active --quiet "$PHP_SVC" || { echo "FATAL: $PHP_SVC failed to start"; systemctl status "$PHP_SVC" --no-pager || true; exit 1; }

nginx -t
systemctl reload nginx || systemctl restart nginx
systemctl is-active --quiet nginx || systemctl restart nginx

echo
echo "== Status =="
systemctl is-active nginx "$PHP_SVC" || true
curl -k -sI -o /dev/null -w "HTTPS /login HTTP %{http_code}\n" "https://127.0.0.1/login" -H "Host: voip.xceedconnections.com" 2>/dev/null \
  || curl -sI -o /dev/null -w "HTTP /login %{http_code}\n" "http://127.0.0.1/login" -H "Host: voip.xceedconnections.com" 2>/dev/null \
  || true
echo
echo "If still 502, check:"
echo "  journalctl -u $PHP_SVC -n 40 --no-pager"
echo "  tail -n 40 /var/log/nginx/ownswitch.error.log"
echo "Done."
