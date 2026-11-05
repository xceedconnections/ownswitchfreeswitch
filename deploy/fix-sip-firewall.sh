#!/usr/bin/env bash
# Install OpenSIPS fail2ban jail + portal helper + INVITE auth-fail log line.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-sip-firewall.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
SIP_PORT="${SIP_PORT:-5060}"
cd "$SRC"
git fetch origin main
git reset --hard origin/main

apt-get install -y fail2ban >/dev/null

install -m 0644 "$SRC/deploy/templates/fail2ban/opensips-auth.conf" /etc/fail2ban/filter.d/opensips-auth.conf
if [[ -f "$SRC/deploy/templates/fail2ban/ownswitch-portal.conf" ]]; then
  install -m 0644 "$SRC/deploy/templates/fail2ban/ownswitch-portal.conf" /etc/fail2ban/filter.d/ownswitch-portal.conf
fi
install -m 0755 "$SRC/deploy/templates/fail2ban/ownswitch-fail2ban.sh" /usr/local/sbin/ownswitch-fail2ban
install -m 0440 "$SRC/deploy/templates/fail2ban/ownswitch-fail2ban.sudoers" /etc/sudoers.d/ownswitch-fail2ban
visudo -cf /etc/sudoers.d/ownswitch-fail2ban

if [[ ! -f /etc/fail2ban/jail.d/ownswitch-sip.local ]]; then
  cat >/etc/fail2ban/jail.d/ownswitch-sip.local <<EOF
# Managed by Ownswitch
[opensips-auth]
enabled  = true
filter   = opensips-auth
port     = ${SIP_PORT}
protocol = udp,tcp
backend  = systemd
journalmatch = _SYSTEMD_UNIT=opensips.service
maxretry = 5
findtime = 10m
bantime  = 1h
action   = %(action_)s
EOF
  chmod 0644 /etc/fail2ban/jail.d/ownswitch-sip.local
fi

# Ensure SSH jail defaults file exists (do not wipe)
if [[ ! -f /etc/fail2ban/jail.d/ownswitch.local ]]; then
  cat >/etc/fail2ban/jail.d/ownswitch.local <<EOF
[DEFAULT]
bantime  = 1h
findtime = 10m
maxretry = 5
backend  = systemd

[sshd]
enabled = true
EOF
fi

# Do NOT patch opensips.cfg here — use deploy/fix-opensips-start-now.sh for SIP cfg/start.
# Firewall install must not risk breaking REGISTER.

if [[ -f "$SRC/deploy/templates/fail2ban/ownswitch-portal.local" && ! -f /etc/fail2ban/jail.d/ownswitch-portal.local ]]; then
  install -m 0644 "$SRC/deploy/templates/fail2ban/ownswitch-portal.local" /etc/fail2ban/jail.d/ownswitch-portal.local
fi

systemctl enable fail2ban >/dev/null 2>&1 || true
systemctl restart fail2ban
sleep 1
fail2ban-client status opensips-auth || true
fail2ban-client status ownswitch-portal || true

bash "$SRC/deploy/refresh-ui.sh"

echo
echo "################################################################"
echo " SIP firewall: fail2ban jail opensips-auth (5 fails / 10m / 1h)."
echo " Portal firewall: ownswitch-portal (login CAPTCHA + password fails)."
echo " Portal UI: Settings → Firewall  (unban / thresholds / manual ban)."
echo " Helper: /usr/local/sbin/ownswitch-fail2ban status-all"
echo "################################################################"
