#!/usr/bin/env bash
# One-shot DB unblocker. Bypasses stale local trees and broken 015 qualify.
# MUST run via curl (do not use an old copy under /opt/ownswitch-src):
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/emergency-db-fix.sh | sudo bash
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root" >&2; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_OPT="${OWNSWITCH_OPT:-/opt/ownswitch}"
OWN_ETC="${OWNSWITCH_ETC:-/etc/ownswitch}"
ENV_FILE="${OWN_ETC}/ownswitch.env"
RAW="https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main"
REPO="${OWN_REPO_URL:-https://github.com/xceedconnections/ownswitchfreeswitch.git}"

echo "== emergency-db-fix =="
[[ -f "$ENV_FILE" ]] || { echo "FATAL: missing $ENV_FILE" >&2; exit 1; }

install -d -m 0755 "$SRC"
if [[ -d "$SRC/.git" ]]; then
  git -C "$SRC" remote set-url origin "$REPO" || true
  git -C "$SRC" fetch origin main
  git -C "$SRC" checkout -f main
  git -C "$SRC" reset --hard origin/main
  git -C "$SRC" clean -fd
else
  git clone --branch main "$REPO" "$SRC"
fi
echo "HEAD=$(git -C "$SRC" rev-parse --short HEAD)"

# Force-download the migration set that unblocks MySQL 8
install -d "$SRC/database/migrations" "$SRC/app/Database" "$SRC/bin" "$SRC/deploy"
for f in \
  database/migrations/015_magnus_sip_params.sql \
  database/migrations/017_dids.sql \
  database/migrations/018_fix_qualify_columns.sql \
  database/migrations/019_magnus_sip_params_rest.sql \
  app/Database/Migrator.php \
  bin/migrate.php \
  deploy/refresh-ui.sh
do
  echo "GET $f"
  curl -fsSL "$RAW/$f" -o "$SRC/$f"
  sed -i 's/\r$//' "$SRC/$f" 2>/dev/null || true
done

# Prove 015 is the noop (must NOT contain ADD COLUMN qualify)
if grep -E "ADD COLUMN qualify |ADD COLUMN \`qualify\`" "$SRC/database/migrations/015_magnus_sip_params.sql" >/dev/null 2>&1; then
  echo "FATAL: 015 still contains qualify ALTER — raw download wrong" >&2
  head -20 "$SRC/database/migrations/015_magnus_sip_params.sql" >&2
  exit 1
fi
echo "015 is noop OK"
wc -l "$SRC/database/migrations/015_magnus_sip_params.sql"

chmod +x "$SRC/deploy/refresh-ui.sh"
# Deploy UI from this patched SRC (refresh-ui also git-pulls — files already match origin after reset)
bash "$SRC/deploy/refresh-ui.sh"

CUR="$(readlink -f "$OWN_OPT/current")"
# Re-copy migrations into live release after refresh (refresh may have raced)
cp -f "$SRC/database/migrations/015_magnus_sip_params.sql" "$CUR/database/migrations/"
cp -f "$SRC/database/migrations/017_dids.sql" "$CUR/database/migrations/"
cp -f "$SRC/database/migrations/018_fix_qualify_columns.sql" "$CUR/database/migrations/"
cp -f "$SRC/database/migrations/019_magnus_sip_params_rest.sql" "$CUR/database/migrations/"
cp -f "$SRC/app/Database/Migrator.php" "$CUR/app/Database/"
cp -f "$SRC/bin/migrate.php" "$CUR/bin/"

MIG="$CUR/bin/migrate.php"
echo "Migrate via $MIG"
env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$MIG" --status || true
if ! env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$MIG"; then
  echo "FATAL: migrate still failed" >&2
  exit 1
fi
env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$MIG" --status || true
echo "OK — hard-refresh browser (Ctrl+Shift+R)"
