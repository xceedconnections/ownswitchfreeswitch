#!/usr/bin/env bash
# Stage 60 - CGRateS v0.10: Redis DataDB + MySQL StorDB (separate `cgrates` database).
#
# Install order:
#   1) CGRATES_BIN_TARBALL_URL if set
#   2) Official .deb from pkg.cgrates.org (bookworm amd64) — reliable on Ubuntu 24.04
#   3) go build from git tag ${CGRATES_VERSION}
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
stage_init "60_cgrates"

BIN_DIR="/usr/local/bin"
SHARE_DIR="/usr/share/cgrates"
VERSION_MARK="${SHARE_DIR}/.installed-version"
CGR_BINS=(cgr-engine cgr-migrator cgr-loader cgr-console cgr-tester)
# Official package archive (v0.10 line). Bookworm debs run on Ubuntu 24.04.
CGRATES_DEB_URL="${CGRATES_DEB_URL:-http://pkg.cgrates.org/deb/v0.10/bookworm/cgrates_current_amd64.deb}"

mysql_root -e 'SELECT 1' >/dev/null 2>&1 || die "MySQL not reachable - run stage 30 first"
systemctl is-active --quiet redis-server || die "Redis not running - run stage 40 first"

ensure_system_user cgrates /var/lib/cgrates
install -d -m 0750 -o cgrates -g cgrates /var/lib/cgrates /var/log/cgrates
install -d -m 0755 "${SHARE_DIR}"

find_cgr_bin() { # name
  local n=$1
  if [[ -x "${BIN_DIR}/${n}" ]]; then echo "${BIN_DIR}/${n}"; return 0; fi
  if [[ -x "/usr/bin/${n}" ]]; then echo "/usr/bin/${n}"; return 0; fi
  command -v "$n" 2>/dev/null || true
}

install_from_deb() {
  local deb="/tmp/cgrates_current_amd64.deb"
  log_info "Installing CGRateS from ${CGRATES_DEB_URL}"
  download "${CGRATES_DEB_URL}" "$deb" "${CGRATES_DEB_SHA256:-}"
  apt_install debconf
  DEBIAN_FRONTEND=noninteractive dpkg -i "$deb" || apt-get -f install -y
  rm -f "$deb"
  # Package may put binaries in /usr/bin; ensure VERSION_MARK
  local eng; eng="$(find_cgr_bin cgr-engine)"
  [[ -n "$eng" && -x "$eng" ]] || return 1
  # Prefer local copies in /usr/local/bin for our unit file
  for b in "${CGR_BINS[@]}"; do
    src="$(find_cgr_bin "$b")"
    if [[ -n "$src" && "$src" != "${BIN_DIR}/${b}" ]]; then
      install -m 0755 "$src" "${BIN_DIR}/${b}" 2>/dev/null || ln -sfn "$src" "${BIN_DIR}/${b}"
    fi
  done
  # Schema data often under /usr/share/cgrates from the package
  if [[ ! -d "${SHARE_DIR}/storage/mysql" ]]; then
    for cand in /usr/share/cgrates /usr/share/cgrates/data; do
      if [[ -d "${cand}/storage/mysql" ]]; then
        [[ "$cand" == "${SHARE_DIR}" ]] || rsync -a "${cand}/" "${SHARE_DIR}/"
        break
      fi
    done
  fi
  # If package still has no SQL schemas, fetch from git tag (data only)
  if [[ ! -d "${SHARE_DIR}/storage/mysql" ]]; then
    log_warn "Deb lacked storage/mysql — fetching schemas from git ${CGRATES_VERSION}"
    local w; w="$(mktemp -d)"
    git clone --quiet --depth 1 --branch "${CGRATES_VERSION}" https://github.com/cgrates/cgrates.git "$w/src" \
      || git clone --quiet --depth 1 --branch v0.10.4 https://github.com/cgrates/cgrates.git "$w/src"
    rsync -a "$w/src/data/storage/" "${SHARE_DIR}/storage/"
    rsync -a "$w/src/data/tariffplans/" "${SHARE_DIR}/tariffplans/" 2>/dev/null || true
    rm -rf "$w"
  fi
  printf '%s\n' "${CGRATES_VERSION}" >"${VERSION_MARK}"
  note_change "installed CGRateS via official deb"
  return 0
}

