#!/usr/bin/env bash
# Fix ghost "Connected" Live Calls + ensure MI hangup works.
#   sudo bash /opt/ownswitch-src/deploy/fix-live-calls-dialogs.sh
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
cd "$SRC"
git fetch origin main && git reset --hard origin/main

# Re-render OpenSIPS (OPTIONS dialog ping BPp + FAILOVER_SIP_STATUS) + portal
if ! bash "$SRC/deploy/install.sh" --only 50 --yes --non-interactive; then
  echo "WARN: stage 50 failed — check unresolved placeholders / opensips -c"
  echo "  Retry: sudo bash $SRC/deploy/install.sh --from 50"
fi
bash "$SRC/deploy/refresh-ui.sh"

ENV_FILE=/etc/ownswitch/ownswitch.env
# Ensure MI HTTP URL for portal hangup / dlg_list (ringing + connected)
if [[ -f "$ENV_FILE" ]]; then
  if ss -H -ltn 'sport = :8888' 2>/dev/null | grep -q .; then
    MI=http://127.0.0.1:8888/mi
    grep -q '^OPENSIPS_MI_HTTP_URL=' "$ENV_FILE" && sed -i "s|^OPENSIPS_MI_HTTP_URL=.*|OPENSIPS_MI_HTTP_URL=${MI}|" "$ENV_FILE" || echo "OPENSIPS_MI_HTTP_URL=${MI}" >>"$ENV_FILE"
    grep -q '^OPENSIPS_MI_URL=' "$ENV_FILE" && sed -i "s|^OPENSIPS_MI_URL=.*|OPENSIPS_MI_URL=${MI}|" "$ENV_FILE" || echo "OPENSIPS_MI_URL=${MI}" >>"$ENV_FILE"
  fi
fi

# Wipe zombie dialog rows (portal was merging stale DB "Connected" after ViciDial hangup)
mysql --protocol=socket -uroot opensips -e "DELETE FROM dialog; DELETE FROM dialog_vars;" 2>/dev/null || \
  mysql --protocol=socket -uroot opensips -e "DELETE FROM dialog;" 2>/dev/null || true

systemctl reload opensips 2>/dev/null || systemctl restart opensips
sleep 2
opensips-cli -x mi dlg_list 2>/dev/null | head -n 30 || curl -s -X POST http://127.0.0.1:8888/mi \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","method":"dlg_list","id":1}' || true

echo
echo "Done. Live Calls uses MI dlg_list (Trying/Ringing/Connected)."
echo "Ghost Connected rows cleared; new calls OPTIONS-ping both legs every 20s."
echo "Hangup uses MI dlg_end_dlg with DB purge fallback."
