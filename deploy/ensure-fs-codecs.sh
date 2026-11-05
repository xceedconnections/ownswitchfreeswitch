#!/usr/bin/env bash
# Ensure common FreeSWITCH codec modules are loaded (idempotent; does not restart Sofia).
#   curl -fsSL …/deploy/ensure-fs-codecs.sh | sudo bash
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

FS_PREFIX="${FS_PREFIX:-/usr/local/freeswitch}"
FS_CLI="${FS_PREFIX}/bin/fs_cli"
ENVF="${OWN_ETC:-/etc/ownswitch}/ownswitch.env"
ESL_PASS=""
[[ -f "$ENVF" ]] && ESL_PASS="$(grep -E '^FREESWITCH_ESL_PASSWORD=' "$ENVF" | head -1 | cut -d= -f2- || true)"
cli() {
  if [[ -n "$ESL_PASS" ]]; then "$FS_CLI" -p "$ESL_PASS" -x "$1"; else "$FS_CLI" -x "$1"; fi
}

MODS=(
  mod_spandsp
  mod_opus
  mod_silk
  mod_siren
  mod_amr
  mod_amrwb
  mod_codec2
  mod_g729
)

echo "== Load optional codec modules (ignore already-loaded / missing) =="
for m in "${MODS[@]}"; do
  if [[ -f "${FS_PREFIX}/lib/freeswitch/mod/${m}.so" ]]; then
    cli "load ${m}" >/dev/null 2>&1 || true
    echo "  ${m}: present"
  else
    echo "  ${m}: .so not installed (skip)"
  fi
done

echo "== show codecs (sample) =="
cli 'show codecs' | head -n 60 || true
echo "Done. G.711/GSM are built-in; G.729 needs a licensed/binary module if you require it."
