#!/usr/bin/env bash
# Enable RTPengine without rewriting OpenSIPS dialplan / SIP auth.
# Safe for a working switch:
#   - Installs/starts rtpengine (userspace OK)
#   - Opens RTP UDP range in UFW (+ optional Hetzner note)
#   - Points portal health check at 127.0.0.1:22222
#   - Keeps Default media policy = Direct so existing calls stay peer-to-peer
#   - Does NOT restart OpenSIPS unless rtpengine_sock is missing from cfg
#
# Usage (as root on the switch):
#   cd /opt/ownswitch-src && git fetch origin && git reset --hard origin/main \
#     && bash deploy/enable-rtpengine-safe.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_ETC="${OWN_ETC:-/etc/ownswitch}"
OWN_VAR="${OWN_VAR:-/var/lib/ownswitch}"
ENVF="${OWN_ETC}/ownswitch.env"
IC="${OWN_ETC}/install.conf"

cd "$SRC"
git fetch origin main >/dev/null 2>&1 || true
git reset --hard origin/main >/dev/null 2>&1 || true

# Load installer helpers (IPs, ports, templates)
# shellcheck disable=SC1091
source "${SRC}/deploy/lib/common.sh"
setup_logging || true
# Prefer userspace (no DKMS/kernel risk) and pcap recording for a first enable
export RTPENGINE_KERNEL="${RTPENGINE_KERNEL:-0}"
export RTPENGINE_RECORDING_METHOD="${RTPENGINE_RECORDING_METHOD:-pcap}"
init_config
detect_os || true

: "${RTP_PORT_MIN:=30000}"
: "${RTP_PORT_MAX:=40000}"
: "${RTPENGINE_NG_PORT:=22222}"

export RTP_PORT_MIN RTP_PORT_MAX RTPENGINE_NG_PORT RTPENGINE_KERNEL RTPENGINE_RECORDING_METHOD
export PUBLIC_IP PRIVATE_IP

echo "======== enable-rtpengine-safe ========"
echo "PUBLIC_IP=${PUBLIC_IP}  PRIVATE_IP=${PRIVATE_IP}"
echo "RTP ${RTP_PORT_MIN}-${RTP_PORT_MAX}/udp  ng=127.0.0.1:${RTPENGINE_NG_PORT}"
echo "kernel=${RTPENGINE_KERNEL}  recording=${RTPENGINE_RECORDING_METHOD}"

# ---------------------------------------------------------------------------
# 1. Install + configure RTPengine (stage 70)
# ---------------------------------------------------------------------------
bash "${SRC}/deploy/stages/70_rtpengine.sh"

# ---------------------------------------------------------------------------
# 2. Host firewall: RTP media range (do not touch SIP rules)
# ---------------------------------------------------------------------------
if command -v ufw >/dev/null 2>&1; then
  ufw allow "${RTP_PORT_MIN}:${RTP_PORT_MAX}/udp" comment 'ownswitch-rtpengine-media' >/dev/null 2>&1 || true
  ufw status | grep -E "${RTP_PORT_MIN}|rtpengine" || true
  echo "UFW: allowed ${RTP_PORT_MIN}:${RTP_PORT_MAX}/udp"
else
  echo "WARN: ufw not found — open ${RTP_PORT_MIN}-${RTP_PORT_MAX}/udp on your cloud firewall too"
fi

# ---------------------------------------------------------------------------
# 3. Portal env: health probe → local ng socket
# ---------------------------------------------------------------------------
touch "$ENVF"
chmod 0640 "$ENVF"
chown root:ownswitch "$ENVF" 2>/dev/null || true
grep -vE '^(RTPENGINE_HOST|RTPENGINE_PORT)=' "$ENVF" > /tmp/own.env.$$ || true
{
  cat /tmp/own.env.$$
  echo "RTPENGINE_HOST=127.0.0.1"
  echo "RTPENGINE_PORT=${RTPENGINE_NG_PORT}"
} > "$ENVF"
rm -f /tmp/own.env.$$
chown root:ownswitch "$ENVF" 2>/dev/null || true
chmod 0640 "$ENVF"
echo "Portal env: RTPENGINE_HOST=127.0.0.1 RTPENGINE_PORT=${RTPENGINE_NG_PORT}"

# Persist ports in install.conf for future stages
if [[ -f "$IC" ]]; then
  grep -vE '^(RTP_PORT_MIN|RTP_PORT_MAX|RTPENGINE_NG_PORT)=' "$IC" > /tmp/ic.$$ || true
  {
    cat /tmp/ic.$$
    echo "RTP_PORT_MIN=${RTP_PORT_MIN}"
    echo "RTP_PORT_MAX=${RTP_PORT_MAX}"
    echo "RTPENGINE_NG_PORT=${RTPENGINE_NG_PORT}"
  } > "$IC"
  rm -f /tmp/ic.$$
fi

