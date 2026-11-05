#!/usr/bin/env bash
# Fix FreeSWITCH trunk gateway sync for the Ownswitch portal.
# Creates a writable gateway dir inside open_basedir and links it into Sofia.
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-fs-trunk-sync.sh | sudo bash
#
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root" >&2
  exit 1
fi

FS_PREFIX="${FS_PREFIX:-/usr/local/freeswitch}"
FS_CONF="${FREESWITCH_CONF:-${FS_PREFIX}/conf}"
OWN_VAR="${OWN_VAR:-/var/lib/ownswitch}"
OWN_ETC="${OWN_ETC:-/etc/ownswitch}"
GW_VAR="${OWN_VAR}/freeswitch/gateways"
PROFILES="${FS_CONF}/sip_profiles"
EXT_LINK="${PROFILES}/external"

echo "== Fix FreeSWITCH trunk sync =="
echo "FS_CONF=$FS_CONF"
echo "GW_VAR=$GW_VAR"

# 1) Writable gateway store (PHP-FPM / ownswitch can write here)
install -d -m 0755 "${OWN_VAR}/freeswitch"
if id ownswitch >/dev/null 2>&1 && getent group freeswitch >/dev/null 2>&1; then
  install -d -m 2770 -o ownswitch -g freeswitch "${GW_VAR}"
  usermod -aG freeswitch ownswitch 2>/dev/null || true
elif id ownswitch >/dev/null 2>&1; then
  install -d -m 2770 -o ownswitch -g ownswitch "${GW_VAR}"
else
  install -d -m 0755 "${GW_VAR}"
fi

# 2) Point Sofia external/*.xml include at that store
if [[ ! -d "${FS_CONF}" ]]; then
  echo "WARN: ${FS_CONF} missing — FreeSWITCH conf not installed yet"
else
  install -d -m 0755 "${PROFILES}"
  if [[ -L "${EXT_LINK}" ]]; then
    ln -sfn "${GW_VAR}" "${EXT_LINK}"
    echo "Updated symlink ${EXT_LINK} -> ${GW_VAR}"
  elif [[ -d "${EXT_LINK}" ]]; then
    # Move any existing gw xml into the var store, then replace dir with symlink
    shopt -s nullglob
    for f in "${EXT_LINK}"/*.xml; do
      mv -n "$f" "${GW_VAR}/" 2>/dev/null || true
    done
    shopt -u nullglob
    rm -rf "${EXT_LINK}"
    ln -sfn "${GW_VAR}" "${EXT_LINK}"
    echo "Replaced ${EXT_LINK} with symlink -> ${GW_VAR}"
  else
    ln -sfn "${GW_VAR}" "${EXT_LINK}"
    echo "Created symlink ${EXT_LINK} -> ${GW_VAR}"
  fi
  # FreeSWITCH must be able to follow/read the symlink target
  if getent group freeswitch >/dev/null 2>&1; then
    chown -h freeswitch:freeswitch "${EXT_LINK}" 2>/dev/null || true
    chmod 0755 "${OWN_VAR}/freeswitch" 2>/dev/null || true
  fi
fi

# 3) Ensure open_basedir allows OWN_VAR (and FS prefix as fallback)
patched=0
for f in /etc/php/*/fpm/pool.d/ownswitch.conf /etc/php/*/fpm/pool.d/*.conf; do
  [[ -f "$f" ]] || continue
  grep -q 'ownswitch\|open_basedir' "$f" 2>/dev/null || continue
  if grep -q 'open_basedir' "$f"; then
    if ! grep -E 'open_basedir.*=.*(/usr/local/freeswitch|'"${OWN_VAR}"')' "$f" >/dev/null 2>&1; then
      sed -i "s|php_admin_value\[open_basedir\] = \(.*\)|php_admin_value[open_basedir] = \1:${OWN_VAR}:/usr/local/freeswitch|" "$f"
      echo "Patched open_basedir in $f"
      patched=1
    fi
  fi
done

# 4) Persist env hint
ENVF="${OWN_ETC}/ownswitch.env"
if [[ -f "$ENVF" ]]; then
  if grep -q '^FREESWITCH_GATEWAY_DIR=' "$ENVF"; then
    sed -i "s|^FREESWITCH_GATEWAY_DIR=.*|FREESWITCH_GATEWAY_DIR=${GW_VAR}|" "$ENVF"
  else
    printf '\nFREESWITCH_GATEWAY_DIR=%s\n' "${GW_VAR}" >> "$ENVF"
  fi
  echo "Set FREESWITCH_GATEWAY_DIR=${GW_VAR}"
fi

# 5) Refresh portal code so provisioner writes to OWN_VAR
echo
echo "== Refreshing portal UI/code =="
curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/refresh-ui.sh | bash

# 6) Rescan Sofia
if [[ -x "${FS_PREFIX}/bin/fs_cli" ]]; then
  "${FS_PREFIX}/bin/fs_cli" -x 'sofia profile external rescan' 2>/dev/null || true
fi

if [[ "$patched" -eq 1 ]]; then
  systemctl restart php8.3-fpm 2>/dev/null || systemctl restart php8.2-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null || true
fi

echo
echo "Done. Re-save the trunk in the portal."
echo "Gateway files will appear in: ${GW_VAR}/gw-*.xml"
ls -la "${GW_VAR}" "${EXT_LINK}" 2>/dev/null || true