install_from_source() {
  local work; work="$(mktemp -d /tmp/cgrates-build.XXXXXX)"
  log_info "Building CGRateS ${CGRATES_VERSION} from source (go build)"
  apt_install git build-essential golang-go
  export GOPATH="/var/cache/ownswitch-build/go" GOCACHE="/var/cache/ownswitch-build/gocache" GOTOOLCHAIN=local
  install -d -m 0755 "$GOPATH" "$GOCACHE"
  git clone --quiet --depth 1 --branch "${CGRATES_VERSION}" https://github.com/cgrates/cgrates.git "$work/src"
  ( cd "$work/src"
    for b in "${CGR_BINS[@]}"; do
      log_info "go build ${b}"
      go build -o "${BIN_DIR}/${b}" "./cmd/${b}"
    done
    rsync -a --delete data/storage/ "${SHARE_DIR}/storage/"
    rsync -a --delete data/tariffplans/ "${SHARE_DIR}/tariffplans/"
  )
  printf '%s\n' "${CGRATES_VERSION}" >"${VERSION_MARK}"
  note_change "installed CGRateS ${CGRATES_VERSION} from source"
  rm -rf "$work"
}

# ---------------------------------------------------------------------------
# 1. Binaries
# ---------------------------------------------------------------------------
installed_ok=0
if [[ -x "$(find_cgr_bin cgr-engine)" && -d "${SHARE_DIR}/storage/mysql" ]]; then
  installed_ok=1
  [[ -f "${VERSION_MARK}" ]] || printf '%s\n' "${CGRATES_VERSION}" >"${VERSION_MARK}"
  log_ok "CGRateS already present ($(find_cgr_bin cgr-engine))"
fi

if (( ! installed_ok )); then
  if [[ -n "${CGRATES_BIN_TARBALL_URL}" ]]; then
    work="$(mktemp -d /tmp/cgrates-tgz.XXXXXX)"
    download "${CGRATES_BIN_TARBALL_URL}" "$work/cgr.tgz" "${CGRATES_BIN_TARBALL_SHA256}"
    mkdir -p "$work/x"; tar -xzf "$work/cgr.tgz" -C "$work/x"
    for b in "${CGR_BINS[@]}"; do
      src="$(find "$work/x" -type f -name "$b" | head -n1 || true)"
      [[ -n "$src" ]] && install -m 0755 "$src" "${BIN_DIR}/$b"
    done
    datadir="$(find "$work/x" -type d -name storage -path '*data*' | head -n1 || true)"
    if [[ -n "$datadir" ]]; then rsync -a "$(dirname "$datadir")/" "${SHARE_DIR}/"; fi
    printf '%s\n' "${CGRATES_VERSION}" >"${VERSION_MARK}"
    note_change "installed CGRateS from tarball"
    rm -rf "$work"
  else
    install_from_deb || {
      log_warn "Deb install failed — falling back to source build"
      install_from_source
    }
  fi
fi

ENG="$(find_cgr_bin cgr-engine)"
[[ -n "$ENG" && -x "$ENG" ]] || die "cgr-engine was not installed"
MIG="$(find_cgr_bin cgr-migrator)"
ver_out="$("$ENG" -version 2>&1 || true)"
log_info "cgr-engine: ${ver_out}"
printf '%s\n' "${CGRATES_VERSION}" >"${VERSION_MARK}"

# ---------------------------------------------------------------------------
# 2. StorDB schema
# ---------------------------------------------------------------------------
mysql_dir="${SHARE_DIR}/storage/mysql"
[[ -d "$mysql_dir" ]] || die "CGRateS MySQL schema directory missing: ${mysql_dir}"

