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
  apt_install build-essential cmake automake autoconf libtool libtool-bin pkg-config git \
    libssl-dev zlib1g-dev libdb-dev unixodbc-dev libncurses-dev libexpat1-dev libgdbm-dev bison \
    libtiff-dev uuid-dev libpcre3-dev libedit-dev libsqlite3-dev libcurl4-openssl-dev nasm \
    libogg-dev libspeex-dev libspeexdsp-dev libldns-dev libsndfile1-dev libopus-dev yasm \
    libjpeg-dev libpq-dev

  work="$(mktemp -d /tmp/fs-build.XXXXXX)"
  trap 'rm -rf "$work"' EXIT
  jobs_n="$(nproc)"

  # libks — shallow clones have no tags; libks CMake packaging changelog then fails with
  # "string REPLACE requires at least four arguments". We install from source, not .deb.
  git clone --quiet --depth 1 https://github.com/signalwire/libks.git "$work/libks"
  ( cd "$work/libks" && cmake . -DCMAKE_INSTALL_PREFIX=/usr -DWITH_LIBBACKTRACE=1 -DWITH_PACKAGING=OFF \
      && make -j"$jobs_n" && make install )
  # sofia-sip
  git clone --quiet --depth 1 https://github.com/freeswitch/sofia-sip.git "$work/sofia-sip"
  ( cd "$work/sofia-sip" && ./bootstrap.sh && ./configure CFLAGS="-g -ggdb" --with-pic --with-glib=no --without-doxygen --disable-stun --prefix=/usr \
      && make -j"$jobs_n" && make install )
  # spandsp (FreeSWITCH 1.10 requires this specific commit)
  git clone --quiet https://github.com/freeswitch/spandsp.git "$work/spandsp"
  ( cd "$work/spandsp" && git checkout --quiet 0d2e6ac65e0e8f53d652665a743015a88bf048d4 && ./bootstrap.sh \
      && ./configure CFLAGS="-g -ggdb" --with-pic --prefix=/usr && make -j"$jobs_n" && make install )
  # signalwire-c
  git clone --quiet --depth 1 https://github.com/signalwire/signalwire-c.git "$work/signalwire-c"
  ( cd "$work/signalwire-c" && cmake . -DCMAKE_INSTALL_PREFIX=/usr && make -j"$jobs_n" && make install )
  ldconfig

  # FreeSWITCH itself
  git clone --quiet --depth 1 --branch "${FREESWITCH_VERSION}" https://github.com/signalwire/freeswitch.git "$work/freeswitch"
  ( cd "$work/freeswitch" \
    && ./bootstrap.sh -j \
    && sed -i -E 's/^(applications|formats)\/mod_av/#\1\/mod_av/' modules.conf \
    && ./configure --prefix="${FS_PREFIX}" --enable-portable-binary --disable-dependency-tracking \
    && make -j"$jobs_n" && make install \
    && { [[ -d "${FS_PREFIX}/conf/vanilla" || -f "${FS_PREFIX}/conf/freeswitch.xml" ]] || make samples; } )
  [[ -x "${FS_PREFIX}/bin/freeswitch" ]] || die "FreeSWITCH build did not produce ${FS_PREFIX}/bin/freeswitch"
  printf '%s\n' "${FREESWITCH_VERSION}" >"${MARK}"
  note_change "built FreeSWITCH ${FREESWITCH_VERSION}"
  rm -rf "$work"; trap - EXIT
fi

ensure_system_user freeswitch "${FS_PREFIX}"
chown -R freeswitch:freeswitch "${FS_PREFIX}"
chmod -R o-rwx "${FS_PREFIX}"
install -d -m 0750 -o freeswitch -g freeswitch "${FS_PREFIX}/log" "${FS_PREFIX}/db"

# ---------------------------------------------------------------------------
# 2. Configuration: SIP 5060, RTP range, ESL, xml_curl, xml_cdr
# ---------------------------------------------------------------------------
conf="${FS_PREFIX}/conf"
[[ -d "$conf" ]] || die "FreeSWITCH config dir missing: ${conf}"
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
# PHP-FPM runs as ownswitch and writes gateway XML. FreeSWITCH (group) must be able to read it.
if id ownswitch >/dev/null 2>&1; then
  install -d -m 2770 -o ownswitch -g freeswitch "${conf}/sip_profiles/external"
  chown -R ownswitch:freeswitch "${conf}/sip_profiles/external"
  find "${conf}/sip_profiles/external" -type f -exec chmod 0660 {} +
else
  install -d -m 0750 -o freeswitch -g freeswitch "${conf}/sip_profiles/external"
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

(( fs_changed )) && note_change "FreeSWITCH configuration updated"
svc_apply freeswitch "${fs_changed}"

# ---------------------------------------------------------------------------
# 3. Verify ESL is loopback only
# ---------------------------------------------------------------------------
wait_for_tcp 127.0.0.1 8021 60 || { journalctl -u freeswitch -n 40 --no-pager >&2 || true; die "FreeSWITCH ESL did not come up on 127.0.0.1:8021"; }
if ss -H -ltn 'sport = :8021' | awk '{print $4}' | grep -vqx '127.0.0.1:8021'; then
  die "FreeSWITCH ESL is listening on a non-loopback address!"
fi
persist_setting FREESWITCH_ENABLED 1
log_ok "FreeSWITCH ${FREESWITCH_VERSION} running; ESL 127.0.0.1:8021; SIP ${SIP_PORT} advertising ${PUBLIC_IP}"
