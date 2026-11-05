#!/usr/bin/env bash
# Force-pull Ownswitch migrations and apply them. Survives stale /opt/ownswitch-src copies.
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-migrate.sh | sudo bash
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0" >&2
  exit 1
fi

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_OPT="${OWNSWITCH_OPT:-/opt/ownswitch}"
OWN_ETC="${OWNSWITCH_ETC:-/etc/ownswitch}"
ENV_FILE="${OWN_ETC}/ownswitch.env"
REPO_URL="${OWN_REPO_URL:-https://github.com/xceedconnections/ownswitchfreeswitch.git}"
RAW="https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main"

echo "== Ownswitch fix-migrate =="
echo "SRC=$SRC"

[[ -f "$ENV_FILE" ]] || { echo "FATAL: $ENV_FILE missing" >&2; exit 1; }

install -d -m 0755 "$(dirname "$SRC")"
if [[ ! -d "$SRC/.git" ]]; then
  git clone --branch main "$REPO_URL" "$SRC"
else
  git -C "$SRC" remote set-url origin "$REPO_URL" 2>/dev/null || true
  git -C "$SRC" fetch origin main
  git -C "$SRC" checkout -f main
  git -C "$SRC" reset --hard origin/main
  git -C "$SRC" clean -fd
fi
HEAD="$(git -C "$SRC" rev-parse --short HEAD)"
echo "git HEAD=$HEAD"

# Belt-and-suspenders: overwrite critical migration files from GitHub raw (defeats stale mirrors / bad clones)
install -d -m 0755 "$SRC/database/migrations" "$SRC/app/Database" "$SRC/bin"
curl -fsSL "$RAW/database/migrations/015_magnus_sip_params.sql" -o "$SRC/database/migrations/015_magnus_sip_params.sql"
curl -fsSL "$RAW/database/migrations/017_dids.sql" -o "$SRC/database/migrations/017_dids.sql"
curl -fsSL "$RAW/database/migrations/018_fix_qualify_columns.sql" -o "$SRC/database/migrations/018_fix_qualify_columns.sql"
curl -fsSL "$RAW/app/Database/Migrator.php" -o "$SRC/app/Database/Migrator.php"
curl -fsSL "$RAW/bin/migrate.php" -o "$SRC/bin/migrate.php"
sed -i 's/\r$//' "$SRC/database/migrations/"*.sql "$SRC/app/Database/Migrator.php" "$SRC/bin/migrate.php" 2>/dev/null || true

echo "015 qualify lines:"
grep -n "qualify" "$SRC/database/migrations/015_magnus_sip_params.sql" || echo "(none — good)"
if grep -E "ADD COLUMN qualify " "$SRC/database/migrations/015_magnus_sip_params.sql" >/dev/null 2>&1; then
  echo "WARN: patching unquoted qualify in 015 on disk…"
  sed -i 's/ADD COLUMN qualify /ADD COLUMN `qualify` /g; s/AFTER qualify/AFTER `qualify`/g' \
    "$SRC/database/migrations/015_magnus_sip_params.sql"
fi

sed -i 's/\r$//' "$SRC/deploy/refresh-ui.sh" 2>/dev/null || true
bash "$SRC/deploy/refresh-ui.sh"

CUR="$(readlink -f "${OWN_OPT}/current" 2>/dev/null || true)"
# Also patch live release in case rsync raced
if [[ -d "$CUR/database/migrations" ]]; then
  cp -f "$SRC/database/migrations/015_magnus_sip_params.sql" "$CUR/database/migrations/"
  cp -f "$SRC/database/migrations/017_dids.sql" "$CUR/database/migrations/"
  cp -f "$SRC/database/migrations/018_fix_qualify_columns.sql" "$CUR/database/migrations/"
  cp -f "$SRC/app/Database/Migrator.php" "$CUR/app/Database/"
  cp -f "$SRC/bin/migrate.php" "$CUR/bin/"
fi

MIG="${CUR}/bin/migrate.php"
[[ -f "$MIG" ]] || MIG="${SRC}/bin/migrate.php"

echo
echo "Running: $MIG"
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$MIG" --status 2>/dev/null \
  || env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$MIG" --status || true

echo "Applying…"
if runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$MIG"; then
  echo "Migrations OK"
elif env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$MIG"; then
  echo "Migrations OK (root)"
else
  echo "FATAL: migrate failed" >&2
  exit 1
fi

echo
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$MIG" --status 2>/dev/null \
  || env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$MIG" --status || true
echo "Done. Hard-refresh browser."
