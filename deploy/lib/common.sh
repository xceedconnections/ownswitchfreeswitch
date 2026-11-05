#!/usr/bin/env bash
# =============================================================================
# Ownswitch installer - shared library (SOURCE this file, do not execute it).
#
# Provides: logging, require_root, detect_os, config loading/persistence,
#           secret generation, template rendering, apt/systemd/mysql helpers.
#
# NOTE (Windows checkouts): the exec bit cannot be set on NTFS. On the Ubuntu
# target run once:   find deploy -type f \( -name '*.sh' -o -path '*/bin/*' \) -exec chmod +x {} +
# install.sh runs every stage through `bash <file>`, so the exec bit is optional.
# =============================================================================

if [[ -n "${OWNSWITCH_COMMON_LOADED:-}" ]]; then
  return 0
fi
OWNSWITCH_COMMON_LOADED=1

set -Eeuo pipefail

# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "${DEPLOY_DIR}/.." && pwd)"
TEMPLATE_DIR="${DEPLOY_DIR}/templates"
export DEPLOY_DIR REPO_ROOT TEMPLATE_DIR

OWN_ETC="${OWN_ETC:-/etc/ownswitch}"
OWN_SECRETS="${OWN_SECRETS:-${OWN_ETC}/secrets}"
OWN_OPT="${OWN_OPT:-/opt/ownswitch}"
OWN_VAR="${OWN_VAR:-/var/lib/ownswitch}"
OWN_LOG="${OWN_LOG:-/var/log/ownswitch}"
OWN_STATE="${OWN_STATE:-/var/lib/ownswitch-install}"
OWN_BACKUP_CFG_DIR="${OWN_BACKUP_CFG_DIR:-/var/backups/ownswitch-config}"
INSTALL_LOG="${INSTALL_LOG:-/var/log/ownswitch-install.log}"
export OWN_ETC OWN_SECRETS OWN_OPT OWN_VAR OWN_LOG OWN_STATE OWN_BACKUP_CFG_DIR INSTALL_LOG

RUN_ID="${RUN_ID:-$(date -u +%Y%m%dT%H%M%SZ)}"
export RUN_ID

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
if [[ -t 2 ]]; then
  _C_RED=$'\033[31m'; _C_GRN=$'\033[32m'; _C_YEL=$'\033[33m'; _C_BLU=$'\033[34m'; _C_RST=$'\033[0m'
else
  _C_RED=""; _C_GRN=""; _C_YEL=""; _C_BLU=""; _C_RST=""
fi

_ts() { date -u +'%Y-%m-%dT%H:%M:%SZ'; }
log_info()  { printf '%s [INFO ] %s%s\n'  "$(_ts)" "${_C_BLU}" "${*}${_C_RST}" >&2; }
log_ok()    { printf '%s [ OK  ] %s%s\n'  "$(_ts)" "${_C_GRN}" "${*}${_C_RST}" >&2; }
log_warn()  { printf '%s [WARN ] %s%s\n'  "$(_ts)" "${_C_YEL}" "${*}${_C_RST}" >&2; }
log_error() { printf '%s [ERROR] %s%s\n'  "$(_ts)" "${_C_RED}" "${*}${_C_RST}" >&2; }
log_step()  { printf '\n%s [STEP ] %s==> %s%s\n' "$(_ts)" "${_C_BLU}" "${*}" "${_C_RST}" >&2; }
die()       { log_error "$*"; exit 1; }

# Tee all stdout/stderr to the install log (once per process tree).
setup_logging() {
  [[ -n "${OWNSWITCH_LOGGING_ACTIVE:-}" ]] && return 0
  install -d -m 0755 "$(dirname "${INSTALL_LOG}")"
  touch "${INSTALL_LOG}"; chmod 0640 "${INSTALL_LOG}"
  exec > >(tee -a "${INSTALL_LOG}") 2>&1
  export OWNSWITCH_LOGGING_ACTIVE=1
}

trap_err() {
  local rc=$? line=${1:-?}
  log_error "Command failed (exit ${rc}) at ${BASH_SOURCE[1]:-main}:${line}: ${BASH_COMMAND}"
}

is_true() {
  case "${1:-}" in 1|[Tt][Rr][Uu][Ee]|[Yy]|[Yy][Ee][Ss]|[Oo][Nn]) return 0 ;; *) return 1 ;; esac
}

# ---------------------------------------------------------------------------
# Result / change tracking (file based so subshells can record too)
# ---------------------------------------------------------------------------
run_dir() { printf '%s/runs/%s' "${OWN_STATE}" "${RUN_ID}"; }

