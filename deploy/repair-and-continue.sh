#!/usr/bin/env bash
# =============================================================================
# Ownswitch — fully automatic repair + continue after a failed install.
#
# Fixes the common MySQL APT EXPKEYSIG failure, pulls latest code, then resumes
# the installer from stage 30 through the end (MySQL → … → portal).
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitch/main/deploy/repair-and-continue.sh \
#     | sudo bash
#
# Optional env:
#   OWNSWITCH_DOMAIN=voip.example.com
#   OWNSWITCH_ADMIN_EMAIL=ops@example.com
#   OWN_SWITCH_ADMIN_PASS='...'
#   FREESWITCH_ENABLED=0|1   (default 1)
#   SKIP_GIT_PULL=1
# =============================================================================
set -Eeuo pipefail

[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
REPO="${OWNSWITCH_REPO_URL:-https://github.com/xceedconnections/ownswitchfreeswitch.git}"
BRANCH="${OWNSWITCH_BRANCH:-main}"
KEYRING="/usr/share/keyrings/mysql.gpg"
CODENAME="$(. /etc/os-release; echo "${VERSION_CODENAME:-noble}")"

log() { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

export DEBIAN_FRONTEND=noninteractive
export MYSQL_INSTALL_METHOD=direct
export FORCE_MYSQL_DIRECT=1
export NON_INTERACTIVE=1
export FREESWITCH_ENABLED="${FREESWITCH_ENABLED:-1}"
export APP_SRC="${APP_SRC:-$SRC}"

# ---------------------------------------------------------------------------
# 0. Source tree
# ---------------------------------------------------------------------------
apt-get update -o Acquire::AllowInsecureRepositories=true -o Acquire::AllowDowngradeToInsecureRepositories=true -qq || true
apt-get install -y -qq ca-certificates curl git gnupg apt-transport-https >/dev/null

if [[ ! -d "${SRC}/.git" ]]; then
  log "Cloning ${REPO} → ${SRC}"
  rm -rf "${SRC}"
  git clone --branch "${BRANCH}" --depth 1 "${REPO}" "${SRC}"
elif [[ "${SKIP_GIT_PULL:-0}" != "1" ]]; then
  log "Updating ${SRC}"
  git -C "${SRC}" remote set-url origin "${REPO}" || true
  git -C "${SRC}" fetch --depth 1 origin "${BRANCH}"
  git -C "${SRC}" reset --hard "origin/${BRANCH}"
fi
[[ -f "${SRC}/deploy/install.sh" ]] || die "deploy/install.sh missing in ${SRC}"
find "${SRC}/deploy" -type f \( -name '*.sh' -o -path '*/bin/*' \) -exec chmod +x {} +

# ---------------------------------------------------------------------------
# 1. Obliterate broken MySQL APT state, install fresh keyring + source
# ---------------------------------------------------------------------------
log "Cleaning broken MySQL APT configuration…"
# Stop apt from seeing any mysql list while we rebuild keys
shopt -s nullglob
for f in /etc/apt/sources.list.d/mysql*.list /etc/apt/sources.list.d/mysql*.list.* \
         /etc/apt/sources.list.d/mysql*.sources; do
  [[ -e "$f" ]] || continue
  mv -f "$f" "${f}.ownswitch-bak-$(date +%s)" || rm -f "$f"
done
shopt -u nullglob
rm -f /etc/apt/trusted.gpg.d/mysql* \
      /usr/share/keyrings/mysql*.gpg \
      /etc/apt/keyrings/mysql*.gpg 2>/dev/null || true
# Old apt-key entries (ignore failures)
apt-key del B7B3B788A8D3785C 2>/dev/null || true
apt-key del A8D3785C 2>/dev/null || true

log "Downloading current MySQL signing keys…"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
: >"${TMP}/all.asc"
for url in \
  https://repo.mysql.com/RPM-GPG-KEY-mysql-2025 \
  https://repo.mysql.com/RPM-GPG-KEY-mysql-2023 \
  "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xB7B3B788A8D3785C" \
  "https://keys.openpgp.org/vks/v1/by-fingerprint/BCA43417C3B485DD128EC6D4B7B3B788A8D3785C"
do
  if curl -fsSL --connect-timeout 20 --max-time 60 "$url" -o "${TMP}/k" 2>/dev/null; then
    cat "${TMP}/k" >>"${TMP}/all.asc"
    log "  + $(echo "$url" | sed 's#https://##;s#/.*##')"
  fi
done
grep -q 'BEGIN PGP PUBLIC KEY BLOCK' "${TMP}/all.asc" || die "Could not download any MySQL GPG key"

install -d -m 0755 /usr/share/keyrings /etc/apt/keyrings
gpg --batch --yes --dearmor -o "${KEYRING}" "${TMP}/all.asc"
chmod 644 "${KEYRING}"
cp -f "${KEYRING}" /usr/share/keyrings/mysql-apt-config.gpg
cp -f "${KEYRING}" /usr/share/keyrings/mysql-8.4.gpg
cp -f "${KEYRING}" /etc/apt/keyrings/mysql.gpg

# Prefer https; fall back to http
PROTO=https
curl -fsS --connect-timeout 10 --max-time 20 -o /dev/null https://repo.mysql.com/ || PROTO=http

cat >/etc/apt/sources.list.d/mysql.list <<EOF
# Ownswitch managed — signed-by keyring (do not use unsigned mysql-apt-config lists)
deb [signed-by=${KEYRING}] ${PROTO}://repo.mysql.com/apt/ubuntu ${CODENAME} mysql-8.4-lts
deb [signed-by=${KEYRING}] ${PROTO}://repo.mysql.com/apt/ubuntu ${CODENAME} mysql-tools
EOF

# Purge broken mysql-apt-config package so it cannot rewrite lists on upgrade
if dpkg -l mysql-apt-config 2>/dev/null | grep -q '^ii'; then
  log "Removing obsolete mysql-apt-config package…"
  DEBIAN_FRONTEND=noninteractive apt-get purge -y mysql-apt-config >/dev/null 2>&1 || true
  # Re-write our list in case purge scripts touched sources
  cat >/etc/apt/sources.list.d/mysql.list <<EOF
deb [signed-by=${KEYRING}] ${PROTO}://repo.mysql.com/apt/ubuntu ${CODENAME} mysql-8.4-lts
deb [signed-by=${KEYRING}] ${PROTO}://repo.mysql.com/apt/ubuntu ${CODENAME} mysql-tools
EOF
fi

log "apt-get update (MySQL repo must be signed)…"
if ! apt-get update -o DPkg::Lock::Timeout=600; then
  die "apt-get update still failing — paste: apt-get update 2>&1 | tail -40"
fi

CAND="$(apt-cache policy mysql-server 2>/dev/null | awk '/Candidate:/ {print $2; found=1} END {exit 0}')"
log "mysql-server candidate: ${CAND:-none}"
[[ "${CAND}" == 8.4.* ]] || die "Expected MySQL 8.4 candidate, got '${CAND}'. Check repo.mysql.com access."

# Persist installer settings so resume does not flip back to apt-config
mkdir -p /etc/ownswitch
touch /etc/ownswitch/install.conf
grep -q '^MYSQL_INSTALL_METHOD=' /etc/ownswitch/install.conf 2>/dev/null \
  && sed -i 's/^MYSQL_INSTALL_METHOD=.*/MYSQL_INSTALL_METHOD=direct/' /etc/ownswitch/install.conf \
  || echo 'MYSQL_INSTALL_METHOD=direct' >>/etc/ownswitch/install.conf
grep -q '^MYSQL_APT_CONFIG_VERSION=' /etc/ownswitch/install.conf 2>/dev/null \
  && sed -i 's/^MYSQL_APT_CONFIG_VERSION=.*/MYSQL_APT_CONFIG_VERSION=0.8.40-1/' /etc/ownswitch/install.conf \
  || echo 'MYSQL_APT_CONFIG_VERSION=0.8.40-1' >>/etc/ownswitch/install.conf

# ---------------------------------------------------------------------------
# 2. Resume full installer from stage 30
# ---------------------------------------------------------------------------
log "Resuming Ownswitch installer from stage 30 (this can take a long time)…"
cd "${SRC}"
export OWNSWITCH_DOMAIN="${OWNSWITCH_DOMAIN:-voip.xceedconnections.com}"
bash "${SRC}/deploy/install.sh" --from 30 --yes --non-interactive

log "Done. Portal: https://${OWNSWITCH_DOMAIN:-voip.xceedconnections.com}/"
log "Credentials (if generated): /root/ownswitch-credentials.txt"
log "Report: /var/lib/ownswitch-install/reports/latest.txt"
