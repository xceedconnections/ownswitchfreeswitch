#!/usr/bin/env bash
# Stage 10 - hardware/DNS/time checks, base packages, sysctl, base directory layout.
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
stage_init "10_system_checks"

warn_count=0; fail=0
soft_fail() { # message  (fails unless FORCE=1)
  if is_true "${FORCE}"; then log_warn "$1 (continuing, FORCE=1)"; warn_count=$((warn_count + 1))
  else log_error "$1 (set FORCE=1 to override)"; fail=1; fi
}

# --- CPU / RAM / disk -------------------------------------------------------
cpus="$(nproc)"
ram_mb="$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo)"
disk_gb="$(df -BG --output=avail / | tail -n1 | tr -dc '0-9')"
log_info "CPU=${cpus}  RAM=${ram_mb}MB  free disk on /=${disk_gb}GB"

(( cpus >= MIN_CPUS ))     || soft_fail "Only ${cpus} CPU(s); at least ${MIN_CPUS} required"
(( ram_mb >= MIN_RAM_MB )) || soft_fail "Only ${ram_mb}MB RAM; at least ${MIN_RAM_MB}MB required"
(( disk_gb >= MIN_DISK_GB )) || soft_fail "Only ${disk_gb}GB free on /; at least ${MIN_DISK_GB}GB required"

swap_mb="$(awk '/SwapTotal/ {printf "%d", $2/1024}' /proc/meminfo)"
(( swap_mb > 0 )) || { log_warn "No swap configured (recommended: 2GB for MySQL/CGRateS build)"; warn_count=$((warn_count + 1)); }

# --- DNS / connectivity -----------------------------------------------------
for host in archive.ubuntu.com apt.opensips.org repo.mysql.com github.com; do
  if getent hosts "$host" >/dev/null; then log_ok "DNS ok: ${host}"
  else soft_fail "DNS cannot resolve ${host}"; fi
done
if ! curl -fsS --max-time 10 -o /dev/null https://apt.opensips.org/ 2>/dev/null; then
  soft_fail "HTTPS to apt.opensips.org failed (proxy/firewall?)"
fi

# Domain should resolve to this host for Let's Encrypt (informational otherwise)
if [[ "${OWNSWITCH_DOMAIN}" != *.* ]]; then
  log_warn "OWNSWITCH_DOMAIN='${OWNSWITCH_DOMAIN}' is not a FQDN; set OWNSWITCH_DOMAIN for a proper TLS certificate"
  warn_count=$((warn_count + 1))
else
  resolved="$(getent ahostsv4 "${OWNSWITCH_DOMAIN}" 2>/dev/null | awk 'NR==1{print $1}' || true)"
  if [[ -z "$resolved" ]]; then
    log_warn "${OWNSWITCH_DOMAIN} does not resolve yet (Let's Encrypt will be skipped until it does)"
  elif [[ "$resolved" != "${PUBLIC_IP}" ]]; then
    log_warn "${OWNSWITCH_DOMAIN} resolves to ${resolved} but PUBLIC_IP=${PUBLIC_IP}"
  else
    log_ok "${OWNSWITCH_DOMAIN} -> ${resolved}"
  fi
fi

# --- Ports already in use by something else --------------------------------
for spec in "tcp:80" "tcp:443" "udp:${SIP_PORT}" "tcp:${SIP_PORT}" "tcp:3306" "tcp:6379"; do
  proto="${spec%%:*}"; port="${spec##*:}"
  flag="-ltn"; [[ "$proto" == "udp" ]] && flag="-lun"
  if ss -H $flag "sport = :${port}" 2>/dev/null | grep -q .; then
    owner="$(ss -H -lpn $flag "sport = :${port}" 2>/dev/null | grep -o 'users:(("[^"]*"' | head -n1 || true)"
    log_warn "Port ${proto}/${port} already in use ${owner} - fine on re-runs, otherwise investigate"
  fi
done

# --- Time sync --------------------------------------------------------------
if is_true "${SET_TIMEZONE_UTC}"; then
  if [[ "$(timedatectl show -p Timezone --value 2>/dev/null || true)" != "Etc/UTC" ]]; then
    timedatectl set-timezone Etc/UTC && note_change "timezone set to Etc/UTC"
  fi
fi

apt_update
apt_install ca-certificates curl gnupg lsb-release jq rsync perl openssl ufw gettext-base \
            software-properties-common unzip tar gzip iproute2 dnsutils chrony logrotate cron

systemctl enable --now chrony >/dev/null 2>&1 || log_warn "could not enable chrony"
sync_state="no"
for _ in $(seq 1 20); do
  if [[ "$(timedatectl show -p NTPSynchronized --value 2>/dev/null || echo no)" == "yes" ]] \
     || chronyc tracking 2>/dev/null | grep -q 'Leap status *: Normal'; then sync_state="yes"; break; fi
  sleep 2
done
if [[ "$sync_state" == "yes" ]]; then log_ok "Clock is NTP-synchronised"
else soft_fail "Clock is not synchronised (billing needs accurate time)"; fi

# --- sysctl tuning ----------------------------------------------------------
sysctl_file="/etc/sysctl.d/99-ownswitch.conf"
sysctl_tmp="$(mktemp)"
cat >"$sysctl_tmp" <<'EOF'
# Managed by Ownswitch installer (deploy/stages/10_system_checks.sh)
net.core.rmem_max = 26214400
net.core.wmem_max = 26214400
net.core.somaxconn = 4096
net.ipv4.tcp_syncookies = 1
net.ipv4.conf.all.rp_filter = 2
net.ipv4.conf.default.rp_filter = 2
net.ipv4.conf.all.accept_redirects = 0
net.ipv6.conf.all.accept_redirects = 0
vm.swappiness = 10
EOF
if ! cmp -s "$sysctl_tmp" "$sysctl_file" 2>/dev/null; then
  backup_file "$sysctl_file"
  install -m 0644 "$sysctl_tmp" "$sysctl_file"
  sysctl --system >/dev/null 2>&1 || log_warn "sysctl --system reported errors (container?)"
  note_change "wrote ${sysctl_file}"
fi
rm -f "$sysctl_tmp"

# --- layout + persisted settings -------------------------------------------
ensure_base_layout
persist_defaults

if (( fail )); then die "System checks failed"; fi
log_ok "System checks passed (${warn_count} warning(s))"
