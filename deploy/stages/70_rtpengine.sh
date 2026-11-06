#!/usr/bin/env bash
# Stage 70 - RTPengine 11.5.x (Ubuntu noble universe) + recording daemon.
# Kernel forwarding (DKMS) is attempted; if unavailable we fall back to userspace mode.
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
stage_init "70_rtpengine"

ensure_base_layout

# universe must be enabled (cloud images normally have it)
if ! grep -rqsE '^(Components:.*universe|deb .* universe)' /etc/apt/sources.list /etc/apt/sources.list.d/ 2>/dev/null; then
  add-apt-repository -y universe >/dev/null
  apt_update
fi

# ---------------------------------------------------------------------------
# 1. Packages
# ---------------------------------------------------------------------------
# Package names differ between Debian/Ubuntu generations (rtpengine-* vs ngcp-rtpengine-*)
pick_pkg() { local p; for p in "$@"; do if [[ -n "$(apt-cache policy "$p" 2>/dev/null | awk '/Candidate:/ && $2 != "(none)" {print $2}')" ]]; then printf '%s' "$p"; return 0; fi; done; return 1; }
PKG_DAEMON="$(pick_pkg rtpengine-daemon ngcp-rtpengine-daemon || true)"
PKG_RECORDING="$(pick_pkg rtpengine-recording-daemon ngcp-rtpengine-recording-daemon || true)"
PKG_DKMS="$(pick_pkg rtpengine-kernel-dkms ngcp-rtpengine-kernel-dkms || true)"
[[ -n "$PKG_DAEMON" && -n "$PKG_RECORDING" ]] || die "RTPengine packages not found in apt (is universe enabled?)"
apt_install "$PKG_DAEMON" "$PKG_RECORDING"
rt_ver="$(dpkg-query -W -f='${Version}' "$PKG_DAEMON" 2>/dev/null || true)"
log_info "${PKG_DAEMON} ${rt_ver}"

kernel_ok=0
if is_true "${RTPENGINE_KERNEL}"; then
  if [[ ! -d /sys/module/xt_RTPENGINE ]]; then
    apt_install_optional "linux-headers-$(uname -r)" ${PKG_DKMS:+"$PKG_DKMS"}
    modprobe xt_RTPENGINE 2>/dev/null || true
  fi
  if [[ -d /sys/module/xt_RTPENGINE ]]; then kernel_ok=1; log_ok "xt_RTPENGINE kernel module loaded"
  else log_warn "Kernel module unavailable (headers/DKMS?). Running RTPengine in USERSPACE mode (lower capacity)."; fi
fi

# ---------------------------------------------------------------------------
# 2. Directories
# ---------------------------------------------------------------------------
export RTPENGINE_SPOOL_DIR="/var/spool/rtpengine"
export RECORDINGS_DIR="${OWN_VAR}/recordings"
rt_user="root"; getent passwd rtpengine >/dev/null && rt_user="rtpengine"
install -d -m 0750 -o "$rt_user" -g "$rt_user" "${RTPENGINE_SPOOL_DIR}"
# Output readable by the portal group so it can stream/verify recordings
# FreeSWITCH record_session writes the wav; the portal user reads it.
rec_owner="freeswitch"
getent passwd freeswitch >/dev/null || rec_owner="$rt_user"
install -d -m 2770 -o "$rec_owner" -g ownswitch "${RECORDINGS_DIR}"

# ---------------------------------------------------------------------------
# 3. Configuration
# ---------------------------------------------------------------------------
if (( kernel_ok )); then export RTPENGINE_TABLE="0"; else export RTPENGINE_TABLE="-1"; fi

case "${RTPENGINE_RECORDING_METHOD}" in
  auto) if (( kernel_ok )); then export RTPENGINE_RECORDING_METHOD_EFFECTIVE="proc"; else export RTPENGINE_RECORDING_METHOD_EFFECTIVE="pcap"; fi ;;
  proc|pcap) export RTPENGINE_RECORDING_METHOD_EFFECTIVE="${RTPENGINE_RECORDING_METHOD}" ;;
  *) die "RTPENGINE_RECORDING_METHOD must be auto|proc|pcap" ;;