# ---------------------------------------------------------------------------
# 4. Keep media Direct by default (no sudden relay for all users)
# ---------------------------------------------------------------------------
if [[ -f /opt/ownswitch/current/bin/migrate.php ]] || [[ -f "$SRC/bin/migrate.php" ]]; then
  PHP_BIN="$(command -v php || true)"
  ENV_FILE="$ENVF"
  SQL="INSERT INTO system_settings (setting_key, setting_value) VALUES ('default_media_policy', 'direct')
       ON DUPLICATE KEY UPDATE setting_value='direct';"
  DB_NAME="$(grep -E '^DB_DATABASE=' "$ENVF" 2>/dev/null | cut -d= -f2- || true)"
  DB_USER="$(grep -E '^DB_USERNAME=' "$ENVF" 2>/dev/null | cut -d= -f2- || true)"
  DB_PASS="$(grep -E '^DB_PASSWORD=' "$ENVF" 2>/dev/null | cut -d= -f2- || true)"
  DB_HOST="$(grep -E '^DB_HOST=' "$ENVF" 2>/dev/null | cut -d= -f2- || echo 127.0.0.1)"
  if [[ -n "$DB_NAME" && -n "$DB_USER" ]] && command -v mysql >/dev/null 2>&1; then
    MYSQL_PWD="$DB_PASS" mysql -h"$DB_HOST" -u"$DB_USER" "$DB_NAME" -e "$SQL" 2>/dev/null \
      && echo "Settings: default_media_policy=direct (existing calls stay peer-to-peer)" \
      || echo "WARN: could not set default_media_policy via mysql (set Direct in Settings UI)"
  else
    echo "WARN: set Settings → Default media policy = Direct in the portal"
  fi
fi

# ---------------------------------------------------------------------------
# 5. OpenSIPS: only ensure rtpengine_sock points at local ng (no full rewrite)
# ---------------------------------------------------------------------------
OS_CFG="${OPENSIPS_CFG:-/etc/opensips/opensips.cfg}"
NEED_OS_RELOAD=0
if [[ -f "$OS_CFG" ]]; then
  if ! grep -qE 'loadmodule\s+"rtpengine\.so"' "$OS_CFG"; then
    echo "WARN: opensips.cfg has no rtpengine.so — Relay/Record will not work until OpenSIPS cfg includes the module"
  else
    WANT="udp:127.0.0.1:${RTPENGINE_NG_PORT}"
    if grep -qE 'modparam\("rtpengine",\s*"rtpengine_sock"' "$OS_CFG"; then
      if ! grep -qF "$WANT" "$OS_CFG"; then
        cp -a "$OS_CFG" "${OS_CFG}.bak.rtpengine.$(date +%Y%m%d%H%M%S)"
        sed -i -E "s|modparam\(\"rtpengine\",\s*\"rtpengine_sock\",\s*\"[^\"]*\"\)|modparam(\"rtpengine\", \"rtpengine_sock\", \"${WANT}\")|" "$OS_CFG"
        NEED_OS_RELOAD=1
        echo "Updated rtpengine_sock → ${WANT}"
      else
        echo "OpenSIPS rtpengine_sock already ${WANT}"
      fi
    else
      echo "WARN: rtpengine.so loaded but rtpengine_sock missing — add: modparam(\"rtpengine\", \"rtpengine_sock\", \"${WANT}\")"
    fi
  fi
else
  echo "WARN: ${OS_CFG} not found"
fi

if (( NEED_OS_RELOAD )); then
  opensips-cli -x mi reload_routes 2>/dev/null || true
  # Prefer MI soft reload of module params if available; else gentle restart
  if ! opensips-cli -x mi rtpengine_show 2>/dev/null; then
    echo "Reloading OpenSIPS to pick up rtpengine_sock (brief blip)…"
    systemctl reload opensips 2>/dev/null || systemctl restart opensips
  fi
else
  echo "OpenSIPS left running (no cfg change requiring restart)"
fi

# ---------------------------------------------------------------------------
# 6. Verify
# ---------------------------------------------------------------------------
sleep 1
systemctl is-active --quiet rtpengine-daemon || systemctl is-active --quiet ngcp-rtpengine-daemon \
  || systemctl is-active --quiet rtpengine || echo "WARN: rtpengine unit not active — check: systemctl status 'rtpengine*'"

if command -v rtpengine-ctl >/dev/null 2>&1; then
  rtpengine-ctl list numsessions 2>/dev/null || true
fi

# Ping ng from localhost (same as portal health)
if command -v php >/dev/null 2>&1; then
  php -r '
    $h="127.0.0.1"; $p='"${RTPENGINE_NG_PORT}"';
    $c=bin2hex(random_bytes(4));
    $msg=$c." d7:command4:pinge";
    $s=@stream_socket_client("udp://$h:$p", $e, $s, 2);
    if(!$s){fwrite(STDERR,"ping fail\n"); exit(1);}
    stream_set_timeout($s,2); fwrite($s,$msg); $r=fread($s,4096); fclose($s);
    echo (str_contains((string)$r,"pong")?"RTPengine ping: pong\n":"RTPengine ping: unexpected\n");
  ' || echo "WARN: php ping failed"
fi

bash "${SRC}/deploy/refresh-ui.sh" || true

echo
echo "################################################################"
echo " RTPengine enabled (safe mode)."
echo "  - Daemon listening ng on 127.0.0.1:${RTPENGINE_NG_PORT}"
echo "  - Media UDP ${RTP_PORT_MIN}-${RTP_PORT_MAX} (open in UFW + cloud FW)"
echo "  - Portal health should show RTPengine ok after refresh"
echo "  - Default media = Direct → current calls unchanged"
echo
echo " To send media via the switch (SDP media IP = your server):"
echo "   Settings → Default media policy = Relay"
echo "   or per SIP user: Media = Relay / Record"
echo
echo " Also open UDP ${RTP_PORT_MIN}-${RTP_PORT_MAX} in Hetzner Cloud Firewall if used."
echo "################################################################"
