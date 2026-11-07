#!/usr/bin/env bash
# One-time (or anytime) install of portal “Update now” helper + sudoers.
# Run as root:  sudo bash /opt/ownswitch-src/deploy/install-update-helper.sh
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
if [[ ! -d "$SRC/.git" && -f /opt/ownswitch/current/deploy/install-update-helper.sh ]]; then
  # Prefer live release copy if src missing
  SRC="$(readlink -f /opt/ownswitch/current)"
fi

HELPER_SRC="$SRC/deploy/templates/update/ownswitch-update.sh"
SUDOERS_SRC="$SRC/deploy/templates/update/ownswitch-update.sudoers"
REFRESH="$SRC/deploy/refresh-ui.sh"

[[ -f "$HELPER_SRC" ]] || { echo "Missing $HELPER_SRC — pull latest main first"; exit 1; }

install -m 0755 "$HELPER_SRC" /usr/local/sbin/ownswitch-update

# Allow portal user to run helper AND refresh-ui directly (bootstrap)
cat >/etc/sudoers.d/ownswitch-update <<'EOF'
# Ownswitch portal Version → Update now
ownswitch ALL=(root) NOPASSWD: /usr/local/sbin/ownswitch-update
ownswitch ALL=(root) NOPASSWD: /bin/bash /opt/ownswitch-src/deploy/refresh-ui.sh
ownswitch ALL=(root) NOPASSWD: /usr/bin/bash /opt/ownswitch-src/deploy/refresh-ui.sh
EOF
chmod 0440 /etc/sudoers.d/ownswitch-update
visudo -cf /etc/sudoers.d/ownswitch-update

echo "OK: /usr/local/sbin/ownswitch-update installed"
echo "OK: sudoers ownswitch-update"
echo "Test: sudo -u ownswitch sudo -n /usr/local/sbin/ownswitch-update"
echo "(that runs a full refresh — optional)"
ls -la /usr/local/sbin/ownswitch-update
[[ -f "$REFRESH" ]] && echo "refresh-ui: $REFRESH"

# SSL + WebRTC helpers (Settings pages). Always refresh from this SRC.
if [[ -f "$SRC/deploy/install-ssl-webrtc-helpers.sh" ]]; then
  bash "$SRC/deploy/install-ssl-webrtc-helpers.sh"
fi
