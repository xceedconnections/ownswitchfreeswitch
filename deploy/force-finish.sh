#!/usr/bin/env bash
# =============================================================================
# Ownswitch — force-finish remaining stack after MySQL/Redis are up.
#
# Runs stages one-by-one with full logs (does not hide failures).
# Use when health still shows opensips/cgrates/nginx/php inactive.
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/force-finish.sh \
#     | sudo bash
#
# Env:
#   FREESWITCH_ENABLED=0   skip FreeSWITCH (recommended until core stack is green)
# =============================================================================
set -Eeuo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
LOG="/var/log/ownswitch-force-finish.log"
export DEBIAN_FRONTEND=noninteractive
export MYSQL_INSTALL_METHOD=direct
export FORCE_MYSQL_DIRECT=1
export NON_INTERACTIVE=1
export APP_SRC="${APP_SRC:-$SRC}"
# Default: get portal/SIP/billing up first; FreeSWITCH is slow and optional for HTTP/SIP billing core.
export FREESWITCH_ENABLED="${FREESWITCH_ENABLED:-0}"

exec > >(tee -a "$LOG") 2>&1
log() { printf '\n====[%s] %s====\n' "$(date -u +%H:%M:%S)" "$*"; }
die() { printf 'FATAL: %s\n' "$*" >&2; exit 1; }

log "Force-finish starting. Log: ${LOG}"

# --- sync code ---
apt-get install -y -qq ca-certificates curl git gnupg rsync >/dev/null || true
if [[ -d "${SRC}/.git" ]]; then
  git -C "${SRC}" fetch --depth 1 origin main
  git -C "${SRC}" reset --hard origin/main
else
  git clone --depth 1 https://github.com/xceedconnections/ownswitchfreeswitch.git "${SRC}"
fi
find "${SRC}/deploy" -type f -name '*.sh' -exec chmod +x {} +
[[ -f "${SRC}/public/index.php" ]] || die "public/index.php missing in ${SRC} — cannot deploy portal"

# Persist FS preference
mkdir -p /etc/ownswitch
grep -q '^FREESWITCH_ENABLED=' /etc/ownswitch/install.conf 2>/dev/null \
  && sed -i "s/^FREESWITCH_ENABLED=.*/FREESWITCH_ENABLED=${FREESWITCH_ENABLED}/" /etc/ownswitch/install.conf \
  || echo "FREESWITCH_ENABLED=${FREESWITCH_ENABLED}" >>/etc/ownswitch/install.conf
grep -q '^MYSQL_INSTALL_METHOD=' /etc/ownswitch/install.conf 2>/dev/null \
  && sed -i 's/^MYSQL_INSTALL_METHOD=.*/MYSQL_INSTALL_METHOD=direct/' /etc/ownswitch/install.conf \
  || echo 'MYSQL_INSTALL_METHOD=direct' >>/etc/ownswitch/install.conf

# Preconditions
systemctl is-active --quiet mysql || die "MySQL is not active — run repair-and-continue.sh first"
systemctl is-active --quiet redis-server || {
  log "Starting redis…"
  bash "${SRC}/deploy/install.sh" --only 40 --yes --non-interactive
}
mysql --protocol=socket -uroot -e 'SELECT 1' >/dev/null

run_stage() {
  local n=$1
  log "Running stage ${n}"
  if bash "${SRC}/deploy/install.sh" --only "${n}" --yes --non-interactive; then
    log "Stage ${n} OK"
    return 0
  fi
  log "Stage ${n} FAILED — collecting diagnostics"
  case "$n" in
    50) journalctl -u opensips -n 80 --no-pager || true
        opensips -c -f /etc/opensips/opensips.cfg 2>&1 || true
        ls /etc/apt/sources.list.d/opensips* 2>/dev/null || true
        ;;
    60) journalctl -u cgrates -n 80 --no-pager || true
        [[ -x /usr/local/bin/cgr-engine ]] && /usr/local/bin/cgr-engine -version || true
        ls -la /usr/share/cgrates/storage/mysql 2>/dev/null || true
        ;;
    70) journalctl -u 'rtpengine*' -n 40 --no-pager || true ;;
    75) journalctl -u freeswitch -n 40 --no-pager || true ;;
    80) journalctl -u nginx -n 40 --no-pager || true
        journalctl -u php8.3-fpm -n 40 --no-pager || true
        nginx -t 2>&1 || true
        php -v 2>&1 || true
        ;;
    90) ls -la /opt/ownswitch/current/public/index.php 2>/dev/null || true
        ls -la "${SRC}/public/index.php" || true
        ;;
  esac
  return 1
}

FAILED=()
# Core path: SIP + rating + media relay + web + app + workers
for n in 50 60 70 80 90 95; do
  if ! run_stage "$n"; then
    FAILED+=("$n")
    # Keep going so we surface every failure in one pass
  fi
done

# FreeSWITCH only when explicitly enabled
if [[ "${FREESWITCH_ENABLED}" == "1" ]]; then
  run_stage 75 || FAILED+=(75)
fi

# Force-start anything that is installed but inactive
log "Force-starting installed services…"
for u in opensips cgrates php8.3-fpm nginx; do
  if systemctl list-unit-files --no-legend "$u.service" 2>/dev/null | grep -q .; then
    systemctl enable "$u" >/dev/null 2>&1 || true
    systemctl restart "$u" >/dev/null 2>&1 || journalctl -u "$u" -n 30 --no-pager || true
  fi
done
for u in rtpengine rtpengine-daemon ngcp-rtpengine-daemon; do
  systemctl restart "$u" >/dev/null 2>&1 || true
done
systemctl enable --now ownswitch-scheduler.timer ownswitch-backup.timer >/dev/null 2>&1 || true
systemctl enable --now ownswitch-worker >/dev/null 2>&1 || true

# OpenSIPS schema hard check
if command -v mysql >/dev/null; then
  sub="$(mysql --protocol=socket -uroot -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='opensips' AND table_name='subscriber'" 2>/dev/null || echo 0)"
  if [[ "$sub" != "1" ]]; then
    log "OpenSIPS subscriber table missing — re-applying schemas via stage 50"
    run_stage 50 || true
  fi
fi

# Final health
run_stage 99 || true

echo
echo "========== RESULT =========="
echo "Failed stages this pass: ${FAILED[*]:-none}"
grep -E 'SUMMARY:|\[FAIL\]|\[WARN\]' /var/lib/ownswitch-install/reports/latest.txt 2>/dev/null || true
echo
echo "Services:"
for u in mysql redis-server opensips cgrates php8.3-fpm nginx; do
  printf '  %-20s %s\n' "$u" "$(systemctl is-active "$u" 2>/dev/null || echo missing)"
done
echo
echo "Portal:"
curl -ksI --max-time 10 "https://127.0.0.1/" -H "Host: ${OWNSWITCH_DOMAIN:-voip.xceedconnections.com}" | head -n8 || true
echo
echo "Full log: ${LOG}"
if ((${#FAILED[@]})); then
  echo "Some stages failed. Paste the FAIL lines above plus:  sudo tail -n 200 ${LOG}"
  exit 1
fi
echo "Force-finish completed successfully."
exit 0
