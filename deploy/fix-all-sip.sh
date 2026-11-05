#!/usr/bin/env bash
# One-shot: repair OpenSIPS provisioning + print exact softphone / vendor steps.
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitch/main/deploy/fix-all-sip.sh | sudo bash
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
cd "$SRC"
git fetch origin main && git reset --hard origin/main

bash "$SRC/deploy/fix-opensips-provision.sh"
bash "$SRC/deploy/fix-drouting-schema.sh"
bash "$SRC/deploy/diagnose-sip.sh" || true

OWN_ETC=/etc/ownswitch
ENV_FILE="${OWN_ETC}/ownswitch.env"
DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "${OWN_ETC}/install.conf" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
PUB="$(curl -4 -s --max-time 5 ifconfig.me 2>/dev/null || true)"
SIP_IP="${DNS_IP:-$PUB}"

# Force every portal SIP account onto the correct realm domain (HA1 still needs password reset)
mysql --protocol=socket -uroot ownswitch -e "UPDATE sip_accounts SET domain='${DOMAIN}' WHERE domain<>'${DOMAIN}' OR domain IS NULL OR domain='';" 2>/dev/null || true

echo
echo "######################################################################"
echo " READ THIS — two different things"
echo "######################################################################"
echo
echo "A) SOFTPHONE (SIP User) — Ownswitch must receive REGISTER"
echo "   1. Hetzner Cloud Firewall → allow INBOUND:"
echo "        UDP 5060, TCP 5060, UDP 30000-40000"
echo "   2. Portal → SIP Users → edit user → set NEW password → Save"
echo "      (password is shown once; provisioning writes opensips.subscriber)"
echo "   3. Softphone:"
echo "        Username  = SIP username"
echo "        Password  = the new password"
echo "        Domain    = ${DOMAIN}"
echo "        Registrar = ${DOMAIN}"
echo "        Proxy/IP  = ${SIP_IP}"
echo "        Port      = 5060"
echo "        Transport = UDP  (if fail, switch to TCP)"
echo "   4. Check portal System Status → OpenSIPS:"
echo "        subscribers > 0 and registered_contacts > 0 when softphone is online"
echo "   5. Live log:  journalctl -u opensips -f"
echo
echo "B) CARRIER / VENDOR MAGNUS — IP peer does NOT register"
echo "   Ownswitch does NOT show as 'Registered' on Magnus for IP trunks."
echo "   Give vendor this IP to allowlist:  ${SIP_IP}"
echo "   On their Magnus: Customer/SIP → IP Authentication = ${SIP_IP}"
echo "   Only if they gave SIP user+password requiring REGISTER:"
echo "     Trunk → Auth mode = Register → username/password → Save"
echo
echo "Verify DB after softphone password reset:"
echo "  mysql -uroot opensips -e \"SELECT username,domain FROM subscriber; SELECT username,contact FROM location;\""
echo "  mysql -uroot opensips -e \"SELECT gwid,address,description FROM dr_gateways;\""
echo "######################################################################"