_ensure_run_dir() { install -d -m 0750 "$(run_dir)"; }

note_change() {
  _ensure_run_dir
  printf '%s\n' "$*" >>"$(run_dir)/changes.log"
  log_info "CHANGED: $*"
}

record_result() { # stage status detail
  _ensure_run_dir
  printf '%s\t%s\t%s\n' "$1" "$2" "${3:-}" >>"$(run_dir)/results.tsv"
}

# ---------------------------------------------------------------------------
# Guards
# ---------------------------------------------------------------------------
require_root() {
  if [[ ${EUID} -ne 0 ]]; then
    die "This installer must run as root (try: sudo $0)"
  fi
}

require_cmd() {
  local c
  for c in "$@"; do
    command -v "$c" >/dev/null 2>&1 || die "Required command not found: $c"
  done
}

detect_os() {
  [[ -r /etc/os-release ]] || die "/etc/os-release not found - unsupported OS"
  OS_ID="$(. /etc/os-release; printf '%s' "${ID:-unknown}")"
  OS_VERSION_ID="$(. /etc/os-release; printf '%s' "${VERSION_ID:-unknown}")"
  OS_CODENAME="$(. /etc/os-release; printf '%s' "${VERSION_CODENAME:-unknown}")"
  OS_PRETTY="$(. /etc/os-release; printf '%s' "${PRETTY_NAME:-unknown}")"
  OS_ARCH="$(dpkg --print-architecture 2>/dev/null || uname -m)"
  export OS_ID OS_VERSION_ID OS_CODENAME OS_PRETTY OS_ARCH
}

