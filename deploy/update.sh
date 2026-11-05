#!/usr/bin/env bash
# Full server update: pull from GitHub → deploy UI release → migrate → refresh OpenSIPS + health.
#
# On the server (as root), run ONE of:
#   sudo bash /opt/ownswitch-src/deploy/update.sh
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/update.sh | sudo bash
#
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0" >&2
  exit 1
fi

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
REPO_URL="${OWN_REPO_URL:-https://github.com/xceedconnections/ownswitchfreeswitch.git}"
BRANCH="${OWN_BRANCH:-main}"

echo "== Ownswitch update =="
echo "SRC=$SRC  BRANCH=$BRANCH  REPO=$REPO_URL"

if [[ ! -d "$SRC/.git" ]]; then
  echo "Cloning $REPO_URL → $SRC"
  install -d -m 0755 "$(dirname "$SRC")"
  git clone --branch "$BRANCH" "$REPO_URL" "$SRC"
else
  echo "Fetching latest $BRANCH from GitHub…"
  git -C "$SRC" remote set-url origin "$REPO_URL" 2>/dev/null || true
  git -C "$SRC" fetch origin "$BRANCH"
  git -C "$SRC" checkout "$BRANCH"
  git -C "$SRC" reset --hard "origin/$BRANCH"
fi

HEAD="$(git -C "$SRC" rev-parse --short HEAD)"
echo "Source at $HEAD"

chmod +x "$SRC/deploy/refresh-ui.sh" "$SRC/deploy/install.sh" "$SRC/deploy/update.sh" 2>/dev/null || true
find "$SRC/deploy" -type f \( -name '*.sh' -o -path '*/bin/*' \) -exec chmod +x {} + 2>/dev/null || true

# Drop Windows CRLF if present (breaks #!/usr/bin/env bash)
sed -i 's/\r$//' "$SRC/deploy/refresh-ui.sh" "$SRC/deploy/install.sh" "$SRC/deploy/update.sh" 2>/dev/null || true

echo
echo "== 1/2 Deploy portal (UI + migrations) =="
bash "$SRC/deploy/refresh-ui.sh"

echo
echo "== 2/2 OpenSIPS config + health (stages 50,99) =="
# Already root — call install.sh by absolute path (sudo ./relative fails with "command not found")
if [[ -f /etc/ownswitch/install.conf ]]; then
  export OWNSWITCH_CONFIG=/etc/ownswitch/install.conf
fi
# Skip apt on routine updates (OpenSIPS packages already present). Force with:
#   OWNSWITCH_FORCE_APT=1 sudo bash deploy/update.sh
export OWNSWITCH_SKIP_APT="${OWNSWITCH_SKIP_APT:-1}"
cd "$SRC"
bash "$SRC/deploy/install.sh" --yes --only 50,99

echo
echo "== Done =="
echo "Commit: $HEAD"
echo "Hard-refresh browser: Ctrl+Shift+R"
echo "Checks:"
echo "  curl -k -I https://voip.xceedconnections.com/assets/css/app.css"
echo "  readlink -f /opt/ownswitch/current"
