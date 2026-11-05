#!/usr/bin/env bash
# Stage 99 - health checks + deployment report.
# Report: /var/lib/ownswitch-install/reports/deploy-report-<run>.txt (+ .json, latest.txt symlink)
# The report NEVER contains secret values - only the locations of secret files.
# Exit code: 1 when any check FAILS (set HEALTH_FAIL_EXIT=0 to always exit 0).
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
stage_init "99_health_report"

REPORT_DIR="${OWN_STATE}/reports"
install -d -m 0750 "${REPORT_DIR}"
REPORT="${REPORT_DIR}/deploy-report-${RUN_ID}.txt"
JSON="${REPORT_DIR}/deploy-report-${RUN_ID}.json"

declare -a CHECKS=()   # "status|name|detail"
n_ok=0; n_warn=0; n_fail=0
add() { # status name detail
  CHECKS+=("$1|$2|$3")
  case "$1" in OK) n_ok=$((n_ok + 1));; WARN) n_warn=$((n_warn + 1));; FAIL) n_fail=$((n_fail + 1));; esac
  case "$1" in OK) log_ok "$2: $3";; WARN) log_warn "$2: $3";; FAIL) log_error "$2: $3";; esac
}

svc_check() { # unit [required=1]
  local u=$1 req=${2:-1}
  if systemctl is-active --quiet "$u"; then add OK "service ${u}" "active"
  elif (( req )); then add FAIL "service ${u}" "$(systemctl is-active "$u" 2>&1 || true)"
  else add WARN "service ${u}" "not active (optional)"; fi
}

# ---------------------------------------------------------------------------
# services
# ---------------------------------------------------------------------------
svc_check mysql
svc_check redis-server
svc_check freeswitch 1
svc_check cgrates
svc_check "php${PHP_VERSION}-fpm"
svc_check nginx
svc_check ufw
svc_check chrony
for u in $(systemctl list-unit-files --no-legend 'rtpengine*.service' 2>/dev/null | awk '{print $1}' || true); do svc_check "$u" 1; done
_sip_owner="$(ss -H -ulnp 'sport = :5060' 2>/dev/null | head -n1 || true)"
if systemctl is-active --quiet freeswitch && [[ "${_sip_owner}" == *freeswitch* ]]; then
  add OK "SIP 5060" "FreeSWITCH"
elif [[ "${_sip_owner}" == *opensips* ]]; then
  add FAIL "SIP 5060" "still owned by OpenSIPS — run deploy/remove-opensips.sh after stage 75"
else
  add FAIL "SIP 5060" "FreeSWITCH is not bound (${_sip_owner:-no listener})"
fi
svc_check ownswitch-scheduler.timer 1
svc_check ownswitch-backup.timer 1
if [[ -f "${OWN_OPT}/current/bin/worker.php" || -f "${OWN_OPT}/current/bin/worker_cdr.php" ]]; then
  svc_check ownswitch-worker 1
else
  add WARN "service ownswitch-worker" "dormant: bin/worker.php not deployed yet"
fi

# ---------------------------------------------------------------------------
# versions
# ---------------------------------------------------------------------------
mv_="$(mysqld --version 2>/dev/null | sed -n 's/.*Ver \([0-9.]*\).*/\1/p' || true)"
[[ "$mv_" == 8.4.* ]] && add OK "MySQL version" "$mv_" || add FAIL "MySQL version" "expected 8.4.x, got '${mv_}'"
ov_="removed"
pv_="$(php -r 'echo PHP_VERSION;' 2>/dev/null || true)"
[[ "$pv_" == ${PHP_VERSION}.* ]] && add OK "PHP version" "$pv_" || add FAIL "PHP version" "expected ${PHP_VERSION}.x, got '${pv_}'"
cv_="$(cat /usr/share/cgrates/.installed-version 2>/dev/null || true)"
[[ "$cv_" == "${CGRATES_VERSION}" ]] && add OK "CGRateS version" "$cv_" || add FAIL "CGRateS version" "expected ${CGRATES_VERSION}, got '${cv_}'"

# ---------------------------------------------------------------------------
# functional probes
# ---------------------------------------------------------------------------
for db in "${DB_OWNSWITCH}" "${DB_CGRATES}"; do
  cnt="$(mysql_scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${db}'" 2>/dev/null || echo ERR)"
  if [[ "$cnt" == "ERR" ]]; then add FAIL "database ${db}" "not reachable"
  else add OK "database ${db}" "${cnt} tables"; fi
