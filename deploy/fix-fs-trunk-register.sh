#!/usr/bin/env bash
# Diagnose + force-reload FreeSWITCH outbound REGISTER gateways (portal trunks).
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-fs-trunk-register.sh | sudo bash
set -euo pipefail

FS_PREFIX="${FS_PREFIX:-/usr/local/freeswitch}"
FS_CLI="${FS_PREFIX}/bin/fs_cli"
OWN_VAR="${OWN_VAR:-/var/lib/ownswitch}"
GW_DIR="${FREESWITCH_GATEWAY_DIR:-${OWN_VAR}/freeswitch/gateways}"
OWN_ETC="${OWN_ETC:-/etc/ownswitch}"
ENVF="${OWN_ETC}/ownswitch.env"

echo "== FreeSWITCH trunk REGISTER fix =="
echo "Gateway dir: ${GW_DIR}"

# Ensure gateway store + symlink exist
curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-fs-trunk-sync.sh | bash

echo
echo "== Gateway XML files =="
ls -la "${GW_DIR}" 2>/dev/null || echo "(none yet — re-save trunks in portal after refresh)"

if [[ ! -x "$FS_CLI" ]]; then
  echo "ERROR: fs_cli not found at $FS_CLI" >&2
  exit 1
fi

# ESL password from env if present
ESL_PASS=""
if [[ -f "$ENVF" ]]; then
  ESL_PASS="$(grep -E '^FREESWITCH_ESL_PASSWORD=' "$ENVF" | head -1 | cut -d= -f2- || true)"
fi
FS_ARGS=(-x)
if [[ -n "$ESL_PASS" ]]; then
  FS_ARGS=(-p "$ESL_PASS" -x)
fi

echo
echo "== reloadxml + rescan + register each gw-*.xml =="
"$FS_CLI" "${FS_ARGS[@]}" 'reloadxml' || true
"$FS_CLI" "${FS_ARGS[@]}" 'sofia profile external rescan' || true

shopt -s nullglob
for f in "${GW_DIR}"/gw-*.xml; do
  base="$(basename "$f" .xml)"
  echo "--- $base ---"
  grep -E 'register|username|proxy|realm' "$f" | sed 's/password"[^"]*"/password" ***"/g' || true
  "$FS_CLI" "${FS_ARGS[@]}" "sofia profile external killgw ${base}" 2>/dev/null || true
done
sleep 1
"$FS_CLI" "${FS_ARGS[@]}" 'reloadxml' || true
"$FS_CLI" "${FS_ARGS[@]}" 'sofia profile external rescan' || true
sleep 1
for f in "${GW_DIR}"/gw-*.xml; do
  base="$(basename "$f" .xml)"
  "$FS_CLI" "${FS_ARGS[@]}" "sofia profile external register ${base}" 2>/dev/null || true
  echo "Status ${base}:"
  "$FS_CLI" "${FS_ARGS[@]}" "sofia status gateway ${base}" 2>/dev/null | head -n 20 || true
done
shopt -u nullglob

echo
echo "== Also sync from portal DB =="
if [[ -f /opt/ownswitch/current/bin/sync-opensips-trunks.php ]]; then
  runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENVF" \
    php /opt/ownswitch/current/bin/sync-opensips-trunks.php || true
fi

echo
echo "On Magnus/Asterisk you should see a peer REGISTER from this server's public IP."
echo "If State is FAIL_WAIT / UNREGED: wrong user/pass/realm, or Asterisk ACL blocking this IP."
echo "Re-save the trunk in Ownswitch (Auth = Outbound REGISTER) after this script if gw XML was empty."
