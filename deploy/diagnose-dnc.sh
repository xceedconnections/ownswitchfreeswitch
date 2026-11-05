#!/usr/bin/env bash
# Diagnose why a DNC number still connects. Run on the SIP server as root.
# Usage:
#   sudo bash /opt/ownswitch-src/deploy/diagnose-dnc.sh [number] [group_name_or_id]
# Example:
#   sudo bash /opt/ownswitch-src/deploy/diagnose-dnc.sh 13234462244 test
set -Eeuo pipefail

NUM_RAW="${1:-13234462244}"
GROUP_Q="${2:-}"
NUM="$(echo "$NUM_RAW" | tr -cd '0-9')"
CFG="${OWNSWITCH_OPENSIPS_CFG:-/etc/opensips/opensips.cfg}"
ENVF="${OWNSWITCH_ENV_FILE:-/etc/ownswitch/ownswitch.env}"

echo "======== DNC diagnose ========"
echo "number=$NUM  group_query=${GROUP_Q:-'(all)'}  cfg=$CFG"
echo

echo "---- 1) OpenSIPS process / DNC routes in cfg ----"
systemctl is-active opensips || echo "WARN: opensips not active"
if [[ -f "$CFG" ]]; then
  echo "check_sip_dnc calls: $(grep -c 'route(check_sip_dnc)' "$CFG" || true)"
  echo "check_dnc calls:     $(grep -c 'route(check_dnc)' "$CFG" || true)"
  echo "route[check_sip_dnc] present: $(grep -c '^route\[check_sip_dnc\]' "$CFG" || true)"
  echo "route[check_dnc] present:     $(grep -c '^route\[check_dnc\]' "$CFG" || true)"
  if grep -q '^route\[check_dnc\]' "$CFG"; then
    echo "--- route[check_dnc] body (first 40 lines) ---"
    awk '/^route\[check_dnc\]/{p=1} p{print; if(/^}/){c++; if(c>=1 && p){exit}}}' "$CFG" | head -n 40
    if grep -A5 '^route\[check_dnc\]' "$CFG" | grep -q '^\s*return;\s*$'; then
      # stub if ONLY return in first few lines
      BODY="$(awk '/^route\[check_dnc\]/{p=1;next} p{if(/^}/)exit; print}' "$CFG")"
      if echo "$BODY" | grep -qvE '^\s*(return;|#|$)'; then
        echo "route[check_dnc]: has logic (not empty stub)"
      else
        echo "PROBLEM: route[check_dnc] looks like an empty STUB (only return) — DNC never blocks"
      fi
    fi
  else
    echo "PROBLEM: route[check_dnc] MISSING from $CFG — run fix-opensips-dnc.sh"
  fi
  # Is check_dnc reached before t_relay on outbound?
  if ! grep -q 'route(check_dnc)' "$CFG"; then
    echo "PROBLEM: no route(check_dnc); call site — trunk DNC never runs"
  fi
  echo "cachedb_redis loaded: $(grep -c 'loadmodule \"cachedb_redis' "$CFG" || true)"
  echo "sqlops loaded:        $(grep -c 'loadmodule \"sqlops' "$CFG" || true)"
  echo "load_credentials:     $(grep 'load_credentials' "$CFG" | head -n1 || true)"
else
  echo "PROBLEM: missing $CFG"
fi
echo

echo "---- 2) Portal DNC groups / numbers ----"
if [[ -f "$ENVF" ]]; then
  # shellcheck disable=SC1090
  set -a; source "$ENVF"; set +a
fi
DB_NAME="${DB_DATABASE:-ownswitch}"
DB_USER="${DB_USERNAME:-ownswitch}"
DB_PASS="${DB_PASSWORD:-}"
MYSQL_OWN=(mysql -N)
if [[ -n "$DB_PASS" ]]; then
  MYSQL_OWN=(mysql -N -u"$DB_USER" -p"$DB_PASS" "$DB_NAME")
