#!/usr/bin/env bash
# Fix nginx 404 on /assets/* when the release dir has no other-execute bit.
# Run: sudo bash /opt/ownswitch-src/deploy/fix-assets-perms.sh
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

OWN_OPT="${OWN_OPT:-/opt/ownswitch}"
CUR="$(readlink -f "$OWN_OPT/current")"
[[ -d "$CUR/public" ]] || { echo "Missing $CUR/public"; exit 1; }

chmod 0755 "$OWN_OPT" "$OWN_OPT/releases" 2>/dev/null || true
chmod 0751 "$CUR"
chmod -R u=rwX,go=rX "$CUR/public"
if id www-data >/dev/null 2>&1; then
  usermod -aG ownswitch www-data 2>/dev/null || true
fi

echo "Fixed permissions on $CUR/public"
namei -l "$CUR/public/assets/css/app.css" || ls -la "$CUR/public/assets/css/app.css"
# www-data may need a full nginx restart to pick up new supplementary groups
systemctl restart nginx
echo "nginx restarted"
curl -k -sI "https://127.0.0.1/assets/css/app.css" -H "Host: ${OWNSWITCH_DOMAIN:-voip.xceedconnections.com}" | head -n 5 || true
