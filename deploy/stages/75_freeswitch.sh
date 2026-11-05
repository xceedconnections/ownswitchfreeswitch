#!/usr/bin/env bash
# Stage 75 - FreeSWITCH 1.10.x is the SIP edge (UDP/TCP ${SIP_PORT}).
#
# SignalWire's apt repository needs a personal access token and only officially supports Debian,
# so on Ubuntu 24.04 we BUILD FROM SOURCE (tag ${FREESWITCH_VERSION}) into /usr/local/freeswitch.
# Sofia external binds 0.0.0.0:${SIP_PORT} and advertises ${PUBLIC_IP}. ESL stays on 127.0.0.1:8021.
# mod_xml_curl and mod_xml_cdr call the portal on the loopback vhost.
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
stage_init "75_freeswitch"

if ! is_true "${FREESWITCH_ENABLED}"; then
  log_info "FREESWITCH_ENABLED=${FREESWITCH_ENABLED} - skipping FreeSWITCH"
  record_result "${STAGE_FILE}" "SKIPPED" "FREESWITCH_ENABLED=0"
  exit 0
fi

FS_PREFIX="/usr/local/freeswitch"
export FS_PREFIX
MARK="${FS_PREFIX}/.ownswitch-installed-version"

# ---------------------------------------------------------------------------
# 1. Build + install (skipped when the right version is already present)
# ---------------------------------------------------------------------------
if [[ -x "${FS_PREFIX}/bin/freeswitch" && "$(cat "${MARK}" 2>/dev/null || true)" == "${FREESWITCH_VERSION}" ]]; then
  log_ok "FreeSWITCH ${FREESWITCH_VERSION} already built"
else
  log_info "Building FreeSWITCH ${FREESWITCH_VERSION} from source (20-40 minutes)"
  # Ubuntu 24.04 deps used by FS 1.10.x + sofia/spandsp/libks (no ffmpeg — mod_av disabled).
  apt_install build-essential cmake automake autoconf libtool libtool-bin pkg-config git \
    libssl-dev zlib1g-dev libdb-dev unixodbc-dev libncurses-dev libexpat1-dev libgdbm-dev bison \
    libtiff-dev uuid-dev libpcre3-dev libedit-dev libsqlite3-dev libcurl4-openssl-dev nasm \
    libogg-dev libspeex-dev libspeexdsp-dev libldns-dev libsndfile1-dev libopus-dev yasm \
    libjpeg-dev libpq-dev libxml2-dev liblua5.2-dev libflac-dev libvorbis-dev

  work="$(mktemp -d /tmp/fs-build.XXXXXX)"
  trap 'rm -rf "$work"' EXIT
  jobs_n="$(nproc)"
  export PKG_CONFIG_PATH="/usr/lib/pkgconfig:/usr/local/lib/pkgconfig${PKG_CONFIG_PATH:+:$PKG_CONFIG_PATH}"

  # libks: shallow clones have no tags → Debian packaging changelog CMake crashes
  # ("string REPLACE requires at least four arguments"). Skip packaging + libbacktrace
  # (ExternalProject git TIMEOUT 10 is flaky). Ownswitch does not need SignalWire cloud.
  log_info "Building libks…"
  git clone --quiet --depth 1 https://github.com/signalwire/libks.git "$work/libks"
  ( cd "$work/libks" \
      && cmake . -DCMAKE_INSTALL_PREFIX=/usr -DWITH_PACKAGING=OFF -DWITH_LIBBACKTRACE=OFF \
      && make -j"$jobs_n" && make install )

  # sofia-sip (pin release known-good with FS 1.10.12 / Ubuntu 24.04)
  log_info "Building sofia-sip…"
  git clone --quiet --depth 1 --branch v1.13.17 https://github.com/freeswitch/sofia-sip.git "$work/sofia-sip"
  ( cd "$work/sofia-sip" && ./bootstrap.sh && ./configure CFLAGS="-g -ggdb" --with-pic --with-glib=no \
      --without-doxygen --disable-stun --prefix=/usr && make -j"$jobs_n" && make install )

  # spandsp (FreeSWITCH 1.10.x requires this commit — master breaks mod_spandsp)
  log_info "Building spandsp…"
  git clone --quiet https://github.com/freeswitch/spandsp.git "$work/spandsp"
  ( cd "$work/spandsp" && git checkout --quiet 0d2e6ac65e0e8f53d652665a743015a88bf048d4 && ./bootstrap.sh \
      && ./configure CFLAGS="-g -ggdb" --with-pic --prefix=/usr && make -j"$jobs_n" && make install )

  # signalwire-c is optional (mod_signalwire). Skip it — Ownswitch uses ESL/xml_curl only.
  ldconfig

  # FreeSWITCH itself — force classic layout under prefix/conf (default FHS uses etc/freeswitch).
  log_info "Building FreeSWITCH ${FREESWITCH_VERSION}…"
  git clone --quiet --depth 1 --branch "${FREESWITCH_VERSION}" https://github.com/signalwire/freeswitch.git "$work/freeswitch"
  ( cd "$work/freeswitch" \
    && ./bootstrap.sh -j \
    && sed -i -E \
         -e 's/^(applications|formats)\/mod_av/#\1\/mod_av/' \
         -e 's/^applications\/mod_signalwire/#applications\/mod_signalwire/' \
         -e 's|^#?xml_int/mod_xml_curl|xml_int/mod_xml_curl|' \
         -e 's|^#?xml_int/mod_xml_cdr|xml_int/mod_xml_cdr|' \
         modules.conf \
    && grep -q '^xml_int/mod_xml_curl' modules.conf || echo 'xml_int/mod_xml_curl' >> modules.conf \
    && ./configure --prefix="${FS_PREFIX}" --sysconfdir="${FS_PREFIX}/conf" \
         --enable-portable-binary --disable-dependency-tracking \
    && make -j"$jobs_n" && make install \
    && { [[ -f "${FS_PREFIX}/conf/freeswitch.xml" ]] || make samples; } )
  [[ -x "${FS_PREFIX}/bin/freeswitch" ]] || die "FreeSWITCH build did not produce ${FS_PREFIX}/bin/freeswitch"
  # Ensure runtime can resolve libfreeswitch.so without a manual ld.so.conf edit.
  printf '%s\n' "${FS_PREFIX}/lib" > /etc/ld.so.conf.d/freeswitch.conf
  ldconfig
  printf '%s\n' "${FREESWITCH_VERSION}" >"${MARK}"
  note_change "built FreeSWITCH ${FREESWITCH_VERSION}"
  rm -rf "$work"; trap - EXIT