elif [[ -f /etc/ownswitch/secrets/mysql_ownswitch_password ]]; then
  DB_PASS="$(tr -d '\n' </etc/ownswitch/secrets/mysql_ownswitch_password)"
  MYSQL_OWN=(mysql -N -u"$DB_USER" -p"$DB_PASS" "$DB_NAME")
else
  MYSQL_OWN=(mysql -Nuroot "$DB_NAME")
fi

echo "Portal groups:"
"${MYSQL_OWN[@]}" -e "SELECT id, name, status, number_count FROM dnc_groups ORDER BY id" 2>/dev/null || echo "WARN: cannot read dnc_groups"
GID=""
if [[ -n "$GROUP_Q" ]]; then
  if [[ "$GROUP_Q" =~ ^[0-9]+$ ]]; then
    GID="$GROUP_Q"
  else
    GID="$("${MYSQL_OWN[@]}" -e "SELECT id FROM dnc_groups WHERE name='${GROUP_Q}' LIMIT 1" 2>/dev/null || true)"
  fi
  echo "Resolved group '$GROUP_Q' → id=${GID:-NOT FOUND}"
fi
if [[ -n "$GID" ]]; then
  echo "Numbers in group $GID matching $NUM / NANP alt:"
  ALT=""
  if [[ ${#NUM} -eq 11 && $NUM == 1* ]]; then ALT="${NUM:1}"; elif [[ ${#NUM} -eq 10 ]]; then ALT="1$NUM"; fi
  "${MYSQL_OWN[@]}" -e "SELECT id, number_digits, note FROM dnc_numbers WHERE dnc_group_id=$GID AND number_digits IN ('$NUM','${ALT:-__none__}')" 2>/dev/null || true
  "${MYSQL_OWN[@]}" -e "SELECT COUNT(*) AS total_in_group FROM dnc_numbers WHERE dnc_group_id=$GID" 2>/dev/null || true
else
  echo "Search number $NUM in any group:"
  "${MYSQL_OWN[@]}" -e "SELECT n.dnc_group_id, g.name, g.status, n.number_digits FROM dnc_numbers n JOIN dnc_groups g ON g.id=n.dnc_group_id WHERE n.number_digits IN ('$NUM','${NUM:1}') LIMIT 20" 2>/dev/null || true
fi
echo

echo "---- 3) Trunks with this DNC group (portal) ----"
if [[ -n "$GID" ]]; then
  "${MYSQL_OWN[@]}" -e "SELECT id, name, dnc_group_id, status FROM carrier_trunks WHERE dnc_group_id=$GID" 2>/dev/null || \
    "${MYSQL_OWN[@]}" -e "SELECT id, name, dnc_group_id FROM carrier_trunks WHERE dnc_group_id=$GID" 2>/dev/null || true
else
  "${MYSQL_OWN[@]}" -e "SELECT id, name, dnc_group_id FROM carrier_trunks WHERE dnc_group_id IS NOT NULL AND dnc_group_id>0" 2>/dev/null || true
fi
echo "SIP users with DNC group:"
"${MYSQL_OWN[@]}" -e "SELECT id, username, dnc_group_id FROM sip_accounts WHERE dnc_group_id IS NOT NULL AND dnc_group_id>0 LIMIT 20" 2>/dev/null || true
echo

echo "---- 4) OpenSIPS dr_gateways.attrs (must contain dncid=N) ----"
mysql -Nuroot opensips -e "SELECT gwid, description, attrs FROM dr_gateways" 2>/dev/null || echo "WARN: cannot read dr_gateways"
if ! mysql -Nuroot opensips -e "SELECT attrs FROM dr_gateways" 2>/dev/null | grep -q 'dncid='; then
  echo "PROBLEM: NO gateway attrs contain dncid= — re-save the trunk in portal (or sync trunks)"
fi
echo

echo "---- 5) OpenSIPS subscriber.dnc_group_id (SIP-user DNC) ----"
mysql -Nuroot opensips -e "SELECT username, dnc_group_id, dst_format FROM subscriber LIMIT 20" 2>/dev/null || echo "WARN: subscriber.dnc_group_id missing?"
echo

echo "---- 6) Hot cache (Redis + dnc_cache) ----"
REDIS_DB="${REDIS_PORTAL_DB:-1}"
REDIS_PASS=""
[[ -s /etc/ownswitch/secrets/redis_password ]] && REDIS_PASS="$(tr -d '\n' </etc/ownswitch/secrets/redis_password)"
if [[ -n "$GID" ]]; then
  KEY="ownswitch:dnc:$GID"
  if command -v redis-cli >/dev/null 2>&1; then
    if [[ -n "$REDIS_PASS" ]]; then
      HIT="$(redis-cli -a "$REDIS_PASS" -n "$REDIS_DB" SISMEMBER "$KEY" "$NUM" 2>/dev/null || echo err)"
      HIT2="n/a"
      if [[ ${#NUM} -eq 11 && $NUM == 1* ]]; then
        HIT2="$(redis-cli -a "$REDIS_PASS" -n "$REDIS_DB" SISMEMBER "$KEY" "${NUM:1}" 2>/dev/null || echo err)"
      fi
      SCARD="$(redis-cli -a "$REDIS_PASS" -n "$REDIS_DB" SCARD "$KEY" 2>/dev/null || echo err)"
    else
      HIT="$(redis-cli -n "$REDIS_DB" SISMEMBER "$KEY" "$NUM" 2>/dev/null || echo err)"
      HIT2="n/a"
      SCARD="$(redis-cli -n "$REDIS_DB" SCARD "$KEY" 2>/dev/null || echo err)"
    fi
    echo "Redis $KEY scard=$SCARD SISMEMBER($NUM)=$HIT SISMEMBER(alt)=$HIT2"
    if [[ "$HIT" != "1" && "$HIT2" != "1" ]]; then
      echo "PROBLEM: number NOT in Redis set — run sync-dnc-cache.php"
    fi
  else
    echo "redis-cli not installed — skip Redis check"
  fi
  echo "MySQL opensips.dnc_cache:"
  mysql -Nuroot opensips -e "SELECT group_id, number_digits FROM dnc_cache WHERE group_id=$GID AND number_digits IN ('$NUM','${NUM:1}')" 2>/dev/null || echo "(no dnc_cache rows / table)"
  CNT="$(mysql -Nuroot opensips -e "SELECT COUNT(*) FROM dnc_cache WHERE group_id=$GID" 2>/dev/null || echo 0)"
  echo "dnc_cache rows for group $GID: $CNT"
fi
echo

echo "---- 7) Recent DNC / INVITE log lines ----"
journalctl -u opensips -n 200 --no-pager 2>/dev/null | grep -E 'DNC|Do Not Call|check_dnc|dncid|INVITE owner|auth=ok' | tail -n 40 || true
echo
echo "======== interpretation ========"
echo "For trunk DNC to block BEFORE vendor:"
echo "  A) route(check_dnc) must run and route body must SISMEMBER/sql (not stub return)"
echo "  B) dr_gateways.attrs must include dncid=<portal_group_id> for the selected gw (T1 → gwid T1)"
echo "  C) Redis or dnc_cache must contain the dialled digits (and/or NANP alt)"
echo "  D) Prefer also set DNC group on the SIP user (blocks before do_routing)"
echo
echo "Fix sequence:"
echo "  sudo bash /opt/ownswitch-src/deploy/fix-opensips-dnc.sh"
echo "  sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env php /opt/ownswitch/current/bin/sync-dnc-cache.php"
echo "  sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env php /opt/ownswitch/current/bin/sync-opensips-trunks.php"
echo "  # then re-save trunk + SIP user in portal (DNC group set)"
