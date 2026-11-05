#!/usr/bin/env bash
# Privileged Ownswitch update (pull GitHub main + refresh-ui). Run via sudo as ownswitch.
set -euo pipefail
SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
SCRIPT="$SRC/deploy/refresh-ui.sh"
if [[ ! -f "$SCRIPT" ]]; then
  echo "ERR: refresh-ui.sh missing at $SCRIPT" >&2
  exit 1
fi
exec bash "$SCRIPT"
