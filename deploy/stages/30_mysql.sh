#!/usr/bin/env bash
# Stage 30 - MySQL 8.4 LTS (Oracle MySQL APT repository) + three separate databases.
#
# Install path (documented, see docs/INSTALL.md "MySQL 8.4 LTS"):
#   MYSQL_INSTALL_METHOD=direct (default)
#       write /etc/apt/sources.list.d/mysql.list with a freshly imported signing keyring.
#   MYSQL_INSTALL_METHOD=apt-config
#       download mysql-apt-config_<ver>_all.deb, preseed mysql-8.4-lts, then refresh keys.
#
# Ubuntu's own `mysql-server` is 8.0 - this stage refuses to continue if anything but 8.4 results.
# root@localhost uses auth_socket: no MySQL root password exists or is stored.
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
stage_init "30_mysql"

MYSQL_KEYRING="/usr/share/keyrings/mysql.gpg"
MYSQL_KEY_URLS=(
  "https://repo.mysql.com/RPM-GPG-KEY-mysql-2025"
  "https://repo.mysql.com/RPM-GPG-KEY-mysql-2023"
)
MYSQL_KEY_FPR="BCA43417C3B485DD128EC6D4B7B3B788A8D3785C"

mysql_version() { mysqld --version 2>/dev/null | sed -n 's/.*Ver \([0-9][0-9.]*\).*/\1/p' | head -n1; }

# Oracle periodically expires/rotates the APT signing key. Always rebuild the keyring
# before apt update so EXPKEYSIG / NO_PUBKEY cannot break stage 30 on a clean host.
refresh_mysql_gpg_keys() {
  local tmpdir asci tmpf
  tmpdir="$(mktemp -d)"
  asci="${tmpdir}/keys.asc"
  : >"$asci"

  local url
  for url in "${MYSQL_KEY_URLS[@]}"; do
    tmpf="${tmpdir}/$(basename "$url")"
    if curl -fsSL --connect-timeout 15 --max-time 60 -o "$tmpf" "$url"; then
      cat "$tmpf" >>"$asci"
      log_ok "Fetched $(basename "$url")"
    else
      log_warn "Could not download ${url}"
    fi
  done

  # Renewed expiration for the same fingerprint also lives on the Ubuntu keyserver.
  if curl -fsSL --connect-timeout 15 --max-time 60 \
      "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x${MYSQL_KEY_FPR: -16}" \
      -o "${tmpdir}/keyserver.asc" 2>/dev/null; then
    cat "${tmpdir}/keyserver.asc" >>"$asci"
    log_ok "Fetched MySQL key from keyserver.ubuntu.com"
  else
    log_warn "keyserver.ubuntu.com fetch failed (continuing with repo.mysql.com keys)"
  fi

  [[ -s "$asci" ]] || die "No MySQL GPG key material downloaded"

  install -d -m 0755 /usr/share/keyrings
  gpg --batch --yes --dearmor -o "${MYSQL_KEYRING}.tmp" "$asci"
  mv -f "${MYSQL_KEYRING}.tmp" "${MYSQL_KEYRING}"
  chmod 644 "${MYSQL_KEYRING}"

  # mysql-apt-config historically drops its own keyring; keep it in sync.
  cp -f "${MYSQL_KEYRING}" /usr/share/keyrings/mysql-apt-config.gpg
  cp -f "${MYSQL_KEYRING}" /usr/share/keyrings/mysql-8.4.gpg
  # Remove stale trusted.gpg.d fragments that still carry the expired key blob.
  rm -f /etc/apt/trusted.gpg.d/mysql*.gpg /etc/apt/trusted.gpg.d/mysql*.asc 2>/dev/null || true

  rm -rf "$tmpdir"
  log_ok "MySQL APT keyring refreshed → ${MYSQL_KEYRING}"
}

disable_broken_mysql_lists() {
  # Temporarily neutralize unsigned/broken MySQL lists so the rest of apt can work.
  local f
  for f in /etc/apt/sources.list.d/mysql*.list; do
    [[ -f "$f" ]] || continue
    mv -f "$f" "${f}.disabled.ownswitch" 2>/dev/null || true
  done
}

