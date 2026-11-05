#!/usr/bin/env bash
# Pull latest Ownswitch UI into a new release and switch traffic.
# Usage (on the server as root):
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/refresh-ui.sh | sudo bash
# Or:
#   sudo bash /opt/ownswitch-src/deploy/refresh-ui.sh
set -euo pipefail

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_OPT="${OWN_OPT:-/opt/ownswitch}"
OWN_VAR="${OWN_VAR:-/var/lib/ownswitch}"
OWN_ETC="${OWN_ETC:-/etc/ownswitch}"
REPO_URL="${OWN_REPO_URL:-https://github.com/xceedconnections/ownswitchfreeswitch.git}"
BRANCH="${OWN_BRANCH:-main}"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0" >&2
  exit 1
fi

echo "== Ownswitch UI refresh =="
echo "SRC=$SRC  current=$(readlink -f "$OWN_OPT/current" 2>/dev/null || echo missing)"

if [[ ! -d "$SRC/.git" ]]; then
  echo "Cloning $REPO_URL → $SRC"
  install -d -m 0755 "$(dirname "$SRC")"
  git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$SRC"
else
  echo "Pulling $BRANCH…"
  git -C "$SRC" fetch origin "$BRANCH"
  git -C "$SRC" checkout "$BRANCH"
  git -C "$SRC" reset --hard "origin/$BRANCH"
fi

test -f "$SRC/public/index.php" || { echo "FATAL: $SRC/public/index.php missing"; exit 1; }
test -f "$SRC/public/assets/css/app.css" || { echo "FATAL: $SRC/public/assets/css/app.css missing"; exit 1; }
test -f "$SRC/app/Views/layout.php" || { echo "FATAL: layout.php missing"; exit 1; }

if ! grep -q 'os-auth-page\|os-topbar\|os-brand-mark' "$SRC/app/Views/layout.php"; then
  echo "FATAL: pulled layout.php does not contain the new CRM markup — wrong commit?"
  exit 1
fi
if ! grep -q 'MISSION CONTROL\|mc-pause-banner\|Calling paused' "$SRC/app/Views/dashboard/customer.php"; then
  echo "FATAL: customer dashboard missing Mission Control / Calling paused markup — wrong commit?"
  exit 1
fi
if grep -q 'usage, balance & call quality' "$SRC/app/Views/dashboard/customer.php"; then
  echo "FATAL: old customer dashboard still present (usage, balance & call quality) — pull failed?"
  exit 1
fi

HEAD="$(git -C "$SRC" rev-parse --short HEAD)"
echo "Source OK at $HEAD (Mission Control dashboard present)"

getent passwd ownswitch >/dev/null 2>&1 || useradd --system --home-dir "$OWN_OPT" --shell /usr/sbin/nologin --user-group ownswitch
install -d -m 0755 "$OWN_OPT/releases"
install -d -m 0750 -o ownswitch -g ownswitch "$OWN_VAR/storage/cache" "$OWN_VAR/storage/uploads" 2>/dev/null || true

REL="$OWN_OPT/releases/ui-$(date -u +%Y%m%d%H%M%S)-${HEAD}"
mkdir -p "$REL"
rsync -a --delete \
  --exclude '.git/' --exclude '.github/' --exclude 'deploy/' --exclude 'docs/' --exclude 'tests/' \
  --exclude 'node_modules/' --exclude '.env' --exclude '.env.*' --exclude '*.md' \
  --exclude '/storage/' --exclude '/var/' \
  "$SRC/" "$REL/"

test -f "$REL/public/index.php"
test -f "$REL/public/assets/css/app.css"
test -f "$REL/app/Views/layout.php"
rm -f "$REL/.ownswitch-placeholder"
ln -sfn "$OWN_VAR/storage" "$REL/storage"
chown -R root:ownswitch "$REL"
# 0751 on the release root: other needs +x to traverse into public (nginx runs as www-data).
# Do NOT chmod -R o= on $REL itself — that causes static asset 404s.
chmod 0751 "$REL"
find "$REL" -mindepth 1 -maxdepth 1 ! -name public ! -name storage -exec chmod -R u=rwX,g=rX,o= {} +
chmod -R u=rwX,go=rX "$REL/public"
chmod 0755 /opt/ownswitch /opt/ownswitch/releases 2>/dev/null || true
# Ensure nginx can read group-owned trees even if a future chmod tightens other.
if id www-data >/dev/null 2>&1; then
  usermod -aG ownswitch www-data 2>/dev/null || true
fi

ln -sfn "$REL" "$OWN_OPT/current"
echo "Switched current → $REL"

# Detect PHP-FPM unit (8.3 preferred)
php_fpm_unit() {
  for s in php8.3-fpm php8.2-fpm php8.1-fpm php-fpm; do
    if systemctl cat "${s}.service" >/dev/null 2>&1; then
      echo "$s"
      return 0
    fi
  done
  echo "php8.3-fpm"
}
PHP_FPM_SVC="$(php_fpm_unit)"

