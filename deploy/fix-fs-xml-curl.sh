#!/usr/bin/env bash
# Install missing mod_xml_curl.so (SIP REGISTER directory auth) and reload it.
# Compiles the module against the already-installed FreeSWITCH (fast).
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
  echo "== Compiling mod_xml_curl against installed FreeSWITCH (${TAG}) =="
  apt-get install -y -qq build-essential libcurl4-openssl-dev git 2>/dev/null || true
  [[ -f "${FS_PREFIX}/include/freeswitch/switch.h" ]] || { echo "FATAL: FreeSWITCH headers missing under ${FS_PREFIX}/include"; exit 1; }
  work="$(mktemp -d /tmp/fs-xml-curl.XXXXXX)"
  trap 'rm -rf "$work"' EXIT
  git clone --quiet --depth 1 --branch "${TAG}" https://github.com/signalwire/freeswitch.git "$work/fs"
  SRC="$work/fs/src/mod/xml_int/mod_xml_curl/mod_xml_curl.c"
  [[ -f "$SRC" ]] || { echo "FATAL: source missing"; exit 1; }
  gcc -shared -fPIC -O2 -Wall \
    -I"${FS_PREFIX}/include/freeswitch" -I"${FS_PREFIX}/include" \
    -o "$work/mod_xml_curl.so" "$SRC" \
    -L"${FS_PREFIX}/lib" -lfreeswitch -lcurl -lpthread -lm
  install -m 0755 -o freeswitch -g freeswitch "$work/mod_xml_curl.so" "${MOD_DIR}/mod_xml_curl.so"
  ldconfig
  trap - EXIT
  rm -rf "$work"
  [[ -f "${MOD_DIR}/mod_xml_curl.so" ]] || { echo "FATAL: mod_xml_curl.so still missing"; exit 1; }
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
cli 'sofia status gateway gw-1' | head -n 20
ls -la "${MOD_DIR}/mod_xml_curl.so"
echo "Done. Re-REGISTER softphone: Domain=voip.xceedconnections.com OR public IP, User=owner, portal SIP password."