write_mysql_list() {
  cat >/etc/apt/sources.list.d/mysql.list <<EOF
# Managed by Ownswitch installer — do not edit by hand
deb [signed-by=${MYSQL_KEYRING}] https://repo.mysql.com/apt/ubuntu ${OS_CODENAME} mysql-8.4-lts
deb [signed-by=${MYSQL_KEYRING}] https://repo.mysql.com/apt/ubuntu ${OS_CODENAME} mysql-tools
EOF
  # Prefer https; fall back to http if the host cannot speak TLS to repo.mysql.com
  if ! curl -fsS --connect-timeout 10 --max-time 20 -o /dev/null https://repo.mysql.com/ 2>/dev/null; then
    log_warn "HTTPS to repo.mysql.com failed; using http://"
    cat >/etc/apt/sources.list.d/mysql.list <<EOF
# Managed by Ownswitch installer — do not edit by hand
deb [signed-by=${MYSQL_KEYRING}] http://repo.mysql.com/apt/ubuntu ${OS_CODENAME} mysql-8.4-lts
deb [signed-by=${MYSQL_KEYRING}] http://repo.mysql.com/apt/ubuntu ${OS_CODENAME} mysql-tools
EOF
  fi
  # Drop conflicting lists left by old mysql-apt-config / prior failed runs.
  rm -f /etc/apt/sources.list.d/mysql-apt-config.list \
        /etc/apt/sources.list.d/mysql.list.disabled.ownswitch \
        /etc/apt/sources.list.d/mysql-apt-config.list.disabled.ownswitch 2>/dev/null || true
}

repo_via_apt_config() {
  local deb="/tmp/mysql-apt-config_${MYSQL_APT_CONFIG_VERSION}_all.deb"
  local url="https://dev.mysql.com/get/mysql-apt-config_${MYSQL_APT_CONFIG_VERSION}_all.deb"
  log_info "Fetching ${url}"
  download "$url" "$deb" "${MYSQL_APT_CONFIG_SHA256}"
  echo "mysql-apt-config mysql-apt-config/select-server select mysql-8.4-lts" | debconf-set-selections
  echo "mysql-apt-config mysql-apt-config/select-product select Ok"            | debconf-set-selections
  echo "mysql-apt-config mysql-apt-config/enable-repo select mysql-8.4-lts"    | debconf-set-selections 2>/dev/null || true
  DEBIAN_FRONTEND=noninteractive dpkg -i "$deb"
  rm -f "$deb"
  refresh_mysql_gpg_keys
  # Force our signed-by list so we do not depend on apt-config's keyring path.
  write_mysql_list
}

repo_direct() {
  refresh_mysql_gpg_keys
  write_mysql_list
}

# Avoid SIGPIPE/exit 141 under `set -o pipefail` when awk exits early.
candidate_version() {
  apt-cache policy mysql-server 2>/dev/null | awk '/Candidate:/ {print $2; found=1} END {exit 0}'
}

mysql_apt_update() {
  if ! apt_update; then
    log_warn "apt update failed — refreshing MySQL keys and retrying"
    refresh_mysql_gpg_keys
    write_mysql_list
    apt_update
  fi
}

# ---------------------------------------------------------------------------
# 1. Install
# ---------------------------------------------------------------------------
if pkg_installed mysql-community-server || pkg_installed mysql-server; then
  v="$(mysql_version)"
  [[ "$v" == 8.4.* ]] || die "MySQL is installed but version is '${v:-unknown}', expected 8.4.x. Not touching existing data."
  log_ok "MySQL ${v} already installed"
  # Keep keyring current so later apt updates on this host do not break.
  refresh_mysql_gpg_keys || true
else
  # Clear a half-broken repo from a previous failed run (exactly the EXPKEYSIG case).
  disable_broken_mysql_lists
  apt_update || true

  if [[ "${MYSQL_INSTALL_METHOD}" == "apt-config" ]]; then
    repo_via_apt_config || { log_warn "mysql-apt-config path failed, falling back to direct repo"; repo_direct; }
  else
    repo_direct
  fi
  mysql_apt_update
  cand="$(candidate_version)"
  if [[ "$cand" != 8.4.* ]]; then
    log_warn "apt candidate for mysql-server is '${cand}', retrying with direct repository definition"
    repo_direct
    mysql_apt_update
    cand="$(candidate_version)"
  fi
  [[ "$cand" == 8.4.* ]] || die "MySQL 8.4 LTS not available from apt (candidate='${cand}'). Check network / repo.mysql.com."
  # Empty root password => root@localhost authenticates via auth_socket.
  echo "mysql-community-server mysql-community-server/root-pass password"    | debconf-set-selections
  echo "mysql-community-server mysql-community-server/re-root-pass password" | debconf-set-selections
  apt_install mysql-server mysql-client
fi