esac
if [[ "${RTPENGINE_RECORDING_METHOD_EFFECTIVE}" == "proc" ]] && (( ! kernel_ok )); then
  die "recording-method=proc requires the kernel module; use RTPENGINE_RECORDING_METHOD=pcap"
fi

if [[ "${PRIVATE_IP}" != "${PUBLIC_IP}" ]]; then
  export RTPENGINE_INTERFACE="${PRIVATE_IP}!${PUBLIC_IP}"
else
  export RTPENGINE_INTERFACE="${PUBLIC_IP}"
fi

export RECORDING_NOTIFY_TOKEN
RECORDING_NOTIFY_TOKEN="$(ensure_secret recording_notify_token 40)"

# Discover units + the config path each unit actually reads (packaging differs slightly).
unit_for_pkg() { dpkg -L "$1" 2>/dev/null | grep -E '/systemd/system/[^/]+\.service$' | head -n1 | xargs -r basename || true; }
unit_config_path() { # unit default
  local u=$1 d=$2 p
  p="$(systemctl cat "$u" 2>/dev/null | sed -n 's/.*--config-file[= ]\([^ ]*\).*/\1/p' | head -n1 || true)"
  printf '%s' "${p:-$d}"
}

daemon_unit="$(unit_for_pkg "$PKG_DAEMON")"; daemon_unit="${daemon_unit:-rtpengine-daemon.service}"
rec_unit="$(unit_for_pkg "$PKG_RECORDING")"; rec_unit="${rec_unit:-rtpengine-recording.service}"
daemon_conf="$(unit_config_path "$daemon_unit" /etc/rtpengine/rtpengine.conf)"
rec_conf="$(unit_config_path "$rec_unit" /etc/rtpengine/rtpengine-recording.conf)"
log_info "units: ${daemon_unit} (${daemon_conf}), ${rec_unit} (${rec_conf})"

install -d -m 0755 /etc/rtpengine
# daemon conf holds no secrets (readable by the unprivileged rtpengine user); recording conf holds the notify token
rt_group="root"; getent group rtpengine >/dev/null && rt_group="rtpengine"
render_template "${TEMPLATE_DIR}/rtpengine.conf.tpl" "${daemon_conf}" 0644 root root
daemon_changed="${RENDER_CHANGED}"
render_template "${TEMPLATE_DIR}/rtpengine-recording.conf.tpl" "${rec_conf}" 0640 root "${rt_group}"
rec_changed="${RENDER_CHANGED}"

# Some package versions gate startup on /etc/default/rtpengine-*
for d in /etc/default/rtpengine-daemon /etc/default/rtpengine; do
  if [[ -f "$d" ]] && grep -qE '^RUN_RTPENGINE=no' "$d"; then sed -i 's/^RUN_RTPENGINE=no/RUN_RTPENGINE=yes/' "$d"; fi
done
if [[ -f /etc/default/rtpengine-recording ]] && grep -qE '^RUN_RTPENGINE_RECORDING=no' /etc/default/rtpengine-recording; then
  sed -i 's/^RUN_RTPENGINE_RECORDING=no/RUN_RTPENGINE_RECORDING=yes/' /etc/default/rtpengine-recording
fi

systemctl daemon-reload
svc_apply "${daemon_unit}" "${daemon_changed}"
svc_apply "${rec_unit}" "${rec_changed}"

# ---------------------------------------------------------------------------
# 4. Verify control socket is loopback-only
# ---------------------------------------------------------------------------
sleep 1
if ss -H -lun "sport = :${RTPENGINE_NG_PORT}" | awk '{print $4}' | grep -vqx "127.0.0.1:${RTPENGINE_NG_PORT}"; then
  die "RTPengine ng control port ${RTPENGINE_NG_PORT} is not loopback-only!"
fi
if command -v rtpengine-ctl >/dev/null 2>&1; then rtpengine-ctl list numsessions >/dev/null 2>&1 || true; fi

persist_setting RTPENGINE_RECORDING_METHOD_EFFECTIVE "${RTPENGINE_RECORDING_METHOD_EFFECTIVE}"
log_ok "RTPengine ready (kernel=${kernel_ok}, recording=${RTPENGINE_RECORDING_METHOD_EFFECTIVE}, RTP ${RTP_PORT_MIN}-${RTP_PORT_MAX}/udp)"
