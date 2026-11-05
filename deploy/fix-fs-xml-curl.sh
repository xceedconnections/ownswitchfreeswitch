#!/usr/bin/env bash
# Build/install missing mod_xml_curl.so and reload it (SIP REGISTER directory auth).
#   curl -fsSL …/deploy/fix-fs-xml-curl.sh | sudo bash
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

FS_PREFIX="${FS_PREFIX:-/usr/local/freeswitch}"
MOD_DIR="${FS_PREFIX}/lib/freeswitch/mod"
ENVF="${OWN_ETC:-/etc/ownswitch}/ownswitch.env"
FS_CLI="${FS_PREFIX}/bin/fs_cli"
MARK="${FS_PREFIX}/.ownswitch-installed-version"
VER="$(cat "$MARK" 2>/dev/null || echo v1.10.12)"
VER="${VER#v}"
TAG="v${VER}"

echo "== FreeSWITCH module dir =="
ls -la "${MOD_DIR}/mod_xml_cdr.so" "${MOD_DIR}/mod_xml_curl.so" 2>/dev/null || true

if [[ ! -f "${MOD_DIR}/mod_xml_curl.so" ]]; then
  echo "== Building mod_xml_curl (${TAG}) =="
  apt-get install -y -qq build-essential automake autoconf libtool libtool-bin pkg-config \
    libcurl4-openssl-dev libssl-dev zlib1g-dev libpcre3-dev libedit-dev libsqlite3-dev \
    libspeex-dev libspeexdsp-dev libldns-dev libopus-dev libsndfile1-dev libpq-dev \
    libxml2-dev uuid-dev libtiff-dev 2>/dev/null || true
  work="$(mktemp -d /tmp/fs-xml-curl.XXXXXX)"
  trap 'rm -rf "$work"' EXIT
  git clone --quiet --depth 1 --branch "${TAG}" https://github.com/signalwire/freeswitch.git "$work/fs"
  (
    cd "$work/fs"
    ./bootstrap.sh -j
    # Match stage 75: skip signalwire/av; force-enable xml_curl + xml_cdr
    sed -i -E \
      -e 's/^(applications|formats)\/mod_av/#\1\/mod_av/' \
      -e 's/^applications\/mod_signalwire/#applications\/mod_signalwire/' \
      -e 's|^#?xml_int/mod_xml_curl|xml_int/mod_xml_curl|' \
      -e 's|^#?xml_int/mod_xml_cdr|xml_int/mod_xml_cdr|' \
      modules.conf
    grep -qE '^xml_int/mod_xml_curl' modules.conf || echo 'xml_int/mod_xml_curl' >> modules.conf
    ./configure --prefix="${FS_PREFIX}" --sysconfdir="${FS_PREFIX}/conf" \
      --enable-portable-binary --disable-dependency-tracking
    make -j"$(nproc)" -C src/mod/xml_int/mod_xml_curl
    make -C src/mod/xml_int/mod_xml_curl install
  )
  ldconfig
  if [[ ! -f "${MOD_DIR}/mod_xml_curl.so" ]]; then
    find "$work/fs" -name 'mod_xml_curl.so' -exec cp -av {} "${MOD_DIR}/" \;
  fi
  [[ -f "${MOD_DIR}/mod_xml_curl.so" ]] || { echo "FATAL: mod_xml_curl.so still missing"; exit 1; }
  chown freeswitch:freeswitch "${MOD_DIR}/mod_xml_curl.so" 2>/dev/null || true
  trap - EXIT
  rm -rf "$work"
fi

MODS="${FS_PREFIX}/conf/autoload_configs/modules.conf.xml"
if [[ -f "$MODS" ]] && ! grep -q '<load module="mod_xml_curl"/>' "$MODS"; then
  sed -i 's#</modules>#    <load module="mod_xml_curl"/>\n</modules>#' "$MODS"
fi

# Profile XML includes are relative to sip_profiles/ → external/*.xml
EXT_XML="${FS_PREFIX}/conf/sip_profiles/external.xml"
GOOD='<X-PRE-PROCESS cmd="include" data="external/*.xml"/>'
if [[ -f "$EXT_XML" ]]; then
  cp -a "$EXT_XML" "${EXT_XML}.bak.curl.$(date +%s)"
  sed -i '/X-PRE-PROCESS cmd="include".*external\/\*\.xml/d' "$EXT_XML"
  if grep -q '<gateways>' "$EXT_XML"; then
    sed -i "s#<gateways>#<gateways>\n    ${GOOD}#" "$EXT_XML"
  fi
  echo "Patched gateway include → external/*.xml"
  grep -n include "$EXT_XML" | head
fi

ESL_PASS=""
[[ -f "$ENVF" ]] && ESL_PASS="$(grep -E '^FREESWITCH_ESL_PASSWORD=' "$ENVF" | head -1 | cut -d= -f2- || true)"
cli() {
  if [[ -n "$ESL_PASS" ]]; then "$FS_CLI" -p "$ESL_PASS" -x "$1"; else "$FS_CLI" -x "$1"; fi
}

echo "== Load mod_xml_curl =="
cli 'load mod_xml_curl' || true
sleep 1
cli 'module_exists mod_xml_curl'
cli 'xml_flush_cache' || true
cli 'reloadxml' || true
cli 'sofia profile external rescan' || true
sleep 1
cli 'sofia profile external register gw-1' || true
sleep 2
echo "== Verify =="
cli 'module_exists mod_xml_curl'
cli 'user_exists id owner voip.xceedconnections.com'
cli 'sofia status gateway gw-1' | head -n 25
ls -la "${MOD_DIR}/mod_xml_curl.so"
echo "Done. Re-REGISTER softphone: Domain=voip.xceedconnections.com OR 62.238.34.202, User=owner, same portal password."
