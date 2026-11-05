#!/usr/bin/env bash
# Remove OpenSIPS completely: packages, module packages, apt repo, unit, user, and the unused opensips schema.
# Does not drop the ownswitch database or the cgrates database.
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/common.sh"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0" >&2
  exit 1
fi

log_warn "Removing OpenSIPS and every opensips-* module package."

systemctl disable --now opensips 2>/dev/null || true
systemctl reset-failed opensips 2>/dev/null || true
systemctl kill -s SIGKILL opensips 2>/dev/null || true
pkill -9 -x opensips 2>/dev/null || true
pkill -9 -x opensips-cli 2>/dev/null || true

rm -f /etc/systemd/system/opensips.service
rm -rf /etc/systemd/system/opensips.service.d
rm -f /lib/systemd/system/opensips.service /usr/lib/systemd/system/opensips.service
systemctl daemon-reload || true

mapfile -t pkgs < <(dpkg-query -W -f='${Package}\n' 'opensips*' 2>/dev/null || true)
if ((${#pkgs[@]})); then
  apt-mark unhold "${pkgs[@]}" >/dev/null 2>&1 || true
  DEBIAN_FRONTEND=noninteractive apt-get purge -y "${pkgs[@]}"
fi
DEBIAN_FRONTEND=noninteractive apt-get autoremove -y --purge || true

left="$(dpkg-query -W -f='${Package}\n' 'opensips*' 2>/dev/null || true)"
if [[ -n "${left}" ]]; then
  die "OpenSIPS packages are still installed: ${left}"
fi

rm -f /etc/apt/sources.list.d/*opensips* /etc/apt/sources.list.d/opensips.list
rm -f /usr/share/keyrings/opensips-org.gpg /usr/share/keyrings/opensips*.gpg
rm -f /etc/apt/keyrings/opensips*.gpg /etc/apt/trusted.gpg.d/*opensips*
apt-get update -qq || true

rm -rf /etc/opensips /run/opensips /var/run/opensips /var/lib/opensips /var/log/opensips
rm -rf /usr/lib/opensips /usr/lib/*/opensips /usr/share/opensips /usr/share/doc/opensips /usr/share/doc/opensips-*
rm -f /usr/sbin/opensips /usr/bin/opensips /usr/bin/opensips-cli /etc/default/opensips /etc/logrotate.d/opensips
rm -f /etc/apparmor.d/usr.sbin.opensips /etc/apparmor.d/local/usr.sbin.opensips /etc/apparmor.d/disable/usr.sbin.opensips

if id opensips >/dev/null 2>&1; then
  userdel opensips 2>/dev/null || true
fi
if getent group opensips >/dev/null 2>&1; then
  groupdel opensips 2>/dev/null || true
fi

# Unused switch schema only. ownswitch and cgrates stay.
if mysql --protocol=socket -uroot -N -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='opensips'" 2>/dev/null | grep -qx opensips; then
  mysql --protocol=socket -uroot -e "DROP DATABASE opensips;"
  log_ok "Dropped MySQL database opensips"
fi
mysql --protocol=socket -uroot -e "DROP USER IF EXISTS 'opensips'@'localhost'; DROP USER IF EXISTS 'opensips'@'127.0.0.1'; FLUSH PRIVILEGES;" 2>/dev/null || true

if [[ -x /usr/sbin/opensips || -d /etc/opensips ]]; then
  die "OpenSIPS files are still on disk"
fi
log_ok "OpenSIPS packages, modules, repo, user, and database are gone. The ownswitch database was not touched."

if systemctl is-active --quiet freeswitch; then
  log_ok "FreeSWITCH is active."
else
  log_warn "freeswitch is not active. Run: sudo bash ${DEPLOY_DIR}/install.sh --only 75"
fi
if ss -H -ulnp 'sport = :5060' 2>/dev/null | grep -q freeswitch; then
  log_ok "UDP 5060 is FreeSWITCH"
else
  log_warn "UDP 5060 is not FreeSWITCH yet. Phones cannot register until it is."
fi

echo "Phones must register again. SIP usernames and passwords are unchanged."
echo "Then: sudo bash ${DEPLOY_DIR}/install.sh --only 99"