# Keep nginx document root on /opt/ownswitch/current/public
NGINX_CONF="/etc/nginx/sites-available/ownswitch.conf"
if [[ -f "$NGINX_CONF" ]]; then
  if grep -qE 'root .+/public;' "$NGINX_CONF"; then
    sed -i -E 's#root[[:space:]]+[^;]+/public;#root /opt/ownswitch/current/public;#g' "$NGINX_CONF"
    echo "Nginx root → /opt/ownswitch/current/public"
  fi
  if nginx -t; then
    systemctl reload nginx || systemctl restart nginx || true
  else
    echo "WARN: nginx -t failed — leaving nginx running; fix config then: systemctl reload nginx" >&2
  fi
else
  echo "WARN: $NGINX_CONF missing — skip nginx rewrite"
fi

# Hard restart FPM so Update-now never leaves a dead pool (reload can no-op while broken)
echo "Restarting ${PHP_FPM_SVC}…"
systemctl restart "$PHP_FPM_SVC" || systemctl restart php8.3-fpm || systemctl restart php8.2-fpm || true
sleep 1
if ! systemctl is-active --quiet "$PHP_FPM_SVC" 2>/dev/null && ! systemctl is-active --quiet php8.3-fpm 2>/dev/null; then
  echo "WARN: PHP-FPM not active after restart — portal may 502. Run: sudo bash $SRC/deploy/fix-portal-502.sh" >&2
fi

# Persist APP_SRC
if [[ -f "$OWN_ETC/install.conf" ]]; then
  grep -v '^APP_SRC=' "$OWN_ETC/install.conf" > /tmp/ownswitch-ic.$$ || true
  echo "APP_SRC=$SRC" >> /tmp/ownswitch-ic.$$
  mv /tmp/ownswitch-ic.$$ "$OWN_ETC/install.conf"
fi

# Keep fail2ban helper + SIP/portal jails in sync
if [[ -f "$SRC/deploy/templates/fail2ban/ownswitch-fail2ban.sh" ]]; then
  install -m 0755 "$SRC/deploy/templates/fail2ban/ownswitch-fail2ban.sh" /usr/local/sbin/ownswitch-fail2ban
  if [[ -f "$SRC/deploy/templates/fail2ban/ownswitch-fail2ban.sudoers" ]]; then
    install -m 0440 "$SRC/deploy/templates/fail2ban/ownswitch-fail2ban.sudoers" /etc/sudoers.d/ownswitch-fail2ban
    visudo -cf /etc/sudoers.d/ownswitch-fail2ban >/dev/null 2>&1 || true
  fi
fi
# IP-based SIP peer firewall (firewalld or ufw)
if [[ -f "$SRC/deploy/templates/firewall/ownswitch-sip-peer-fw.sh" ]]; then
  install -m 0755 "$SRC/deploy/templates/firewall/ownswitch-sip-peer-fw.sh" /usr/local/sbin/ownswitch-sip-peer-fw
  if [[ -f "$SRC/deploy/templates/firewall/ownswitch-sip-peer-fw.sudoers" ]]; then
    install -m 0440 "$SRC/deploy/templates/firewall/ownswitch-sip-peer-fw.sudoers" /etc/sudoers.d/ownswitch-sip-peer-fw
    visudo -cf /etc/sudoers.d/ownswitch-sip-peer-fw >/dev/null 2>&1 || true
  fi
  mkdir -p /var/lib/ownswitch/sip-peer-fw
  chmod 0750 /var/lib/ownswitch/sip-peer-fw 2>/dev/null || true
fi
# Portal “Version → Update now” (helper + sudoers, always refresh)
if [[ -f "$SRC/deploy/install-update-helper.sh" ]]; then
  bash "$SRC/deploy/install-update-helper.sh" >/dev/null 2>&1 || true
elif [[ -f "$SRC/deploy/templates/update/ownswitch-update.sh" ]]; then
  install -m 0755 "$SRC/deploy/templates/update/ownswitch-update.sh" /usr/local/sbin/ownswitch-update
  if [[ -f "$SRC/deploy/templates/update/ownswitch-update.sudoers" ]]; then
    install -m 0440 "$SRC/deploy/templates/update/ownswitch-update.sudoers" /etc/sudoers.d/ownswitch-update
    visudo -cf /etc/sudoers.d/ownswitch-update >/dev/null 2>&1 || true
  fi
fi
if [[ -f "$SRC/deploy/templates/fail2ban/ownswitch-portal.conf" ]]; then
  install -m 0644 "$SRC/deploy/templates/fail2ban/ownswitch-portal.conf" /etc/fail2ban/filter.d/ownswitch-portal.conf
fi
if [[ -f "$SRC/deploy/templates/fail2ban/opensips-auth.conf" ]]; then
  install -m 0644 "$SRC/deploy/templates/fail2ban/opensips-auth.conf" /etc/fail2ban/filter.d/opensips-auth.conf