v="$(mysql_version)"
[[ "$v" == 8.4.* ]] || die "Unexpected MySQL version '${v}' after install"

# ---------------------------------------------------------------------------
# 2. Configuration
# ---------------------------------------------------------------------------
ram_mb="$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo)"
pool_mb=$(( ram_mb * MYSQL_BUFFER_POOL_PCT / 100 ))
(( pool_mb >= 256 )) || pool_mb=256
export MYSQL_BUFFER_POOL_MB="$pool_mb"
if [[ "${MYSQL_OPENSIPS_AUTH_PLUGIN}" == "mysql_native_password" ]]; then
  export MYSQL_NATIVE_PASSWORD_SWITCH="ON"
else
  export MYSQL_NATIVE_PASSWORD_SWITCH="OFF"
fi

render_template "${TEMPLATE_DIR}/mysql-ownswitch.cnf.tpl" /etc/mysql/mysql.conf.d/99-ownswitch.cnf 0644 root root
svc_apply mysql "${RENDER_CHANGED}"

# ---------------------------------------------------------------------------
# 3. Wait + sanity
# ---------------------------------------------------------------------------
for _ in $(seq 1 30); do mysql_root -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
mysql_root -e 'SELECT 1' >/dev/null 2>&1 \
  || die "Cannot connect as root via auth_socket. If a previous install set a root password, run stage 30 manually after restoring socket auth."

# ---------------------------------------------------------------------------
# 4. Databases, users, grants (passwords generated once, re-synced on every run)
# ---------------------------------------------------------------------------
pw_ownswitch="$(ensure_secret mysql_ownswitch_password 40)"
pw_opensips="$(ensure_secret mysql_opensips_password 40)"
pw_cgrates="$(ensure_secret mysql_cgrates_password 40)"
pw_cgrates_ro="$(ensure_secret mysql_cgrates_ro_password 40)"

create_db() { mysql_root -e "CREATE DATABASE IF NOT EXISTS \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"; }

create_user() { # user password plugin db privileges  [extra_db_grant]
  local u=$1 pw=$2 plugin=$3 db=$4 privs=$5 host
  for host in localhost 127.0.0.1; do
    mysql_root <<SQL
CREATE USER IF NOT EXISTS '${u}'@'${host}' IDENTIFIED WITH ${plugin} BY '${pw}';
ALTER USER '${u}'@'${host}' IDENTIFIED WITH ${plugin} BY '${pw}';
GRANT ${privs} ON \`${db}\`.* TO '${u}'@'${host}';
SQL
  done
}

for d in "${DB_OWNSWITCH}" "${DB_OPENSIPS}" "${DB_CGRATES}"; do create_db "$d"; done

# Portal: full rights on its own DB only.
create_user ownswitch "$pw_ownswitch" caching_sha2_password "${DB_OWNSWITCH}" "ALL PRIVILEGES"
# OpenSIPS: its own schema only. The portal provisioner reuses this account (see ownswitch.env).
create_user opensips "$pw_opensips" "${MYSQL_OPENSIPS_AUTH_PLUGIN}" "${DB_OPENSIPS}" "ALL PRIVILEGES"
# CGRateS: owns the StorDB (needs DDL for cgr-migrator).
create_user cgrates "$pw_cgrates" caching_sha2_password "${DB_CGRATES}" "ALL PRIVILEGES"
# Portal read-only window into CGRateS StorDB (reconciliation).
create_user cgrates_ro "$pw_cgrates_ro" caching_sha2_password "${DB_CGRATES}" "SELECT"
mysql_root -e 'FLUSH PRIVILEGES'

# Hygiene: no anonymous users, no remote root, no test DB (fresh community installs have none; verify).
mysql_root -e "DELETE FROM mysql.user WHERE User='' ; DROP DATABASE IF EXISTS test; FLUSH PRIVILEGES" 2>/dev/null || true
remote_root="$(mysql_scalar "SELECT COUNT(*) FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost','127.0.0.1','::1')")"
(( remote_root == 0 )) || log_warn "root has ${remote_root} non-local host entries in mysql.user - review manually"

# Verify listener is loopback-only
if ss -H -ltn 'sport = :3306' | awk '{print $4}' | grep -vqE '^(127\.0\.0\.1|\[::1\]):3306$'; then
  die "MySQL is listening on a non-loopback address! Check /etc/mysql config."
fi

log_ok "MySQL ${v} ready: databases ${DB_OWNSWITCH}, ${DB_OPENSIPS}, ${DB_CGRATES}"