schema_applied=0
if ! mysql_table_exists "${DB_CGRATES}" "cdrs"; then
  [[ -f "${mysql_dir}/create_cdrs_tables.sql" ]] || die "create_cdrs_tables.sql not found"
  mysql_root "${DB_CGRATES}" <"${mysql_dir}/create_cdrs_tables.sql"
  note_change "applied CGRateS create_cdrs_tables.sql"; schema_applied=1
fi
if ! mysql_table_exists "${DB_CGRATES}" "tp_rates"; then
  [[ -f "${mysql_dir}/create_tariffplan_tables.sql" ]] || die "create_tariffplan_tables.sql not found"
  mysql_root "${DB_CGRATES}" <"${mysql_dir}/create_tariffplan_tables.sql"
  note_change "applied CGRateS create_tariffplan_tables.sql"; schema_applied=1
fi

# ---------------------------------------------------------------------------
# 3. Configuration
# ---------------------------------------------------------------------------
export REDIS_PASSWORD CGRATES_DB_PASSWORD
REDIS_PASSWORD="$(get_secret redis_password)"
CGRATES_DB_PASSWORD="$(get_secret mysql_cgrates_password)"

install -d -m 0750 -o root -g cgrates /etc/cgrates
render_template "${TEMPLATE_DIR}/cgrates.json.tpl" /etc/cgrates/cgrates.json 0640 root cgrates
cfg_changed="${RENDER_CHANGED}"

if (( schema_applied )) || ! mysql_table_exists "${DB_CGRATES}" "versions"; then
  log_info "Setting CGRateS storage versions via cgr-migrator"
  if [[ -n "$MIG" ]]; then
    if ! "$MIG" -config_path=/etc/cgrates -exec='*set_versions' >/tmp/cgr-migrator.out 2>&1; then
      cat /tmp/cgr-migrator.out >&2 || true
      log_warn "cgr-migrator *set_versions failed (will still try to start cgr-engine)"
    fi
  fi
fi

# ---------------------------------------------------------------------------
# 4. systemd — use whichever path holds cgr-engine
# ---------------------------------------------------------------------------
export CGR_ENGINE_BIN="$ENG"
# Template historically hardcodes /usr/local/bin; rewrite unit ExecStart after render if needed.
render_template "${TEMPLATE_DIR}/systemd/cgrates.service" /etc/systemd/system/cgrates.service 0644 root root
if [[ "$ENG" != "/usr/local/bin/cgr-engine" ]]; then
  sed -i "s|^ExecStart=.*|ExecStart=${ENG} -config_path=/etc/cgrates|" /etc/systemd/system/cgrates.service
  cfg_changed=1
fi
[[ "${RENDER_CHANGED}" == "1" ]] && { daemon_reload; cfg_changed=1; }
daemon_reload
svc_apply cgrates 1

# ---------------------------------------------------------------------------
# 5. Verify
# ---------------------------------------------------------------------------
wait_for_tcp 127.0.0.1 2080 45 || { journalctl -u cgrates -n 60 --no-pager >&2 || true; die "CGRateS HTTP API (127.0.0.1:2080) not reachable"; }
pong="$(curl -fsS --max-time 5 -H 'Content-Type: application/json' \
  -d '{"method":"CoreSv1.Ping","params":[{}],"id":1}' "http://${CGR_HTTP_ADDR}/jsonrpc" 2>/dev/null || true)"
if [[ "$pong" == *Pong* ]]; then log_ok "CGRateS answered CoreSv1.Ping"
else log_warn "CGRateS ping response unexpected: ${pong:-<empty>}"; fi

for port in 2012 2013 2014 2080; do
  if ss -H -ltn "sport = :${port}" | awk '{print $4}' | grep -vqx "127.0.0.1:${port}"; then
    die "CGRateS port ${port} is bound to a non-loopback address!"
  fi
done

log_ok "CGRateS running (DataDB redis/${REDIS_CGR_DB}, StorDB mysql/${DB_CGRATES})"
