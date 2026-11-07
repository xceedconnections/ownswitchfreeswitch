#!/usr/bin/env bash
# Install Settings → SSL and Settings → WebRTC root helpers + sudoers.
# Run as root:  sudo bash /opt/ownswitch-src/deploy/install-ssl-webrtc-helpers.sh
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
if [[ ! -f "$SRC/deploy/templates/ssl/ownswitch-ssl.sh" ]]; then
  if [[ -f /opt/ownswitch/current/deploy/templates/ssl/ownswitch-ssl.sh ]]; then
    SRC="$(readlink -f /opt/ownswitch/current)"
  fi
fi

SSL_SRC="$SRC/deploy/templates/ssl/ownswitch-ssl.sh"
RTC_SRC="$SRC/deploy/templates/webrtc/ownswitch-webrtc.sh"

[[ -f "$SSL_SRC" ]] || { echo "Missing $SSL_SRC — pull latest main first"; exit 1; }
[[ -f "$RTC_SRC" ]] || { echo "Missing $RTC_SRC — pull latest main first"; exit 1; }

install -m 0755 "$SSL_SRC" /usr/local/sbin/ownswitch-ssl
install -m 0755 "$RTC_SRC" /usr/local/sbin/ownswitch-webrtc

cat >/etc/sudoers.d/ownswitch-ssl <<'EOF'
# Ownswitch portal Settings → SSL and WebRTC
ownswitch ALL=(root) NOPASSWD: /usr/local/sbin/ownswitch-ssl
ownswitch ALL=(root) NOPASSWD: /usr/local/sbin/ownswitch-webrtc
EOF
chmod 0440 /etc/sudoers.d/ownswitch-ssl
visudo -cf /etc/sudoers.d/ownswitch-ssl

echo "OK: /usr/local/sbin/ownswitch-ssl"
echo "OK: /usr/local/sbin/ownswitch-webrtc"
echo "OK: sudoers ownswitch-ssl"
ls -la /usr/local/sbin/ownswitch-ssl /usr/local/sbin/ownswitch-webrtc
