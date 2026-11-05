#!/usr/bin/env bash
# Create FreeSWITCH Sofia gateway include dir so portal trunk saves can sync.
# Usage: sudo bash deploy/fix-fs-gateway-dir.sh
set -euo pipefail

FS_PREFIX="${FS_PREFIX:-/usr/local/freeswitch}"
FS_CONF="${FREESWITCH_CONF:-${FS_PREFIX}/conf}"

# Prefer classic layout; fall back if samples still live under etc/freeswitch.
if [[ ! -f "${FS_CONF}/freeswitch.xml" && -f "${FS_PREFIX}/etc/freeswitch/freeswitch.xml" ]]; then
  FS_CONF="${FS_PREFIX}/etc/freeswitch"
fi

GW="${FS_CONF}/sip_profiles/external"
PROFILES="${FS_CONF}/sip_profiles"
EXT_XML="${PROFILES}/external.xml"

echo "==> FreeSWITCH conf: ${FS_CONF}"
echo "==> Gateway dir:     ${GW}"

if [[ ! -d "${FS_CONF}" ]]; then
  echo "ERROR: FreeSWITCH conf missing: ${FS_CONF}" >&2
  echo "Run deploy stage 75 (or install FreeSWITCH) first." >&2
  exit 1
fi

install -d -m 0755 "${PROFILES}"

if id ownswitch >/dev/null 2>&1 && getent group freeswitch >/dev/null 2>&1; then
  install -d -m 2770 -o ownswitch -g freeswitch "${GW}"
  chown -R ownswitch:freeswitch "${GW}"
  find "${GW}" -type f -exec chmod 0660 {} + 2>/dev/null || true
elif getent group freeswitch >/dev/null 2>&1; then
  install -d -m 2770 -o root -g freeswitch "${GW}"
else
  install -d -m 0755 "${GW}"
fi

# Ensure external profile includes gateway XMLs (idempotent).
if [[ -f "${EXT_XML}" ]] && ! grep -q 'external/\*\.xml' "${EXT_XML}"; then
  if grep -q '<gateways>' "${EXT_XML}"; then
    # Insert include inside existing <gateways> if missing.
    if ! grep -q 'X-PRE-PROCESS.*external' "${EXT_XML}"; then
      sed -i 's#<gateways>#<gateways>\n    <X-PRE-PROCESS cmd="include" data="external/*.xml"/>#' "${EXT_XML}"
      echo "==> Added external/*.xml include to ${EXT_XML}"
    fi
  else
    echo "WARN: ${EXT_XML} has no <gateways> section — check Ownswitch external.xml template." >&2
  fi
elif [[ ! -f "${EXT_XML}" ]]; then
  echo "WARN: ${EXT_XML} missing — re-run deploy stage 75 to render Ownswitch external profile." >&2
fi

# Soften parent so PHP-FPM (ownswitch) can mkdir/write if needed later.
if id ownswitch >/dev/null 2>&1 && getent group freeswitch >/dev/null 2>&1; then
  chgrp freeswitch "${PROFILES}" 2>/dev/null || true
  chmod 2775 "${PROFILES}" 2>/dev/null || true
fi

echo "==> Gateway directory ready."
if command -v fs_cli >/dev/null 2>&1 || [[ -x "${FS_PREFIX}/bin/fs_cli" ]]; then
  FS_CLI="${FS_PREFIX}/bin/fs_cli"
  [[ -x "$FS_CLI" ]] || FS_CLI=fs_cli
  "$FS_CLI" -x 'sofia profile external rescan' 2>/dev/null || true
  echo "==> sofia profile external rescan issued."
fi

echo
echo "Re-save the trunk in the portal (or sync trunks) to write gw-*.xml files."