fi
if [[ -f "$SRC/deploy/templates/fail2ban/ownswitch-portal.local" && ! -f /etc/fail2ban/jail.d/ownswitch-portal.local ]]; then
  install -m 0644 "$SRC/deploy/templates/fail2ban/ownswitch-portal.local" /etc/fail2ban/jail.d/ownswitch-portal.local
fi
if command -v fail2ban-client >/dev/null 2>&1 && systemctl is-active --quiet fail2ban 2>/dev/null; then
  fail2ban-client reload >/dev/null 2>&1 || systemctl reload fail2ban >/dev/null 2>&1 || true
fi

echo
echo "== Verify =="
echo "layout markers: $(grep -c 'os-auth-page\|os-topbar\|os-brand-mark' "$OWN_OPT/current/app/Views/layout.php" || true)"
echo "mission-control: $(grep -c 'MISSION CONTROL\|mc-pause-banner' "$OWN_OPT/current/app/Views/dashboard/customer.php" || true)"
echo "mc-css: $(grep -c 'mc-pause-banner\|mc-low-banner' "$OWN_OPT/current/public/assets/css/app.css" || true)"
echo "css bytes: $(wc -c < "$OWN_OPT/current/public/assets/css/app.css")"
ls -la "$OWN_OPT/current/public/assets/css/app.css" "$OWN_OPT/current/public/assets/js/app.js"
if ! grep -q 'MISSION CONTROL\|mc-pause-banner' "$OWN_OPT/current/app/Views/dashboard/customer.php"; then
  echo "FATAL: deployed current/ still has old customer dashboard" >&2
  exit 1
fi
# Apply DB migrations (must succeed for new modules e.g. DIDs)
if [[ -f "$REL/bin/migrate.php" ]]; then
  echo "Running migrations…"
  if runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="${OWN_ETC}/ownswitch.env" php "$REL/bin/migrate.php"; then
    echo "Migrations OK"
  elif env OWNSWITCH_ENV_FILE="${OWN_ETC}/ownswitch.env" php "$REL/bin/migrate.php"; then
    echo "Migrations OK (root)"
  else
    echo "FATAL: migrations failed — DID and other new tables will 500 until this succeeds" >&2
    exit 1
  fi
fi

# Keep Sofia gateway include path correct without restarting the profile (no REGISTER flap).
# Profile XML includes are relative to sip_profiles/ → external/*.xml
FS_CONF="${FREESWITCH_CONF:-/usr/local/freeswitch/conf}"
EXT_XML="${FS_CONF}/sip_profiles/external.xml"
GOOD_INC='<X-PRE-PROCESS cmd="include" data="external/*.xml"/>'
if [[ -f "${EXT_XML}" ]]; then
  if ! grep -q 'data="external/\*\.xml"' "${EXT_XML}" \
    || grep -qE 'data="(sip_profiles/external/\*\.xml|/usr/local/freeswitch/conf/sip_profiles/external/\*\.xml)"' "${EXT_XML}"; then
    cp -a "${EXT_XML}" "${EXT_XML}.bak.ui.$(date +%s)" 2>/dev/null || true
    sed -i '/X-PRE-PROCESS cmd="include".*external\/\*\.xml/d' "${EXT_XML}" || true
    if grep -q '<gateways>' "${EXT_XML}"; then
      sed -i "s#<gateways>#<gateways>\n    ${GOOD_INC}#" "${EXT_XML}" || true
      echo "Patched Sofia gateway include → external/*.xml"
    fi
  fi
fi
# Warn if directory auth module is missing (SIP REGISTER will Forbidden).
if [[ ! -f /usr/local/freeswitch/lib/freeswitch/mod/mod_xml_curl.so ]]; then
  echo "WARN: mod_xml_curl.so missing — SIP REGISTER will fail. Run: sudo bash $SRC/deploy/fix-fs-xml-curl.sh" >&2
fi

# Final service health (after helpers / migrations)
systemctl restart "$PHP_FPM_SVC" 2>/dev/null || systemctl restart php8.3-fpm 2>/dev/null || true
systemctl reload nginx 2>/dev/null || systemctl restart nginx 2>/dev/null || true

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "$OWN_ETC/install.conf" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
echo
echo "Hard-refresh the browser (Ctrl+Shift+R)."
echo "Checks:"
echo "  curl -k -I https://${DOMAIN}/assets/css/app.css"
echo "  curl -k -s https://${DOMAIN}/login | grep -E 'os-auth-page|bootstrap-icons|os-brand-mark'"
echo "  systemctl is-active nginx ${PHP_FPM_SVC}"
echo "Done ($HEAD)."
if ! systemctl is-active --quiet nginx || { ! systemctl is-active --quiet "$PHP_FPM_SVC" 2>/dev/null && ! systemctl is-active --quiet php8.3-fpm 2>/dev/null; }; then
  echo "WARN: services unhealthy — run: sudo bash $SRC/deploy/fix-portal-502.sh" >&2
fi
