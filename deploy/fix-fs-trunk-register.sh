#!/usr/bin/env bash
# Fix "not loaded" / no REGISTER to Magnus-Asterisk for Ownswitch trunks.
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-fs-trunk-register.sh | sudo bash
#
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root" >&2
  exit 1
fi

FS_PREFIX="${FS_PREFIX:-/usr/local/freeswitch}"
FS_CONF="${FREESWITCH_CONF:-${FS_PREFIX}/conf}"
GW_DIR="${FS_CONF}/sip_profiles/external"
OWN_ETC="${OWN_ETC:-/etc/ownswitch}"
OWN_OPT="${OWN_OPT:-/opt/ownswitch}"
ENVF="${OWN_ETC}/ownswitch.env"
FS_CLI="${FS_PREFIX}/bin/fs_cli"

echo "== 1) Refresh portal code =="
curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/refresh-ui.sh | bash

echo
echo "== 2) Gateway dir under FreeSWITCH conf (readable by sofia) =="
# Remove OWN_VAR symlink layouts — FreeSWITCH often cannot traverse /var/lib/ownswitch.
if [[ -L "${GW_DIR}" ]]; then
  old="$(readlink -f "${GW_DIR}" 2>/dev/null || true)"
  echo "Removing symlink ${GW_DIR} -> ${old}"
  rm -f "${GW_DIR}"
  install -d -m 2770 "${GW_DIR}"
  if [[ -n "$old" && -d "$old" ]]; then
    shopt -s nullglob
    for f in "${old}"/gw-*.xml; do mv -n "$f" "${GW_DIR}/" 2>/dev/null || true; done
    shopt -u nullglob
  fi
fi
install -d -m 0755 "${FS_CONF}/sip_profiles"
if id ownswitch >/dev/null 2>&1 && getent group freeswitch >/dev/null 2>&1; then
  install -d -m 2770 -o ownswitch -g freeswitch "${GW_DIR}"
  usermod -aG freeswitch ownswitch 2>/dev/null || true
  # Ensure PHP-FPM can traverse into /usr/local/freeswitch/conf
  chgrp freeswitch "${FS_PREFIX}" "${FS_CONF}" "${FS_CONF}/sip_profiles" 2>/dev/null || true
  chmod 0755 "${FS_PREFIX}" "${FS_CONF}" "${FS_CONF}/sip_profiles" 2>/dev/null || true
  chmod g+rx "${FS_PREFIX}" "${FS_CONF}" "${FS_CONF}/sip_profiles" 2>/dev/null || true
else
  install -d -m 0755 "${GW_DIR}"
fi

# Absolute include path — relative "external/*.xml" resolves under conf/ and misses sip_profiles/external
EXT_XML="${FS_CONF}/sip_profiles/external.xml"
ABS_INC="<X-PRE-PROCESS cmd=\"include\" data=\"${FS_CONF}/sip_profiles/external/*.xml\"/>"
if [[ -f "${EXT_XML}" ]]; then
  if grep -q 'sip_profiles/external/\*\.xml' "${EXT_XML}"; then
    echo "external.xml already uses absolute gateway include"
  else
    cp -a "${EXT_XML}" "${EXT_XML}.bak.$(date +%s)"
    # Replace relative include or inject into <gateways>
    if grep -q 'data="external/\*\.xml"' "${EXT_XML}"; then
      sed -i "s#data=\"external/\*\.xml\"#data=\"${FS_CONF}/sip_profiles/external/*.xml\"#" "${EXT_XML}"
    elif grep -q '<gateways>' "${EXT_XML}"; then
      sed -i "s#<gateways>#<gateways>\n    ${ABS_INC}#" "${EXT_XML}"
    fi
    echo "Patched absolute gateway include into external.xml"
  fi
fi

echo
echo "== 3) PHP open_basedir must allow /usr/local/freeswitch =="
patched=0
for f in /etc/php/*/fpm/pool.d/ownswitch.conf /etc/php/*/fpm/pool.d/*.conf; do
  [[ -f "$f" ]] || continue
  grep -q 'open_basedir' "$f" 2>/dev/null || continue
  if ! grep -q '/usr/local/freeswitch' "$f"; then
    sed -i 's|php_admin_value\[open_basedir\] = \(.*\)|php_admin_value[open_basedir] = \1:/usr/local/freeswitch|' "$f"
    echo "Patched $f"
    patched=1
  fi
done
if [[ "$patched" -eq 1 ]]; then
  systemctl restart php8.3-fpm 2>/dev/null || systemctl restart php8.2-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null || true
fi

# Persist gateway dir in env
if [[ -f "$ENVF" ]]; then
  if grep -q '^FREESWITCH_GATEWAY_DIR=' "$ENVF"; then
    sed -i "s|^FREESWITCH_GATEWAY_DIR=.*|FREESWITCH_GATEWAY_DIR=${GW_DIR}|" "$ENVF"
  else
    printf '\nFREESWITCH_GATEWAY_DIR=%s\n' "${GW_DIR}" >> "$ENVF"
  fi
fi

echo
echo "== 4) Sync trunks from DB into ${GW_DIR} =="
SYNC="${OWN_OPT}/current/bin/sync-fs-trunks.php"
if [[ ! -f "$SYNC" ]]; then
  SYNC="${OWN_OPT}/current/bin/sync-opensips-trunks.php"
fi
if [[ -f "$SYNC" ]]; then
  runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENVF" php "$SYNC" || \
    env OWNSWITCH_ENV_FILE="$ENVF" php "$SYNC" || true
else
  echo "WARN: sync script missing — re-save trunk in portal after this fix"
fi

echo
echo "== 5) Force Sofia to load + REGISTER =="
ESL_PASS=""
[[ -f "$ENVF" ]] && ESL_PASS="$(grep -E '^FREESWITCH_ESL_PASSWORD=' "$ENVF" | head -1 | cut -d= -f2- || true)"
cli() {
  if [[ -n "$ESL_PASS" ]]; then
    "$FS_CLI" -p "$ESL_PASS" -x "$1"
  else
    "$FS_CLI" -x "$1"
  fi
}

if [[ -x "$FS_CLI" ]]; then
  cli 'reloadxml' || true
  # Full profile restart reliably reloads gateways (brief SIP blip).
  cli 'sofia profile external restart' || true
  sleep 2
  shopt -s nullglob
  for f in "${GW_DIR}"/gw-*.xml; do
    base="$(basename "$f" .xml)"
    echo "---- ${base} ----"
    grep -E 'name="(register|username|proxy|realm|password)"' "$f" | sed 's/password" value="[^"]*"/password" value="***"/g' || true
    cli "sofia profile external register ${base}" 2>/dev/null || true
    cli "sofia status gateway ${base}" 2>/dev/null | head -n 25 || true
  done
  shopt -u nullglob
  echo
  echo "All gateways:"
  cli 'sofia status' 2>/dev/null | head -n 80 || true
else
  echo "WARN: fs_cli missing"
fi

echo
echo "Gateway files now:"
ls -la "${GW_DIR}"

echo
echo "If still not REGED: check Magnus peer user/pass and that Asterisk allows this server public IP."
echo "Portal list column should show REGED after refresh (hard-reload the page)."
