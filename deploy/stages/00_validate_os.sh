#!/usr/bin/env bash
# Stage 00 - validate the operating system. (chmod +x on Linux; run via install.sh)
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
stage_init "00_validate_os"

fail=0

if [[ "${OS_ID}" != "ubuntu" || "${OS_VERSION_ID}" != "24.04" ]]; then
  if is_true "${OWNSWITCH_SKIP_OS_CHECK:-0}"; then
    log_warn "Unsupported OS (${OS_PRETTY}) but OWNSWITCH_SKIP_OS_CHECK=1 - continuing UNSUPPORTED"
  else
    log_error "Ownswitch requires Ubuntu Server 24.04 LTS (noble); found: ${OS_PRETTY}"
    fail=1
  fi
else
  log_ok "OS: ${OS_PRETTY}"
fi

if [[ "${OS_CODENAME}" != "noble" ]] && ! is_true "${OWNSWITCH_SKIP_OS_CHECK:-0}"; then
  log_error "Expected codename 'noble', found '${OS_CODENAME}'"
  fail=1
fi

if [[ "${OS_ARCH}" != "amd64" ]] && ! is_true "${OWNSWITCH_ALLOW_ARCH_ANY:-0}"; then
  log_error "Architecture '${OS_ARCH}' is not validated (amd64 only). Set OWNSWITCH_ALLOW_ARCH_ANY=1 to override."
  fail=1
else
  log_ok "Architecture: ${OS_ARCH}"
fi

if [[ "$(ps -p 1 -o comm= 2>/dev/null | tr -d ' ')" != "systemd" ]]; then
  log_error "PID 1 is not systemd (container without systemd?). Ownswitch needs a full systemd host/VM."
  fail=1
else
  log_ok "systemd is PID 1"
fi

# Containers (LXC/Docker) cannot load the RTPengine kernel module or manage sysctls reliably.
virt="$(systemd-detect-virt --container 2>/dev/null || true)"
if [[ -n "$virt" && "$virt" != "none" ]]; then
  log_warn "Running inside a container (${virt}): kernel-mode RTP forwarding and some sysctls will be unavailable."
fi

# Existing conflicting installs
if pkg_installed "mysql-server-8.0" || pkg_installed "mysql-server-core-8.0"; then
  log_error "Ubuntu's MySQL 8.0 packages are installed. Ownswitch needs MySQL 8.4 LTS from the MySQL APT repo."
  log_error "Remove them manually (after backing up data) and re-run; the installer will not touch existing data."
  fail=1
fi
if pkg_installed "mariadb-server"; then
  log_error "MariaDB is installed; it is not supported (MySQL 8.4 LTS required)."
  fail=1
fi

if (( fail )); then
  die "OS validation failed"
fi
log_ok "OS validation passed"
