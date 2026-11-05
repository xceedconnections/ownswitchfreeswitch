#!/usr/bin/env bash
# 1) Portal "offline" while softphone is registered → deploy expires=INT fix
# 2) Calls "user not registered" → enable drouting outbound
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-portal-reg-and-calling.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
ENV_FILE=/etc/ownswitch/ownswitch.env
cd "$SRC"
git fetch origin main
git reset --hard origin/main

echo "======== A) Deploy portal registration status fix ========"
bash "$SRC/deploy/refresh-ui.sh"

echo "======== B) location table (what portal reads) ========"
mysql --protocol=socket -uroot opensips -e \
  "SELECT username, domain, LEFT(contact,60) contact, received, expires, FROM_UNIXTIME(expires) AS exp_human FROM location LIMIT 20;" \
  2>/dev/null || mysql --protocol=socket -uroot opensips -e "SELECT * FROM location LIMIT 5;" || true

echo "======== C) OpenSIPS cfg: REGISTER + drouting? ========"
grep -nE 'www_authorize|do_routing|User not registered|sethost' "$CFG" | head -n 30 || true

if ! grep -q 'do_routing' "$CFG" || grep -q 'User not registered' "$CFG"; then
  echo "Outbound missing or User-not-registered dead-end — force patch..."
  bash "$SRC/deploy/force-outbound-calls-now.sh"
fi

echo "======== D) Sync trunks ========"
CUR="$(readlink -f /opt/ownswitch/current)"
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-trunks.php" || true
mysql --protocol=socket -uroot opensips -e \
  "SELECT COUNT(*) AS gateways FROM dr_gateways; SELECT COUNT(*) AS rules FROM dr_rules;" 2>/dev/null || \
  echo "WARN: no drouting tables — outbound will 503/404"

echo "======== E) Portal diagnose ========"
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/diagnose-sip-portal.php" || true

echo
echo "################################################################"
echo " Softphone: re-register once, hard-refresh portal (Ctrl+Shift+R)."
echo " Portal should show REGISTERED if location has a row above."
echo " Calls need gateways>0 (portal Trunks+Routes). Dial PSTN digits."
echo "################################################################"
