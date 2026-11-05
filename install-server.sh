#!/usr/bin/env bash
# =============================================================================
# Ownswitch — one-command installer for a NEW Hetzner Cloud (or any) Ubuntu
# Server 24.04 LTS (amd64) host.
#
# This script installs EVERYTHING for a working VoIP billing switch:
#   MySQL 8.4 · Redis · CGRateS · RTPengine · FreeSWITCH (SIP edge)
#   PHP 8.3-FPM · Nginx · portal · workers · firewall
#
# Usage (on a clean Ubuntu 24.04 server as root):
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/install-server.sh \
#     | sudo bash -s -- --domain voip.example.com --email ops@example.com
#
# Or after cloning:
#
#   sudo bash install-server.sh --domain voip.example.com --email ops@example.com
#
# Options:
#   --domain FQDN          Portal / SIP realm (A/AAAA record → this server)
#   --email EMAIL          Admin + Let's Encrypt contact email
#   --password PASS        Admin password (min 12 chars); auto-generated if omitted
#   --repo URL             Git clone URL (default: xceedconnections/ownswitchfreeswitch)
#   --branch BRANCH        Git branch (default: main)
#   --no-freeswitch        Skip FreeSWITCH (faster; still full billing/routing)
#   --no-letsencrypt       Self-signed TLS only
#   --sip-allow CIDRS      Comma-separated CIDRs allowed to reach SIP (optional)
#   --force                Override soft hardware checks
#   -y | --yes             Non-interactive (implied when piped)
#   -h | --help
#
# Environment overrides (same names as deploy/install.sh) are also accepted.
# =============================================================================
set -Eeuo pipefail

REPO_URL="${OWNSWITCH_REPO_URL:-https://github.com/xceedconnections/ownswitchfreeswitch.git}"
BRANCH="${OWNSWITCH_BRANCH:-main}"
SRC_DIR="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
DOMAIN="${OWNSWITCH_DOMAIN:-}"
EMAIL="${OWNSWITCH_ADMIN_EMAIL:-}"
ADMIN_PASS="${OWN_SWITCH_ADMIN_PASS:-${OWNSWITCH_ADMIN_PASS:-}}"
FREESWITCH_ENABLED="${FREESWITCH_ENABLED:-1}"
LETSENCRYPT=1
SIP_ALLOW_CIDRS="${SIP_ALLOW_CIDRS:-}"
FORCE="${FORCE:-0}"
ASSUME_YES=0

RED=$'\033[31m'; GRN=$'\033[32m'; YLW=$'\033[33m'; CYN=$'\033[36m'; BLD=$'\033[1m'; RST=$'\033[0m'
log()  { printf '%s[%s]%s %s\n' "$CYN" "$(date -u +%H:%M:%S)" "$RST" "$*"; }
ok()   { printf '%s[ OK ]%s %s\n' "$GRN" "$RST" "$*"; }
warn() { printf '%s[WARN]%s %s\n' "$YLW" "$RST" "$*"; }
die()  { printf '%s[FAIL]%s %s\n' "$RED" "$RST" "$*" >&2; exit 1; }

usage() { sed -n '2,40p' "$0" | sed 's/^# \{0,1\}//'; }

while (($#)); do
  case "$1" in
    --domain) DOMAIN="$2"; shift 2 ;;
    --email) EMAIL="$2"; shift 2 ;;
    --password) ADMIN_PASS="$2"; shift 2 ;;
    --repo) REPO_URL="$2"; shift 2 ;;
    --branch) BRANCH="$2"; shift 2 ;;
    --no-freeswitch) FREESWITCH_ENABLED=0; shift ;;
    --no-letsencrypt) LETSENCRYPT=0; shift ;;
    --sip-allow) SIP_ALLOW_CIDRS="$2"; shift 2 ;;
    --force) FORCE=1; shift ;;
    -y|--yes) ASSUME_YES=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "Unknown option: $1 (see --help)" ;;
  esac
done

# Piped curl|bash is non-interactive
if [[ ! -t 0 ]]; then ASSUME_YES=1; fi

[[ "$(id -u)" -eq 0 ]] || die "Run as root (sudo)."

# --- OS gate -----------------------------------------------------------------
. /etc/os-release 2>/dev/null || die "Cannot read /etc/os-release"
[[ "${ID:-}" == "ubuntu" && "${VERSION_ID:-}" == "24.04" ]] \
  || die "Ownswitch requires Ubuntu Server 24.04 LTS (got ${PRETTY_NAME:-unknown}). Create a Hetzner image: ubuntu-24.04."
[[ "$(uname -m)" == "x86_64" ]] || die "amd64/x86_64 required (use Hetzner CX/CPX, not CAX ARM)."

# --- Defaults for domain/email ----------------------------------------------
PUBLIC_IP="$(curl -4 -fsS --max-time 5 https://ifconfig.me 2>/dev/null \
  || curl -4 -fsS --max-time 5 https://api.ipify.org 2>/dev/null \
  || hostname -I | awk '{print $1}')"
[[ -n "$PUBLIC_IP" ]] || die "Could not detect public IPv4."

if [[ -z "$DOMAIN" ]]; then
  DOMAIN="voip.${PUBLIC_IP}.sslip.io"
  warn "No --domain given; using ${DOMAIN} (sslip.io). Prefer a real DNS A record for production."
fi
if [[ -z "$EMAIL" ]]; then
  EMAIL="admin@${DOMAIN#voip.}"
  [[ "$EMAIL" == *.* ]] || EMAIL="admin@example.com"
  warn "No --email given; using ${EMAIL}"
