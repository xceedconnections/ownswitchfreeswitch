#!/usr/bin/env bash
# Emergency: Eyebeam not registering after a CLI/DNC patch.
# 1) Unban fail2ban  2) Prefer restoring last known-good bak if opensips is sick
# 3) Rebuild subscriber columns + load_credentials  4) Restart + verify REGISTER path
#
# Usage: sudo bash /opt/ownswitch-src/deploy/recover-opensips-register-now.sh
set -Eeuo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
CFG="${1:-/etc/opensips/opensips.cfg}"

echo "======== REGISTER emergency recover ========"

fail2ban-client unban --all >/dev/null 2>&1 || true
echo "fail2ban: unban --all"

# If opensips is dead or cfg fails check, restore newest bak that still validates
if ! systemctl is-active --quiet opensips || ! opensips -c -f "$CFG" >/dev/null 2>&1; then
  echo "opensips unhealthy or cfg invalid — trying newest backup…"
  mapfile -t BAKS < <(ls -t "$CFG".bak-* 2>/dev/null || true)
  RESTORED=0
  for b in "${BAKS[@]}"; do
    [[ -f "$b" ]] || continue
    if opensips -c -f "$b" >/dev/null 2>&1; then
      cp -a "$b" "$CFG"
      echo "Restored $b → $CFG"
      RESTORED=1
      break
    fi
    echo "skip bad bak: $b"
  done
  if [[ "$RESTORED" -eq 0 ]]; then
    echo "WARN: no valid bak found — continuing with current cfg"
  fi
fi

bash "$SRC/deploy/fix-opensips-register.sh" "$CFG"

echo
echo "opensips: $(systemctl is-active opensips)"
echo "ss 5060:"; ss -ulnp 2>/dev/null | grep 5060 || true
echo
echo "Re-register Eyebeam now. Watch:"
echo "  journalctl -u opensips -f | grep REGISTER"
echo "Expect: REGISTER OK <user>"
echo
echo "After REGISTER works, re-apply features with:"
echo "  sudo bash $SRC/deploy/fix-opensips-cli-and-dnc.sh"
echo "(that script now ends with REGISTER repair automatically)"