fi

# Newer FreeSWITCH "make install" may place vanilla configs under etc/freeswitch when
# --sysconfdir was not set. Ownswitch + systemd always use ${FS_PREFIX}/conf.
ensure_fs_conf() {
  local conf_dir="${FS_PREFIX}/conf"
  local etc_dir="${FS_PREFIX}/etc/freeswitch"
  if [[ -f "${conf_dir}/freeswitch.xml" ]]; then
    return 0
  fi
  if [[ -f "${etc_dir}/freeswitch.xml" ]]; then
    log_info "Migrating FreeSWITCH config ${etc_dir} → ${conf_dir}"
    if [[ -e "$conf_dir" && ! -L "$conf_dir" ]]; then
      mv "$conf_dir" "${conf_dir}.bak.$(date -u +%Y%m%d%H%M%S)"
    fi
    rm -f "$conf_dir"
    mv "$etc_dir" "$conf_dir"
    install -d -m 0755 "$(dirname "$etc_dir")"
    ln -sfn "$conf_dir" "$etc_dir"
    note_change "moved FreeSWITCH config to ${conf_dir}"
    return 0
  fi
  # Last resort: re-seed samples into conf/ without a full rebuild.
  if [[ -x "${FS_PREFIX}/bin/freeswitch" ]]; then
    log_warn "No FreeSWITCH samples found; cloning vanilla conf into ${conf_dir}"
    local tmp
    tmp="$(mktemp -d /tmp/fs-conf.XXXXXX)"
    git clone --quiet --depth 1 --branch "${FREESWITCH_VERSION}" \
      https://github.com/signalwire/freeswitch.git "$tmp/fs"
    install -d -m 0750 "$conf_dir"
    cp -a "$tmp/fs/conf/vanilla/." "$conf_dir/"
    rm -rf "$tmp"
    [[ -f "${conf_dir}/freeswitch.xml" ]] || return 1
    note_change "seeded FreeSWITCH vanilla conf into ${conf_dir}"
    return 0
  fi
  return 1
}
ensure_fs_conf || die "FreeSWITCH config dir missing: ${FS_PREFIX}/conf (and no etc/freeswitch to migrate)"
# Runtime libs (libfreeswitch.so) live under prefix/lib on source installs.
if [[ ! -f /etc/ld.so.conf.d/freeswitch.conf ]]; then
  printf '%s\n' "${FS_PREFIX}/lib" > /etc/ld.so.conf.d/freeswitch.conf
  ldconfig
