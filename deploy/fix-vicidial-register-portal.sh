#!/usr/bin/env bash
# Once-and-for-all: portal Live status for Dynamic REGISTER (Eyebeam + Asterisk/ViciDial).
# - Deploys UI that never OPTIONS-purges usrloc
# - Unbans fail2ban / clears blocked peers
# - Syncs subscriber passwords
# - Shows opensips.location so you can prove REGISTER hit THIS OpenSIPS
#
# Usage:
#   sudo bash /opt/ownswitch-src/deploy/fix-vicidial-register-portal.sh [username]
set -Eeuo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
USER="${1:-}"
OWN_OPT="${OWN_OPT:-/opt/ownswitch}"

echo "======== Fix ViciDial / Dynamic REGISTER → portal Live ========"

# 1) Latest code + UI release
if [[ -f "$SRC/deploy/refresh-ui.sh" ]]; then
  bash "$SRC/deploy/refresh-ui.sh"
else
  echo "WARN: refresh-ui.sh missing — git pull first"
fi

CUR="$(readlink -f "$OWN_OPT/current" 2>/dev/null || true)"
if [[ -z "$CUR" || ! -f "$CUR/app/Controllers/SipUserController.php" ]]; then
  echo "FATAL: /opt/ownswitch/current missing SipUserController" >&2
  exit 1
fi

if grep -q 'purgeRegistration' "$CUR/app/Controllers/SipUserController.php" \
  && grep -A20 'if (\$reg !== null)' "$CUR/app/Controllers/SipUserController.php" | grep -q 'purgeRegistration'; then
  echo "FATAL: deployed SipUserController still OPTIONS-purges registrations — wrong release" >&2
  exit 1
fi
if ! grep -q 'Do NOT purge\|Never purge usrloc\|never purge' "$CUR/app/Controllers/SipUserController.php"; then
  # soft check — new wording
  if grep -q 'Asterisk/ViciDial' "$CUR/app/Controllers/SipUserController.php"; then
    echo "OK: portal no longer purges on OPTIONS fail"
  else
    echo "WARN: could not confirm anti-purge marker — continue"
  fi
else
  echo "OK: portal no longer purges on OPTIONS fail"
fi

# 2) Unban everyone (wrong password earlier)
if command -v fail2ban-client >/dev/null 2>&1; then
  fail2ban-client unban --all >/dev/null 2>&1 || true
  echo "OK: fail2ban unban --all"
fi

# 3) Patch live opensips.cfg REGISTER authorize to empty realm (Asterisk-friendly)
CFG="/etc/opensips/opensips.cfg"
if [[ -f "$CFG" ]]; then
  if grep -q 'www_authorize("\$td", "subscriber")' "$CFG" || grep -q 'www_authorize("$td", "subscriber")' "$CFG"; then
    BAK="${CFG}.bak-reg-realm-$(date +%Y%m%d%H%M%S)"
    cp -a "$CFG" "$BAK"
    sed -i 's/www_authorize("\$td", "subscriber")/www_authorize("", "subscriber")/g' "$CFG"
    sed -i 's/www_authorize("$td", "subscriber")/www_authorize("", "subscriber")/g' "$CFG"
    sed -i 's/www_challenge("\$td", "auth")/www_challenge("", "auth")/g' "$CFG"
    sed -i 's/www_challenge("$td", "auth")/www_challenge("", "auth")/g' "$CFG"
    if opensips -c -f "$CFG"; then
      systemctl restart opensips
      echo "OK: REGISTER authorize empty-realm + opensips restarted (bak $BAK)"
    else
      cp -a "$BAK" "$CFG"
      echo "WARN: opensips -c failed — restored REGISTER auth bak" >&2
    fi
  else
    echo "OK: REGISTER auth already empty-realm or custom"
  fi
fi

# 4) Re-push SIP users (passwords → subscriber)
if [[ -f "$CUR/bin/sync-opensips-sip-users.php" ]]; then
  sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
    php "$CUR/bin/sync-opensips-sip-users.php" || true
  echo "OK: sync-opensips-sip-users"
fi

echo
echo "== subscriber (digest) =="
mysql -Nuroot opensips -e \
  "SELECT username, domain, IF(password='' OR password IS NULL,'EMPTY','set') AS pw, allow_calls FROM subscriber ORDER BY username" \
  2>/dev/null || true

echo
echo "== location (LIVE registrations on THIS OpenSIPS) =="
mysql -Nuroot opensips -e \
  "SELECT username, contact, received, expires, FROM_UNIXTIME(expires) AS exp_utc,
          IF(expires > UNIX_TIMESTAMP(),'LIVE','EXPIRED') AS state
   FROM location ORDER BY username" 2>/dev/null || true

if [[ -n "$USER" ]]; then
  echo
  echo "== focus user=$USER =="
  mysql -Nuroot opensips -e \
    "SELECT username, contact, received, IF(expires > UNIX_TIMESTAMP(),'LIVE','EXPIRED') AS state
     FROM location WHERE username='${USER}'" 2>/dev/null || true
  journalctl -u opensips -n 200 --no-pager 2>/dev/null \
    | grep -iE "REGISTER|${USER}|auth failed" | tail -n 25 || true
fi

echo
echo "======== Next on ViciDial / Asterisk ========"
echo "1) Confirm trunk Host = THIS server public IP (or SIP domain)"
echo "2) Username/Secret = portal SIP user (re-save password in portal if unsure)"
echo "3) asterisk -rx 'sip reload'   (or pjsip reload)"
echo "4) asterisk -rx 'sip show registry'   — wait until Registered"
echo "5) Hard-refresh portal SIP Users (Ctrl+Shift+R)"
echo
echo "If location has no LIVE row for the user, Asterisk is NOT registered here"
echo "(wrong host, still banned elsewhere, or auth failing) — portal cannot invent it."
echo
echo "Prove portal view:"
echo "  sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \\"
echo "    php $CUR/bin/diagnose-sip-portal.php"
