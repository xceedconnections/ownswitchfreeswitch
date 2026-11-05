#!/usr/bin/env bash
# Show which Ownswitch health checks failed and attempt automatic fixes.
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/diagnose-and-fix.sh | sudo bash
set -Eeuo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
REPORT="$(readlink -f /var/lib/ownswitch-install/reports/latest.txt 2>/dev/null || true)"
[[ -f "$REPORT" ]] || REPORT="$(ls -1t /var/lib/ownswitch-install/reports/deploy-report-*.txt 2>/dev/null | head -n1 || true)"

echo "========== FAIL / WARN from latest report =========="
if [[ -n "${REPORT:-}" && -f "$REPORT" ]]; then
  echo "Report: $REPORT"
  grep -E '\[(FAIL|WARN)\]' "$REPORT" || true
  echo
  grep -A2 'STAGE RESULTS' "$REPORT" | head -n 40 || true
else
  echo "(no report found)"
fi
echo

echo "========== Live service status =========="
for u in mysql redis-server opensips cgrates php8.3-fpm nginx rtpengine rtpengine-daemon \
         freeswitch ownswitch-scheduler.timer ownswitch-backup.timer ownswitch-worker; do
  st="$(systemctl is-active "$u" 2>/dev/null || echo missing)"
  printf '  %-32s %s\n' "$u" "$st"
done
echo

echo "========== Key paths =========="
echo "  APP_SRC tree     : $SRC (index.php=$( [[ -f $SRC/public/index.php ]] && echo yes || echo NO ))"
echo "  current release  : $(readlink -f /opt/ownswitch/current 2>/dev/null || echo missing)"
echo "  placeholder?     : $( [[ -f /opt/ownswitch/current/.ownswitch-placeholder ]] && echo YES || echo no )"
echo "  ownswitch.env    : $( [[ -f /etc/ownswitch/ownswitch.env ]] && echo present || echo MISSING )"
echo "  Domain           : $(grep -E '^OWNSWITCH_DOMAIN=' /etc/ownswitch/install.conf 2>/dev/null || echo unknown)"
echo

# Pull latest installer bits
if [[ -d "$SRC/.git" ]]; then
  git -C "$SRC" fetch --depth 1 origin main >/dev/null 2>&1 || true
  git -C "$SRC" reset --hard origin/main >/dev/null 2>&1 || true
fi
export APP_SRC="$SRC"
export MYSQL_INSTALL_METHOD=direct
export NON_INTERACTIVE=1

# Decide which stages to re-run based on live state
STAGES=()
if ! systemctl is-active --quiet mysql 2>/dev/null; then STAGES+=(30); fi
if ! systemctl is-active --quiet redis-server 2>/dev/null; then STAGES+=(40); fi
if ! systemctl is-active --quiet opensips 2>/dev/null; then STAGES+=(50); fi
if ! systemctl is-active --quiet cgrates 2>/dev/null; then STAGES+=(60); fi
if ! systemctl is-active --quiet rtpengine 2>/dev/null \
   && ! systemctl is-active --quiet rtpengine-daemon 2>/dev/null \
   && ! systemctl is-active --quiet ngcp-rtpengine-daemon 2>/dev/null; then
  STAGES+=(70)
fi
# FreeSWITCH only if enabled in install.conf
FS_EN="$(grep -E '^FREESWITCH_ENABLED=' /etc/ownswitch/install.conf 2>/dev/null | cut -d= -f2 || echo 0)"
if [[ "${FS_EN}" == "1" ]] && ! systemctl is-active --quiet freeswitch 2>/dev/null; then
  STAGES+=(75)
fi
if ! systemctl is-active --quiet nginx 2>/dev/null || ! systemctl is-active --quiet php8.3-fpm 2>/dev/null; then
  STAGES+=(80)
fi
# Always redeploy app if placeholder or no index
if [[ -f /opt/ownswitch/current/.ownswitch-placeholder ]] \
   || [[ ! -f /opt/ownswitch/current/public/index.php ]] \
   || [[ ! -f "$SRC/public/index.php" ]]; then
  STAGES+=(90)
fi
# Always refresh workers/timers + health
STAGES+=(95 99)

# Dedupe while preserving order
ONLY="$(printf '%s\n' "${STAGES[@]}" | awk '!s[$0]++' | paste -sd, -)"
echo "========== Re-running stages: ${ONLY:-none} =========="

if [[ -z "$ONLY" ]]; then
  echo "Nothing obvious to fix; re-running health report only."
  ONLY=99
fi

echo "Handing off to force-finish.sh (runs stages individually with full logs)…"
export FREESWITCH_ENABLED="${FREESWITCH_ENABLED:-0}"
bash "$SRC/deploy/force-finish.sh"
