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
WS_LINE='    <param name="ws-binding" value="127.0.0.1:5066"/>'

ws_present() { [[ -f "$EXT" ]] && grep -q 'ws-binding' "$EXT"; }
nginx_present() {
  [[ -f "$NGINX" ]] || return 1
  # Must live in the HTTPS server, not the :80 redirect server.
  awk '
    /listen[[:space:]]+443/ { https=1 }
    /listen[[:space:]]+80;/ { https=0 }
    /listen[[:space:]]+\[::\]:80/ { https=0 }
    /listen[[:space:]]+127\.0\.0\.1:/ { https=0 }
    https && /ownswitch-sip-ws|location \/sip-ws/ { found=1 }
    END { exit(found ? 0 : 1) }
  ' "$NGINX" && grep -q 'Sec-WebSocket-Protocol' "$NGINX"
}

fs_cli() {
  local pass=""
  [[ -f "$ENVF" ]] && pass="$(grep -E '^FREESWITCH_ESL_PASSWORD=' "$ENVF" | head -1 | cut -d= -f2- || true)"
  if [[ -n "$pass" && -x /usr/local/freeswitch/bin/fs_cli ]]; then
    /usr/local/freeswitch/bin/fs_cli -p "$pass" -x "$1" || true
  elif [[ -x /usr/local/freeswitch/bin/fs_cli ]]; then
    /usr/local/freeswitch/bin/fs_cli -x "$1" || true
  fi
}

listening() {
  ss -ltn 2>/dev/null | grep -qE '127\.0\.0\.1:5066|:5066' || ss -ltnH 2>/dev/null | grep -q ':5066'
}

emit_status() {
  echo "ok=1"
  if ws_present && nginx_present; then
    echo "enabled=1"
  else
    echo "enabled=0"
  fi
  if listening; then
    echo "listening=1"
  else
    echo "listening=0"
  fi
}

ensure_ws_binding() {
  [[ -f "$EXT" ]] || { echo "message=FreeSWITCH external profile is missing"; return 1; }
  cp -a "$EXT" "${EXT}.bak.ws.$(date +%s)"
  if grep -q 'ws-binding' "$EXT"; then
    sed -i -E 's#<param name="ws-binding"[^/]*/>#'"$WS_LINE"'#' "$EXT"
  elif grep -q 'name="sip-port"' "$EXT"; then
    sed -i '/name="sip-port"/a\'"$WS_LINE" "$EXT"
  else
    sed -i '/<settings>/a\'"$WS_LINE" "$EXT"
  fi
  grep -q 'ws-binding' "$EXT" || { echo "message=Could not write ws-binding into external.xml"; return 1; }
}

write_nginx_block() {
  cat <<'EOF'
    # ownswitch-sip-ws — browser dialer (HTTPS only)
    location /sip-ws {
        proxy_pass http://127.0.0.1:5066;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Sec-WebSocket-Protocol $http_sec_websocket_protocol;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        proxy_buffering off;
    }

EOF
}

ensure_nginx() {
  [[ -f "$NGINX" ]] || { echo "message=Nginx site is missing"; return 1; }
  if grep -q 'microphone=()' "$NGINX"; then
    sed -i 's/microphone=()/microphone=(self)/' "$NGINX"
  fi
  cp -a "$NGINX" "${NGINX}.bak.ws.$(date +%s)"

  # Strip every previous sip-ws location from any server block.
  awk '
    /ownswitch-sip-ws|location = \/sip-ws|location \/sip-ws/ { skip=1 }
    skip && /}/ { skip=0; next }
    skip { next }
    { print }
  ' "$NGINX" >"${NGINX}.tmp"
  mv "${NGINX}.tmp" "$NGINX"

  local snippet
  snippet="$(mktemp)"
  write_nginx_block >"$snippet"

  # Insert into the HTTPS (443) server only, just before its main location /.
  SNIP="$snippet" awk '
    /listen[[:space:]]+443/ { https=1 }
    /listen[[:space:]]+80;/ { https=0 }
    /listen[[:space:]]+\[::\]:80/ { https=0 }
    /listen[[:space:]]+127\.0\.0\.1:/ { https=0 }
    !done && https && index($0, "location / {") {
      while ((getline line < ENVIRON["SNIP"]) > 0) print line
      done = 1
    }
    { print }
  ' "$NGINX" >"${NGINX}.new"
  rm -f "$snippet"
  mv "${NGINX}.new" "$NGINX"

  if ! nginx_present; then
    echo "message=Could not place /sip-ws into the HTTPS nginx server"
    return 1
  fi
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
    sleep 2
    if ! listening; then
      fs_cli "sofia profile external start" >/dev/null
      sleep 2
    fi
    if listening; then
      echo "message=WebRTC is enabled. Softphone connects at wss://this-host/sip-ws."
    else
      echo "message=Nginx is ready, but FreeSWITCH is not listening on 5066 yet. Check sofia status / ws-binding support."
      emit_status
      exit 1
    fi
    emit_status
    ;;
  *)
    echo "message=Unknown command"
    exit 1
    ;;
esac
