#!/usr/bin/env bash
# Install portal login fail2ban jail + refresh multi-jail helper.
set -euo pipefail
SRC="$(cd "$(dirname "$0")/.." && pwd)"
[[ $EUID -eq 0 ]] || { echo "Run as root"; exit 1; }

apt-get install -y fail2ban >/dev/null

install -m 0644 "$SRC/deploy/templates/fail2ban/opensips-auth.conf" /etc/fail2ban/filter.d/opensips-auth.conf
install -m 0644 "$SRC/deploy/templates/fail2ban/ownswitch-portal.conf" /etc/fail2ban/filter.d/ownswitch-portal.conf
install -m 0755 "$SRC/deploy/templates/fail2ban/ownswitch-fail2ban.sh" /usr/local/sbin/ownswitch-fail2ban
install -m 0440 "$SRC/deploy/templates/fail2ban/ownswitch-fail2ban.sudoers" /etc/sudoers.d/ownswitch-fail2ban
visudo -cf /etc/sudoers.d/ownswitch-fail2ban

if [[ ! -f /etc/fail2ban/jail.d/ownswitch-sip.local ]]; then
  cat >/etc/fail2ban/jail.d/ownswitch-sip.local <<'EOF'
# Managed by Ownswitch
[opensips-auth]
enabled  = true
filter   = opensips-auth
port     = 5060
protocol = udp,tcp
backend  = auto
logpath  = /usr/local/freeswitch/log/auth.log
           /var/log/auth.log
maxretry = 5
findtime = 10m
bantime  = 24h
ignoreip = 127.0.0.1/8 ::1
usedns   = no
action   = %(action_)s
EOF
  chmod 0644 /etc/fail2ban/jail.d/ownswitch-sip.local
fi

if [[ ! -f /etc/fail2ban/jail.d/ownswitch-portal.local ]]; then
  install -m 0644 "$SRC/deploy/templates/fail2ban/ownswitch-portal.local" /etc/fail2ban/jail.d/ownswitch-portal.local
else
  # Keep operator overrides; still ensure filter exists
  true
fi

systemctl enable fail2ban >/dev/null 2>&1 || true
systemctl restart fail2ban
sleep 1
fail2ban-client status opensips-auth || true
fail2ban-client status ownswitch-portal || true

echo
echo " Portal firewall: fail2ban jail ownswitch-portal (5 fails / 10m / 1h)."
echo " SIP firewall: opensips-auth (existing)."
echo " Helper: /usr/local/sbin/ownswitch-fail2ban status-all"