fi

ensure_system_user freeswitch "${FS_PREFIX}"
chown -R freeswitch:freeswitch "${FS_PREFIX}"
chmod -R o-rwx "${FS_PREFIX}"
install -d -m 0750 -o freeswitch -g freeswitch \
  "${FS_PREFIX}/log" "${FS_PREFIX}/db" "${FS_PREFIX}/storage" \
  "${FS_PREFIX}/recordings" "${FS_PREFIX}/sounds" "${FS_PREFIX}/run"

# ---------------------------------------------------------------------------
# 2. Configuration: SIP 5060, RTP range, ESL, xml_curl, xml_cdr
# ---------------------------------------------------------------------------
conf="${FS_PREFIX}/conf"
[[ -f "${conf}/freeswitch.xml" ]] || die "FreeSWITCH config dir missing: ${conf}/freeswitch.xml"
fs_changed=0

set_var() { # file regex-old new
  local file=$1 old=$2 new=$3
  if [[ -f "$file" ]] && grep -qE "$old" "$file"; then
    sed -i -E "s|${old}|${new}|" "$file"; fs_changed=1
  fi
}
# Only the external profile is loaded, and it binds SIP_PORT. Internal 5090/5091 profiles are not used.
for prof in internal.xml internal-ipv6.xml; do
  if [[ -f "${conf}/sip_profiles/${prof}" ]]; then
    mv "${conf}/sip_profiles/${prof}" "${conf}/sip_profiles/${prof}.disabled"
    fs_changed=1
  fi
done
set_var "${conf}/vars.xml" 'external_sip_port=[0-9]+' "external_sip_port=${SIP_PORT}"
sed -i -E 's|<!--\s*(<param name="rtp-start-port"[^>]*/>)\s*-->|\1|; s|<!--\s*(<param name="rtp-end-port"[^>]*/>)\s*-->|\1|' \
  "${conf}/autoload_configs/switch.conf.xml" 2>/dev/null || true
set_var "${conf}/autoload_configs/switch.conf.xml" 'name="rtp-start-port" value="[0-9]+"' "name=\"rtp-start-port\" value=\"${RTP_PORT_MIN}\""
set_var "${conf}/autoload_configs/switch.conf.xml" 'name="rtp-end-port" value="[0-9]+"'   "name=\"rtp-end-port\" value=\"${RTP_PORT_MAX}\""
set_var "${conf}/vars.xml" 'default_password=[^"]+' "default_password=$(ensure_secret freeswitch_default_password 32)"

mods="${conf}/autoload_configs/modules.conf.xml"
enable_mod() {
  local mod=$1
  [[ -f "$mods" ]] || return 0
  if grep -q "module=\"${mod}\"" "$mods"; then
    sed -i -E "s|<!--[[:space:]]*(<load module=\"${mod}\"/>)[[:space:]]*-->|\1|" "$mods"
  else
    sed -i "s|</modules>|  <load module=\"${mod}\"/>\n</modules>|" "$mods"
  fi
  fs_changed=1
}
enable_mod mod_xml_curl
enable_mod mod_xml_cdr
enable_mod mod_sofia

export FS_ESL_PASSWORD FS_XML_SECRET FS_PREFIX
FS_ESL_PASSWORD="$(ensure_secret freeswitch_esl_password 40)"
FS_XML_SECRET="$(ensure_secret freeswitch_xml_secret 40)"
render_template "${TEMPLATE_DIR}/freeswitch-event_socket.conf.xml.tpl" "${conf}/autoload_configs/event_socket.conf.xml" 0640 freeswitch freeswitch
(( RENDER_CHANGED )) && fs_changed=1
render_template "${TEMPLATE_DIR}/freeswitch/external.xml.tpl" "${conf}/sip_profiles/external.xml" 0640 freeswitch freeswitch
(( RENDER_CHANGED )) && fs_changed=1
render_template "${TEMPLATE_DIR}/freeswitch/xml_curl.conf.xml.tpl" "${conf}/autoload_configs/xml_curl.conf.xml" 0640 freeswitch freeswitch
(( RENDER_CHANGED )) && fs_changed=1
render_template "${TEMPLATE_DIR}/freeswitch/xml_cdr.conf.xml.tpl" "${conf}/autoload_configs/xml_cdr.conf.xml" 0640 freeswitch freeswitch
(( RENDER_CHANGED )) && fs_changed=1
install -d -m 0750 -o freeswitch -g freeswitch "${FS_PREFIX}/log/xml_cdr"
# Portal (ownswitch) writes gw-*.xml here; FreeSWITCH (group) reads via external/*.xml include.
# Keep a real directory under conf (do NOT symlink to OWN_VAR — freeswitch often cannot traverse it).
GW_DIR="${conf}/sip_profiles/external"
if [[ -L "${GW_DIR}" ]]; then
  # Migrate away from broken OWN_VAR symlink layouts.
  target="$(readlink -f "${GW_DIR}" 2>/dev/null || true)"
  rm -f "${GW_DIR}"
  install -d -m 2770 "${GW_DIR}"
  if [[ -n "$target" && -d "$target" ]]; then
    shopt -s nullglob
    for f in "${target}"/gw-*.xml; do
      mv -n "$f" "${GW_DIR}/" 2>/dev/null || true
    done
    shopt -u nullglob
  fi