# ---------------------------------------------------------------------------
# Configuration: environment > --config file > /etc/ownswitch/install.conf > defaults
# Only KEY=VALUE lines are read (the files are never `source`d).
# ---------------------------------------------------------------------------
load_config() {
  local f line k v
  for f in "${OWNSWITCH_CONFIG:-}" "${OWN_ETC}/install.conf"; do
    [[ -n "$f" && -r "$f" ]] || continue
    while IFS= read -r line || [[ -n "$line" ]]; do
      [[ "$line" =~ ^[[:space:]]*# ]] && continue
      [[ "$line" =~ ^([A-Z][A-Z0-9_]*)=(.*)$ ]] || continue
      k="${BASH_REMATCH[1]}"; v="${BASH_REMATCH[2]}"
      v="${v%\"}"; v="${v#\"}"; v="${v%\'}"; v="${v#\'}"
      if [[ -z "${!k+x}" ]]; then
        export "${k}=${v}"
      fi
    done <"$f"
  done
  return 0
}

persist_setting() { # KEY VALUE  (overwrite)
  local k=$1 v=$2 f="${OWN_ETC}/install.conf" tmp
  install -d -m 0750 "${OWN_ETC}"
  [[ -f "$f" ]] || { : >"$f"; chmod 0640 "$f"; }
  tmp="$(mktemp)"
  grep -v "^${k}=" "$f" >"$tmp" || true
  printf '%s=%s\n' "$k" "$v" >>"$tmp"
  cat "$tmp" >"$f"; rm -f "$tmp"
}

persist_if_absent() { # KEY VALUE
  local f="${OWN_ETC}/install.conf"
  if [[ -f "$f" ]] && grep -q "^$1=" "$f"; then return 0; fi
  persist_setting "$1" "$2"
}

is_private_ip() {
  [[ "$1" =~ ^10\. || "$1" =~ ^192\.168\. || "$1" =~ ^172\.(1[6-9]|2[0-9]|3[01])\. \
     || "$1" =~ ^100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\. || "$1" =~ ^127\. ]]
}

valid_ipv4() { [[ "$1" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; }

init_config() {
  load_config

  : "${OWNSWITCH_DOMAIN:=$(hostname -f 2>/dev/null || hostname)}"
  : "${OWNSWITCH_ADMIN_EMAIL:=admin@${OWNSWITCH_DOMAIN}}"
  : "${LETSENCRYPT_EMAIL:=}"
  : "${SIP_PORT:=5060}"
  : "${RTP_PORT_MIN:=30000}"
  : "${RTP_PORT_MAX:=40000}"
  : "${SIP_ALLOW_CIDRS:=}"      # comma separated; empty = any source may reach SIP
  : "${SSH_ALLOW_CIDRS:=}"      # comma separated; empty = any source may reach SSH
  : "${ADMIN_ALLOW_CIDRS:=}"    # reserved: restrict 80/443 (empty = public)
  : "${FAIL2BAN_ENABLED:=1}"
  : "${FREESWITCH_ENABLED:=1}"
  : "${FREESWITCH_VERSION:=v1.10.12}"
  : "${CGRATES_VERSION:=v0.10.4}"
  : "${CGRATES_TENANT:=ownswitch.local}"
  : "${CGRATES_BIN_TARBALL_URL:=}"
  : "${CGRATES_BIN_TARBALL_SHA256:=}"
  : "${OPENSIPS_VERSION_SERIES:=3.6}"
  # "direct" imports a fresh keyring (avoids EXPKEYSIG from stale mysql-apt-config packages).
  : "${MYSQL_INSTALL_METHOD:=direct}"               # direct | apt-config
  # 0.8.40+ embeds the renewed MySQL APT signing key (older builds hit EXPKEYSIG B7B3B788A8D3785C).
  : "${MYSQL_APT_CONFIG_VERSION:=0.8.40-1}"
  : "${MYSQL_APT_CONFIG_SHA256:=}"
  # Upgrade persisted stale values from earlier installer runs.
  case "${MYSQL_APT_CONFIG_VERSION}" in
    0.8.3[0-9]-*|0.8.2*|0.8.1*|0.8.0*) MYSQL_APT_CONFIG_VERSION="0.8.40-1" ;;
  esac
  [[ "${MYSQL_INSTALL_METHOD}" == "apt-config" && "${FORCE_MYSQL_DIRECT:-0}" == "1" ]] && MYSQL_INSTALL_METHOD="direct"
  : "${MYSQL_OPENSIPS_AUTH_PLUGIN:=mysql_native_password}"
  : "${MYSQL_BUFFER_POOL_PCT:=25}"
  : "${REDIS_CGR_DB:=10}"
  : "${REDIS_PORTAL_DB:=1}"
  : "${RTPENGINE_NG_PORT:=22222}"
  : "${RTPENGINE_KERNEL:=1}"
  : "${RTPENGINE_RECORDING_METHOD:=auto}"           # auto | proc | pcap
  : "${PHP_VERSION:=8.3}"
  : "${APP_SRC:=${REPO_ROOT}}"
  : "${APP_RELEASES_KEEP:=5}"
  : "${SET_TIMEZONE_UTC:=1}"
  : "${PUBLIC_IP_DETECT:=1}"
  : "${HEALTH_FAIL_EXIT:=1}"
  : "${MIN_RAM_MB:=3500}"
  : "${MIN_DISK_GB:=20}"
  : "${MIN_CPUS:=2}"
  : "${FORCE:=0}"
  : "${NON_INTERACTIVE:=0}"

  if [[ -z "${PRIVATE_IP:-}" ]]; then
    PRIVATE_IP="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); exit}}' || true)"
    [[ -n "${PRIVATE_IP}" ]] || PRIVATE_IP="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
    [[ -n "${PRIVATE_IP}" ]] || PRIVATE_IP="127.0.0.1"
  fi
  if [[ -z "${PUBLIC_IP:-}" ]]; then
    PUBLIC_IP="${PRIVATE_IP}"
    if is_private_ip "${PRIVATE_IP}" && is_true "${PUBLIC_IP_DETECT}" && command -v curl >/dev/null 2>&1; then
      local det
      det="$(curl -4fsS --max-time 6 https://api.ipify.org 2>/dev/null || true)"
      if valid_ipv4 "${det}"; then PUBLIC_IP="${det}"; fi
    fi
  fi
  if [[ -z "${SSH_PORT:-}" ]]; then
    SSH_PORT="$(sshd -T 2>/dev/null | awk '$1=="port"{print $2; exit}' || true)"
    [[ -n "${SSH_PORT}" ]] || SSH_PORT=22
  fi

  # Fixed internal endpoints (all loopback only)
  CGR_JSONRPC_ADDR="127.0.0.1:2012"
  CGR_GOB_ADDR="127.0.0.1:2013"
  CGR_BIJSON_ADDR="127.0.0.1:2014"
  CGR_HTTP_ADDR="127.0.0.1:2080"
  DB_OWNSWITCH="ownswitch"; DB_OPENSIPS="opensips"; DB_CGRATES="cgrates"
  PHP_FPM_SOCK="/run/php/ownswitch.sock"
  INTERNAL_HTTP_ADDR="127.0.0.1:8081"
  OPENSIPS_FIFO="/run/opensips/opensips_fifo"

  export OWNSWITCH_DOMAIN OWNSWITCH_ADMIN_EMAIL LETSENCRYPT_EMAIL SIP_PORT RTP_PORT_MIN RTP_PORT_MAX \
         SIP_ALLOW_CIDRS SSH_ALLOW_CIDRS ADMIN_ALLOW_CIDRS FAIL2BAN_ENABLED FREESWITCH_ENABLED FREESWITCH_VERSION \
         CGRATES_VERSION CGRATES_TENANT CGRATES_BIN_TARBALL_URL CGRATES_BIN_TARBALL_SHA256 \
         OPENSIPS_VERSION_SERIES MYSQL_INSTALL_METHOD MYSQL_APT_CONFIG_VERSION MYSQL_APT_CONFIG_SHA256 \
         MYSQL_OPENSIPS_AUTH_PLUGIN MYSQL_BUFFER_POOL_PCT REDIS_CGR_DB REDIS_PORTAL_DB \
         RTPENGINE_NG_PORT RTPENGINE_KERNEL RTPENGINE_RECORDING_METHOD PHP_VERSION APP_SRC APP_RELEASES_KEEP \
         SET_TIMEZONE_UTC PUBLIC_IP_DETECT HEALTH_FAIL_EXIT MIN_RAM_MB MIN_DISK_GB MIN_CPUS FORCE NON_INTERACTIVE \
         PRIVATE_IP PUBLIC_IP SSH_PORT CGR_JSONRPC_ADDR CGR_GOB_ADDR CGR_BIJSON_ADDR CGR_HTTP_ADDR \
         DB_OWNSWITCH DB_OPENSIPS DB_CGRATES PHP_FPM_SOCK INTERNAL_HTTP_ADDR OPENSIPS_FIFO
}

persist_defaults() {
  persist_if_absent OWNSWITCH_DOMAIN "${OWNSWITCH_DOMAIN}"
  persist_if_absent PUBLIC_IP "${PUBLIC_IP}"
  persist_if_absent PRIVATE_IP "${PRIVATE_IP}"
  persist_if_absent SSH_PORT "${SSH_PORT}"
  persist_if_absent FREESWITCH_ENABLED "${FREESWITCH_ENABLED}"
  persist_if_absent CGRATES_VERSION "${CGRATES_VERSION}"
  persist_if_absent CGRATES_TENANT "${CGRATES_TENANT}"
  persist_if_absent RTP_PORT_MIN "${RTP_PORT_MIN}"
  persist_if_absent RTP_PORT_MAX "${RTP_PORT_MAX}"
  persist_if_absent SIP_PORT "${SIP_PORT}"
  persist_if_absent SIP_ALLOW_CIDRS "${SIP_ALLOW_CIDRS}"
  persist_if_absent SSH_ALLOW_CIDRS "${SSH_ALLOW_CIDRS}"
}

# ---------------------------------------------------------------------------
# Base layout (users + directories). Safe to call from any stage.
# ---------------------------------------------------------------------------
ensure_system_user() { # user home [extra useradd args...]
  local u=$1 home=$2; shift 2
  if ! getent passwd "$u" >/dev/null; then
    useradd --system --home-dir "$home" --no-create-home --shell /usr/sbin/nologin --user-group "$@" "$u"
    note_change "created system user ${u}"
  fi
}

ensure_base_layout() {
  ensure_system_user ownswitch "${OWN_OPT}"
  install -d -m 0750 -o root -g ownswitch "${OWN_ETC}"
  install -d -m 0700 -o root -g root "${OWN_SECRETS}"
  install -d -m 0755 -o root -g root "${OWN_OPT}" "${OWN_OPT}/releases"
  install -d -m 0750 -o ownswitch -g ownswitch "${OWN_VAR}" "${OWN_VAR}/storage" "${OWN_VAR}/sessions" "${OWN_VAR}/tmp"
  install -d -m 0750 -o ownswitch -g ownswitch "${OWN_LOG}"
  install -d -m 0750 -o root -g root "${OWN_STATE}" "${OWN_STATE}/runs" "${OWN_STATE}/reports"
  install -d -m 0750 -o root -g root "${OWN_BACKUP_CFG_DIR}"
}

# ---------------------------------------------------------------------------
# Secrets: one file per secret in /etc/ownswitch/secrets (root:root 0600).
# Values are alphanumeric only so they are safe in SQL, JSON, URLs and env files.
# ---------------------------------------------------------------------------
gen_secret() { # length (default 40) -> alnum string
  local len=${1:-40} s
  s="$(openssl rand -hex $(( (len + 1) / 2 )))"
  printf '%s' "${s:0:len}"
}

secret_path() { printf '%s/%s' "${OWN_SECRETS}" "$1"; }

ensure_secret() { # name [len] -> prints value
  local name=$1 len=${2:-40} f
  f="$(secret_path "$name")"
  install -d -m 0700 -o root -g root "${OWN_SECRETS}"
  if [[ ! -s "$f" ]]; then
    ( umask 077; gen_secret "$len" >"$f" )
    chmod 0600 "$f"; chown root:root "$f"
    note_change "generated secret '${name}' (stored in ${f})"
  fi
  cat "$f"
}

get_secret() { # name -> prints value or fails
  local f; f="$(secret_path "$1")"
  [[ -s "$f" ]] || die "Secret '$1' not found at $f (run earlier stages first)"
  cat "$f"
}

# ---------------------------------------------------------------------------
# Template rendering: @@VARNAME@@ placeholders are replaced from the environment.
# (Chosen over envsubst so OpenSIPS $vars, nginx $vars and JSON are never touched.)
# Fails if a placeholder has no matching exported variable.
# Sets RENDER_CHANGED=1/0.
# ---------------------------------------------------------------------------
render_to_stdout() { # src
  perl -pe '
    s/\@\@([A-Za-z0-9_]+)\@\@/
      defined $ENV{$1} ? $ENV{$1} : do { print STDERR "unresolved placeholder: $1\n"; $main::bad = 1; "" }
    /ge;
    END { exit($main::bad ? 3 : 0) }
  ' "$1"
}

backup_file() { # path
  local f=$1
  [[ -e "$f" ]] || return 0
  install -d -m 0750 "${OWN_BACKUP_CFG_DIR}"
  cp -a "$f" "${OWN_BACKUP_CFG_DIR}/$(echo "$f" | tr '/' '_').${RUN_ID}"
}

render_template() { # src dest [mode=0644] [owner=root] [group=root]
  local src=$1 dest=$2 mode=${3:-0644} owner=${4:-root} group=${5:-root} tmp
  [[ -f "$src" ]] || die "Template not found: $src"
  tmp="$(mktemp)"
  if ! render_to_stdout "$src" >"$tmp"; then
    rm -f "$tmp"; die "Template render failed: $src"
  fi
  install -d -m 0755 "$(dirname "$dest")"
  RENDER_CHANGED=0
  if [[ -f "$dest" ]] && cmp -s "$tmp" "$dest"; then
    install -m "$mode" -o "$owner" -g "$group" "$tmp" "$dest.new.$$" && mv -f "$dest.new.$$" "$dest"  # fix perms only
  else
    backup_file "$dest"
    install -m "$mode" -o "$owner" -g "$group" "$tmp" "$dest.new.$$" && mv -f "$dest.new.$$" "$dest"
    RENDER_CHANGED=1
    note_change "wrote ${dest}"
  fi
  rm -f "$tmp"
}

ensure_line() { # file line   (append if exact line absent)
  local f=$1 line=$2
  touch "$f"
  if ! grep -qxF -- "$line" "$f"; then
    printf '%s\n' "$line" >>"$f"
    note_change "appended '${line}' to ${f}"
  fi
}

# ---------------------------------------------------------------------------
# apt
# ---------------------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a NEEDRESTART_SUSPEND=1
# Keep lock waits short and visible — long silent DPkg::Lock::Timeout looks like a hang.
APT_LOCK_TIMEOUT="${APT_LOCK_TIMEOUT:-90}"
APT_OPTS=(-y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold -o "DPkg::Lock::Timeout=${APT_LOCK_TIMEOUT}")

# Wait for dpkg/apt locks with progress (unattended-upgrades often holds them on Ubuntu 24.04).
apt_wait_lock() {
  local i holder
  for ((i = 0; i < 120; i++)); do
    if ! fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 \
      && ! fuser /var/lib/apt/lists/lock >/dev/null 2>&1 \
      && ! fuser /var/lib/dpkg/lock >/dev/null 2>&1; then
      return 0
    fi
    if (( i == 0 || i % 5 == 0 )); then
      holder="$(fuser -v /var/lib/dpkg/lock-frontend 2>&1 | tr '\n' ' ' | sed 's/  */ /g' || true)"
      log_info "waiting for apt/dpkg lock… ${holder:-still held}"
    fi
    # Soft-stop unattended-upgrades so updates do not sit for tens of minutes
    if (( i == 3 )); then
      systemctl stop unattended-upgrades.service 2>/dev/null || true
    fi
    sleep 2
  done
  log_warn "apt/dpkg lock still held after ~4 minutes — continuing; apt may fail"
  return 0
}

apt_update() {
  log_info "apt-get update…"
  apt_wait_lock
  apt-get update -o "DPkg::Lock::Timeout=${APT_LOCK_TIMEOUT}" -qq
  log_ok "apt-get update done"
}

pkg_installed() {
  [[ "$(dpkg-query -W -f='${Status}' "$1" 2>/dev/null || true)" == *"install ok installed"* ]]
}

apt_install() { # packages... (installs only the ones that are missing)
  local missing=() p
  for p in "$@"; do pkg_installed "$p" || missing+=("$p"); done
  if (( ${#missing[@]} )); then
    log_info "apt install: ${missing[*]}"
    apt_wait_lock
    apt-get install "${APT_OPTS[@]}" --no-install-recommends "${missing[@]}"
    note_change "installed packages: ${missing[*]}"
  else
    log_info "apt packages already installed: $*"
  fi
}

apt_install_optional() { # packages... (each tried separately, failures only warn)
  local p need=()
  for p in "$@"; do pkg_installed "$p" || need+=("$p"); done
  ((${#need[@]})) || return 0
  apt_wait_lock
  for p in "${need[@]}"; do
    log_info "optional apt: trying ${p}…"
    if apt-get install "${APT_OPTS[@]}" --no-install-recommends "$p" >/dev/null 2>&1; then
      note_change "installed optional package ${p}"
      log_ok "optional package ${p}"
    else
      log_warn "optional package not installed: ${p}"
    fi
  done
}

# Download with retries; verifies sha256 when given.
download() { # url dest [sha256]
  local url=$1 dest=$2 sha=${3:-}
  curl -fsSL --retry 4 --retry-delay 3 --connect-timeout 15 -o "$dest" "$url" || die "Download failed: $url"
  if [[ -n "$sha" ]]; then
    echo "${sha}  ${dest}" | sha256sum -c - >/dev/null || die "SHA256 mismatch for $url"
  fi
}

# ---------------------------------------------------------------------------
# systemd
# ---------------------------------------------------------------------------
svc_apply() { # unit [restart_if_active=0]
  local unit=$1 changed=${2:-0}
  systemctl enable "$unit" >/dev/null 2>&1 || log_warn "could not enable ${unit}"
  if systemctl is-active --quiet "$unit"; then
    if [[ "$changed" == "1" ]]; then
      systemctl restart "$unit" || { journalctl -u "$unit" -n 40 --no-pager >&2 || true; die "Failed to restart ${unit}"; }
    fi
  else
    systemctl start "$unit" || { journalctl -u "$unit" -n 40 --no-pager >&2 || true; die "Failed to start ${unit}"; }
  fi
  sleep 1
  systemctl is-active --quiet "$unit" || { journalctl -u "$unit" -n 40 --no-pager >&2 || true; die "${unit} is not active"; }
  log_ok "${unit} active"
}

daemon_reload() { systemctl daemon-reload; }

wait_for_tcp() { # host port [timeout=30]
  local h=$1 p=$2 t=${3:-30} i
  for ((i = 0; i < t; i++)); do
    if timeout 1 bash -c "exec 3<>/dev/tcp/${h}/${p}" 2>/dev/null; then return 0; fi
    sleep 1
  done
  return 1
}

# ---------------------------------------------------------------------------
# MySQL (root via auth_socket - no root password is ever stored)
# ---------------------------------------------------------------------------
mysql_root() { mysql --protocol=socket -uroot "$@"; }

mysql_scalar() { mysql_root -N -B -e "$1"; }

mysql_table_exists() { # db table
  [[ "$(mysql_scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$1' AND table_name='$2'")" -gt 0 ]]
}

# ---------------------------------------------------------------------------
# Stage bootstrap
# ---------------------------------------------------------------------------
stage_init() { # stage_name
  STAGE_NAME="$1"
  STAGE_FILE="$(basename "${BASH_SOURCE[1]}")"
  export STAGE_NAME STAGE_FILE
  trap 'trap_err $LINENO' ERR
  require_root
  setup_logging
  init_config
  detect_os
  log_step "Stage ${STAGE_NAME}"
}