fi
if [[ -z "$ADMIN_PASS" ]]; then
  ADMIN_PASS="$(openssl rand -base64 18 | tr -d '/+=' | head -c 20)Aa1!"
  GENERATED_PASS=1
else
  GENERATED_PASS=0
fi
(( ${#ADMIN_PASS} >= 12 )) || die "Admin password must be at least 12 characters."

log "${BLD}Ownswitch full-stack installer${RST}"
log "Host     : $(hostname) / Ubuntu 24.04 / ${PUBLIC_IP}"
log "Domain   : ${DOMAIN}"
log "Admin    : ${EMAIL}"
log "Repo     : ${REPO_URL} @ ${BRANCH}"
log "FreeSWITCH: ${FREESWITCH_ENABLED} (1=on)"
log "Source   : ${SRC_DIR}"
echo

if ! ((ASSUME_YES)); then
  read -r -p "Install complete VoIP billing stack on this server? [y/N] " ans
  [[ "${ans:-}" =~ ^[Yy]$ ]] || die "Aborted."
fi

export DEBIAN_FRONTEND=noninteractive

# --- Base tools --------------------------------------------------------------
log "Installing git/curl/ca-certificates…"
apt-get update -y
apt-get install -y ca-certificates curl git openssl jq

# --- Swap if tiny RAM --------------------------------------------------------
ram_mb="$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo)"
if (( ram_mb < 7500 )) && [[ ! -f /swapfile ]]; then
  log "RAM=${ram_mb}MB — creating 2G swapfile (helps CGRateS/FreeSWITCH builds)…"
  fallocate -l 2G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=2048
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  ok "Swap enabled"
fi

# --- Clone / update source ---------------------------------------------------
if [[ -d "${SRC_DIR}/.git" ]]; then
  log "Updating existing checkout ${SRC_DIR}…"
  git -C "${SRC_DIR}" fetch --all --prune
  git -C "${SRC_DIR}" checkout "${BRANCH}"
  git -C "${SRC_DIR}" pull --ff-only origin "${BRANCH}" || warn "pull failed; continuing with local tree"
else
  log "Cloning ${REPO_URL} → ${SRC_DIR}…"
  rm -rf "${SRC_DIR}"
  git clone --branch "${BRANCH}" --depth 1 "${REPO_URL}" "${SRC_DIR}"
fi
[[ -f "${SRC_DIR}/deploy/install.sh" ]] || die "deploy/install.sh missing in ${SRC_DIR}"
[[ -f "${SRC_DIR}/public/index.php" ]] || die "public/index.php missing — incomplete repo"
find "${SRC_DIR}/deploy" -type f \( -name '*.sh' -o -path '*/bin/*' \) -exec chmod +x {} +
chmod +x "${SRC_DIR}/install-server.sh" 2>/dev/null || true

# --- Export installer env ----------------------------------------------------
export OWNSWITCH_DOMAIN="${DOMAIN}"
export OWNSWITCH_ADMIN_EMAIL="${EMAIL}"
export OWN_SWITCH_ADMIN_PASS="${ADMIN_PASS}"
export OWNSWITCH_ADMIN_PASS="${ADMIN_PASS}"
export PUBLIC_IP
export FREESWITCH_ENABLED
export FORCE
export NON_INTERACTIVE=1
export APP_SRC="${SRC_DIR}"
export SIP_ALLOW_CIDRS
if (( LETSENCRYPT )); then
  export LETSENCRYPT_EMAIL="${EMAIL}"
else
  unset LETSENCRYPT_EMAIL || true
fi

# --- Run full staged installer ----------------------------------------------
log "Starting deploy/install.sh (this can take 20–60+ minutes; FreeSWITCH builds are slow)…"
cd "${SRC_DIR}"
bash "${SRC_DIR}/deploy/install.sh" --yes --non-interactive

# --- Post summary ------------------------------------------------------------
CREDS_FILE="/root/ownswitch-credentials.txt"
umask 077
{
  echo "Ownswitch installation summary"
  echo "Generated (UTC): $(date -u -Iseconds)"
  echo "Domain:          https://${DOMAIN}"
  echo "Admin email:     ${EMAIL}"
  echo "Admin password:  ${ADMIN_PASS}"
  echo "Public IP:       ${PUBLIC_IP}"
  echo "SIP:             ${PUBLIC_IP}:5060 (UDP/TCP)"
  echo "RTP range:       30000-40000/UDP"
  echo "App path:        /opt/ownswitch/current"
  echo "Source:          ${SRC_DIR}"
  echo "Secrets:         /etc/ownswitch/"
  echo "Report:          /var/lib/ownswitch-install/reports/latest.txt"
  echo
  echo "Change the admin password after first login."
  echo "Point DNS A ${DOMAIN} → ${PUBLIC_IP} if not already done, then re-run certbot if needed."
} > "${CREDS_FILE}"
chmod 600 "${CREDS_FILE}"

echo
ok "================================================================"
ok " Ownswitch is installed."
ok " Portal:   https://${DOMAIN}"
ok " Login:    ${EMAIL}"
if (( GENERATED_PASS )); then
  ok " Password: ${ADMIN_PASS}  (also in ${CREDS_FILE})"
else
  ok " Password: (the one you supplied) — copy also in ${CREDS_FILE}"
fi
ok " SIP:      ${PUBLIC_IP}:5060"
ok " Report:   /var/lib/ownswitch-install/reports/latest.txt"
ok "================================================================"
warn "Open Hetzner Cloud Firewall / Security Group: TCP 22,80,443 + UDP/TCP 5060 + UDP 30000-40000"
warn "Keep MySQL/Redis/CGRateS/ESL closed to the internet (installer already binds them locally)."
