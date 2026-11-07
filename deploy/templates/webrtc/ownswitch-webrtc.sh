#!/usr/bin/env bash
# Portal Settings → WebRTC. Local SIP-over-WebSocket for the browser dialer.
#   ownswitch-webrtc status
#   ownswitch-webrtc enable
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "message=Run as root"; exit 1; }

FS_CONF="${FREESWITCH_CONF:-/usr/local/freeswitch/conf}"
EXT="${FS_CONF}/sip_profiles/external.xml"
NGINX="/etc/nginx/sites-available/ownswitch.conf"
ENVF="/etc/ownswitch/ownswitch.env"
MARK="ownswitch-sip-ws"

ws_present() { [[ -f "$EXT" ]] && grep -q 'ws-binding' "$EXT"; }
nginx_present() { [[ -f "$NGINX" ]] && grep -q "$MARK" "$NGINX"; }

fs_cli() {
  local pass=""
  [[ -f "$ENVF" ]] && pass="$(grep -E '^FREESWITCH_ESL_PASSWORD=' "$ENVF" | head -1 | cut -d= -f2- || true)"
  if [[ -n "$pass" && -x /usr/local/freeswitch/bin/fs_cli ]]; then
    /usr/local/freeswitch/bin/fs_cli -p "$pass" -x "$1" || true
  elif [[ -x /usr/local/freeswitch/bin/fs_cli ]]; then
    /usr/local/freeswitch/bin/fs_cli -x "$1" || true
  fi
}

emit_status() {
  echo "ok=1"
  if ws_present && nginx_present; then
    echo "enabled=1"
  else
    echo "enabled=0"
  fi
  if ss -ltn 2>/dev/null | grep -q ':5066'; then
    echo "listening=1"
  else
    echo "listening=0"
  fi
}

ensure_ws_binding() {
  [[ -f "$EXT" ]] || { echo "message=FreeSWITCH external profile is missing"; return 1; }
  if grep -q 'ws-binding' "$EXT"; then
    return 0
  fi
  cp -a "$EXT" "${EXT}.bak.ws.$(date +%s)"
  sed -i '/name="sip-port"/a\    <param name="ws-binding" value="127.0.0.1:5066"/>' "$EXT"
}

ensure_nginx() {
  [[ -f "$NGINX" ]] || { echo "message=Nginx site is missing"; return 1; }
  if grep -q 'microphone=()' "$NGINX"; then
    sed -i 's/microphone=()/microphone=(self)/' "$NGINX"
  fi
  if nginx_present; then
    nginx -t
    systemctl reload nginx
    return 0
  fi
  cp -a "$NGINX" "${NGINX}.bak.ws.$(date +%s)"
  local snippet
  snippet="$(mktemp)"
  cat >"$snippet" <<'EOF'
    # ownswitch-sip-ws — browser dialer
    location = /sip-ws {
        proxy_pass http://127.0.0.1:5066/;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        proxy_buffering off;
    }

EOF
  SNIP="$snippet" awk '
    !done && index($0, "location / {") {
      while ((getline line < ENVIRON["SNIP"]) > 0) print line
      done = 1
    }
    { print }
  ' "$NGINX" >"${NGINX}.new"
  rm -f "$snippet"
  mv "${NGINX}.new" "$NGINX"
  if ! nginx -t; then
    echo "message=nginx -t failed, previous site config restored"
    local bak
    bak="$(ls -1t "${NGINX}.bak.ws."* 2>/dev/null | head -1 || true)"
    [[ -n "$bak" ]] && cp -a "$bak" "$NGINX"
    return 1
  fi
  systemctl reload nginx
}

cmd="${1:-status}"
case "$cmd" in
  status)
    emit_status
    ;;
  enable)
    ensure_ws_binding
    ensure_nginx
    fs_cli "sofia profile external restart" >/dev/null
    sleep 1
    echo "message=WebRTC is enabled. The browser dialer registers at wss://this-host/sip-ws."
    emit_status
    ;;
  *)
    echo "message=Unknown command"
    exit 1
    ;;
esac