done
[[ "$(mysql_scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_CGRATES}' AND table_name IN ('cdrs','tp_rates')" 2>/dev/null || echo 0)" == "2" ]] \
  && add OK "CGRateS StorDB schema" "cdrs + tp_rates present" || add FAIL "CGRateS StorDB schema" "tables missing"

if [[ -s "$(secret_path redis_password)" ]]; then
  [[ "$(REDISCLI_AUTH="$(get_secret redis_password)" redis-cli -h 127.0.0.1 ping 2>/dev/null || true)" == "PONG" ]] \
    && add OK "Redis auth ping" "PONG" || add FAIL "Redis auth ping" "no PONG"
fi

pong="$(curl -fsS --max-time 5 -H 'Content-Type: application/json' -d '{"method":"CoreSv1.Ping","params":[{}],"id":1}' "http://${CGR_HTTP_ADDR}/jsonrpc" 2>/dev/null || true)"
[[ "$pong" == *Pong* ]] && add OK "CGRateS JSON-RPC" "CoreSv1.Ping -> Pong" || add FAIL "CGRateS JSON-RPC" "no Pong (${pong:-empty})"

if [[ -x /usr/local/freeswitch/bin/freeswitch ]]; then
  add OK "FreeSWITCH binary" "$(cat /usr/local/freeswitch/.ownswitch-installed-version 2>/dev/null || echo present)"
else
  add FAIL "FreeSWITCH binary" "missing — run deploy stage 75"
fi
if nginx -t >/dev/null 2>&1; then add OK "nginx config" "nginx -t passed"; else add FAIL "nginx config" "nginx -t failed"; fi

code="$(curl -ks -o /dev/null -w '%{http_code}' --max-time 10 --resolve "${OWNSWITCH_DOMAIN}:443:127.0.0.1" "https://${OWNSWITCH_DOMAIN}/" || true)"
if [[ "$code" =~ ^[2345][0-9][0-9]$ ]]; then add OK "HTTPS portal" "HTTP ${code}"; else add FAIL "HTTPS portal" "no answer (${code})"; fi
_cur="$(readlink -f "${OWN_OPT}/current" 2>/dev/null || true)"
if [[ -f "${OWN_OPT}/current/.ownswitch-placeholder" || -f "${_cur}/.ownswitch-placeholder" ]]; then
  add WARN "application" "placeholder deployed - application code not yet installed"
elif [[ ! -f "${OWN_OPT}/current/public/index.php" ]]; then
  add FAIL "application" "public/index.php missing under current"
elif grep -ql 'application has not been deployed yet' "${OWN_OPT}/current/public/index.php" 2>/dev/null; then
  add WARN "application" "placeholder index.php still live"
else
  add OK "application" "release $(basename "${_cur:-unknown}")"
fi
[[ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "http://${INTERNAL_HTTP_ADDR}/nginx-health" || true)" == "200" ]] \
  && add OK "internal vhost" "${INTERNAL_HTTP_ADDR}/nginx-health" || add WARN "internal vhost" "no 200 from ${INTERNAL_HTTP_ADDR}"

# ---------------------------------------------------------------------------
# exposure audit: internal ports must be loopback-only; list everything that is public
# ---------------------------------------------------------------------------
listeners="$(ss -H -lntu 2>/dev/null || true)"
for spec in tcp:3306 tcp:33060 tcp:6379 tcp:2012 tcp:2013 tcp:2014 tcp:2080 tcp:8021 udp:"${RTPENGINE_NG_PORT}"; do
  proto="${spec%%:*}"; port="${spec##*:}"
  leaks="$(awk -v pr="$proto" -v pt=":${port}" '$1==pr && $5 ~ pt"$" && $5 !~ /^127\.0\.0\.1:/ && $5 !~ /^\[::1\]:/ {print $5}' <<<"$listeners" || true)"
  if [[ -n "$leaks" ]]; then add FAIL "exposure ${proto}/${port}" "listening on non-loopback: $(echo "$leaks" | tr '\n' ' ')"
  else add OK "exposure ${proto}/${port}" "not publicly bound"; fi
done
ufw_state="$(ufw status verbose 2>/dev/null || true)"
[[ "$ufw_state" == *"Status: active"* && "$ufw_state" == *"deny (incoming)"* ]] \
  && add OK "firewall" "ufw active, default deny incoming" || add FAIL "firewall" "ufw inactive or default policy not deny"

# secrets hygiene
bad="$(find "${OWN_SECRETS}" -type f ! -perm 0600 2>/dev/null | head -n3 || true)"
[[ -z "$bad" ]] && add OK "secrets permissions" "all files 0600" || add FAIL "secrets permissions" "$bad"
[[ "$(stat -c '%a' "${OWN_ETC}/ownswitch.env" 2>/dev/null || echo missing)" == "640" ]] \
  && add OK "env file permissions" "${OWN_ETC}/ownswitch.env 0640 root:ownswitch" || add WARN "env file permissions" "check ${OWN_ETC}/ownswitch.env"
leak="$(grep -rIl --exclude-dir=.git -e "$(get_secret mysql_ownswitch_password 2>/dev/null || echo __none__)" "${OWN_OPT}/current/" 2>/dev/null || true)"
if [[ -n "$leak" ]]; then
  add FAIL "secret leakage" "a DB password was found inside the application tree"
else add OK "secret leakage" "no DB password found under ${OWN_OPT}/current"; fi

# ---------------------------------------------------------------------------
# write report
# ---------------------------------------------------------------------------
{
  echo "OWNSWITCH DEPLOYMENT REPORT"
  echo "==========================="
  echo "Run ID      : ${RUN_ID}"
  echo "Generated   : $(date -u +%FT%TZ)"
  echo "Host        : $(hostname -f 2>/dev/null || hostname)"
  echo "OS          : ${OS_PRETTY} (${OS_ARCH}), kernel $(uname -r)"
  echo "Resources   : $(nproc) CPU, $(awk '/MemTotal/ {printf "%d MB", $2/1024}' /proc/meminfo) RAM, $(df -h --output=avail / | tail -n1 | tr -d ' ') free on /"
  echo "Domain      : ${OWNSWITCH_DOMAIN}   Public IP: ${PUBLIC_IP}   Private IP: ${PRIVATE_IP}"
  echo
  echo "SUMMARY: ${n_ok} OK, ${n_warn} WARN, ${n_fail} FAIL"
  echo
  echo "STAGE RESULTS (this run)"
  echo "------------------------"
  if [[ -f "$(run_dir)/results.tsv" ]]; then awk -F'\t' '{printf "  %-28s %-8s %s\n", $1, $2, $3}' "$(run_dir)/results.tsv"; else echo "  (none recorded)"; fi
  echo
  echo "CHECKS"
  echo "------"
  for c in "${CHECKS[@]}"; do IFS='|' read -r s n d <<<"$c"; printf '  [%-4s] %-28s %s\n' "$s" "$n" "$d"; done
  echo
  echo "COMPONENT VERSIONS"
  echo "------------------"
  echo "  MySQL     : ${mv_}"
  echo "  OpenSIPS  : removed (FreeSWITCH is the SIP edge)"
  echo "  CGRateS   : ${cv_}"
  echo "  PHP       : ${pv_}"
  echo "  nginx     : $(nginx -v 2>&1 | sed -n 's/.*nginx\///p' || echo n/a)"
  echo "  Redis     : $(redis-server --version 2>/dev/null | sed -n 's/.*v=\([0-9.]*\).*/\1/p' || echo n/a)"
  echo "  RTPengine : $(dpkg-query -W -f='${Version}' rtpengine-daemon 2>/dev/null || dpkg-query -W -f='${Version}' ngcp-rtpengine-daemon 2>/dev/null || echo n/a)"
  echo "  FreeSWITCH: $(is_true "${FREESWITCH_ENABLED}" && cat /usr/local/freeswitch/.ownswitch-installed-version 2>/dev/null || echo disabled)"
  echo
  echo "DATABASES (separate by design)"
  echo "------------------------------"
  echo "  ownswitch (portal)  user: ownswitch      -> .env DB_*"
  echo "  cgrates (StorDB)    user: cgrates (+ cgrates_ro read-only for the portal)"
  echo "  Redis DataDB index  : ${REDIS_CGR_DB} (CGRateS), ${REDIS_PORTAL_DB} (portal cache)"
  echo
  echo "PUBLIC LISTENERS (ss -lntu, non-loopback)"
  echo "-----------------------------------------"
  awk '$5 !~ /^127\./ && $5 !~ /^\[::1\]/ && $5 !~ /^\[::\]:(53)$/ {printf "  %-4s %s\n", $1, $5}' <<<"$listeners" | sort -u
  echo
  echo "SECRET / CONFIG LOCATIONS (values are not printed)"
  echo "--------------------------------------------------"
  echo "  Secrets directory     : ${OWN_SECRETS}  (root:root 0700, files 0600)"
  ls -1 "${OWN_SECRETS}" 2>/dev/null | sed 's/^/    - /' || true
  echo "  Application env file  : ${OWN_ETC}/ownswitch.env (root:ownswitch 0640)"
  echo "  Persisted settings    : ${OWN_ETC}/install.conf (non-secret)"
  echo "  CGRateS config        : /etc/cgrates/cgrates.json (root:cgrates 0640)"
  echo "  FreeSWITCH config     : /usr/local/freeswitch/conf (SIP ${SIP_PORT}, ESL 127.0.0.1:8021)"
  echo "  Backup passphrase     : $(secret_path backup_passphrase)  <-- COPY OFFLINE"
  [[ -f /root/ownswitch-initial-admin.txt ]] && echo "  Initial admin password: /root/ownswitch-initial-admin.txt (delete after first login)"
  echo
  echo "CHANGES MADE IN THIS RUN"
  echo "------------------------"
  if [[ -s "$(run_dir)/changes.log" ]]; then sed 's/^/  - /' "$(run_dir)/changes.log"; else echo "  (none - host already converged)"; fi
  echo
  echo "NEXT STEPS"
  echo "----------"
  echo "  1. Browse https://${OWNSWITCH_DOMAIN}/ (self-signed unless Let's Encrypt succeeded)."
  echo "  2. Restrict SIP/SSH with SIP_ALLOW_CIDRS / SSH_ALLOW_CIDRS and re-run stage 20."
  echo "  3. Copy ${OWN_SECRETS}/backup_passphrase to an offline password manager; run: sudo ownswitch-backup"
  echo "  4. Deploy the application (APP_SRC) and run: sudo deploy/install.sh --only 90"
  echo "  Install log: ${INSTALL_LOG}"
} >"${REPORT}"
chmod 0640 "${REPORT}"

# machine-readable copy
{
  printf '{"run_id":"%s","generated":"%s","host":"%s","ok":%d,"warn":%d,"fail":%d,"checks":[' \
    "${RUN_ID}" "$(date -u +%FT%TZ)" "$(hostname)" "$n_ok" "$n_warn" "$n_fail"
  first=1
  for c in "${CHECKS[@]}"; do
    IFS='|' read -r s n d <<<"$c"
    d="${d//\\/\\\\}"; d="${d//\"/\\\"}"; d="${d//$'\n'/ }"
    (( first )) || printf ','
    first=0
    printf '{"status":"%s","name":"%s","detail":"%s"}' "$s" "$n" "$d"
  done
  printf ']}\n'
} >"${JSON}"
chmod 0640 "${JSON}"
ln -sfn "${REPORT}" "${REPORT_DIR}/latest.txt"
ln -sfn "${JSON}" "${REPORT_DIR}/latest.json"

# Always print failures FIRST (full report is long — operators miss [FAIL] lines)
if (( n_fail > 0 )); then
  echo
  echo "======== FAILED CHECKS (${n_fail}) — READ THIS ========"
  for c in "${CHECKS[@]}"; do
    IFS='|' read -r s n d <<<"$c"
    [[ "$s" == "FAIL" ]] || continue
    printf '  >>> FAIL | %s | %s\n' "$n" "$d"
  done
  echo "======================================================"
  echo
fi

echo
# Only show CHECKS summary lines from the report (not the whole essay) + pointer
echo "SUMMARY: ${n_ok} OK, ${n_warn} WARN, ${n_fail} FAIL"
echo "CHECKS:"
for c in "${CHECKS[@]}"; do
  IFS='|' read -r s n d <<<"$c"
  printf '  [%-4s] %-28s %s\n' "$s" "$n" "$d"
done
echo
log_info "Full report saved: ${REPORT}"
log_info "Symlink: ${REPORT_DIR}/latest.txt"

if (( n_fail > 0 )); then
  echo
  echo "======== FAILED CHECKS (${n_fail}) — AGAIN ========"
  for c in "${CHECKS[@]}"; do
    IFS='|' read -r s n d <<<"$c"
    [[ "$s" == "FAIL" ]] || continue
    printf '  >>> FAIL | %s | %s\n' "$n" "$d"
    log_error "FAIL | ${n} | ${d}"
  done
  echo "Fix the item(s) above, then: sudo bash ${DEPLOY_DIR}/install.sh --only 99"
  echo
  log_error "${n_fail} health check(s) FAILED"
  is_true "${HEALTH_FAIL_EXIT}" && exit 1
fi
log_ok "Health report complete (${n_ok} OK, ${n_warn} WARN, ${n_fail} FAIL)"