fi
if id ownswitch >/dev/null 2>&1; then
  install -d -m 2770 -o ownswitch -g freeswitch "${GW_DIR}"
  usermod -aG freeswitch ownswitch 2>/dev/null || true
  chown -R ownswitch:freeswitch "${GW_DIR}"
  find "${GW_DIR}" -type f -exec chmod 0660 {} + 2>/dev/null || true
else
  install -d -m 0750 -o freeswitch -g freeswitch "${GW_DIR}"
fi



if [[ -f "${OWN_ETC}/ownswitch.env" ]]; then
  upsert_env() {
    local key=$1 val=$2 file="${OWN_ETC}/ownswitch.env" tmp
    tmp="$(mktemp)"
    if grep -q "^${key}=" "$file"; then
      awk -v k="$key" -v v="$val" 'BEGIN{FS=OFS="="} $1==k {$0=k "=" v} {print}' "$file" >"$tmp"
    else
      cp "$file" "$tmp"
      printf '%s=%s\n' "$key" "$val" >>"$tmp"
    fi
    cat "$tmp" >"$file"
    rm -f "$tmp"
    chmod 0640 "$file"
    chown root:ownswitch "$file" 2>/dev/null || true
  }
  upsert_env FREESWITCH_ENABLED 1
  upsert_env FREESWITCH_ESL_HOST 127.0.0.1
  upsert_env FREESWITCH_ESL_PORT 8021
  upsert_env FREESWITCH_ESL_PASSWORD "${FS_ESL_PASSWORD}"
  upsert_env FS_XML_SECRET "${FS_XML_SECRET}"
  upsert_env FREESWITCH_CONF "${conf}"
fi

render_template "${TEMPLATE_DIR}/systemd/freeswitch.service" /etc/systemd/system/freeswitch.service 0644 root root
if [[ "${RENDER_CHANGED}" == "1" ]]; then daemon_reload; fs_changed=1; fi

if systemctl is-active --quiet opensips; then
  log_warn "Stopping OpenSIPS so FreeSWITCH can bind UDP/TCP ${SIP_PORT}"
  systemctl stop opensips || true
  fs_changed=1
fi

# Clear any crash-loop from a previous bad unit (LimitSTACK / ExecStartPre) before start.
systemctl stop freeswitch 2>/dev/null || true
systemctl reset-failed freeswitch 2>/dev/null || true
printf '%s\n' "${FS_PREFIX}/lib" > /etc/ld.so.conf.d/freeswitch.conf
ldconfig

(( fs_changed )) && note_change "FreeSWITCH configuration updated"
# Always (re)start after unit/config changes — pass 1 so inactive units are started.
svc_apply freeswitch 1

# ---------------------------------------------------------------------------
# 3. Verify ESL is loopback only
# ---------------------------------------------------------------------------
wait_for_tcp 127.0.0.1 8021 60 || { journalctl -u freeswitch -n 40 --no-pager >&2 || true; die "FreeSWITCH ESL did not come up on 127.0.0.1:8021"; }
if ss -H -ltn 'sport = :8021' | awk '{print $4}' | grep -vqx '127.0.0.1:8021'; then
  die "FreeSWITCH ESL is listening on a non-loopback address!"
fi
persist_setting FREESWITCH_ENABLED 1
log_ok "FreeSWITCH ${FREESWITCH_VERSION} running; ESL 127.0.0.1:8021; SIP ${SIP_PORT} advertising ${PUBLIC_IP}"
