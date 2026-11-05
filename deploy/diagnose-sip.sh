#!/usr/bin/env bash
# Full SIP registration diagnosis + auto-repair for Ownswitch.
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitch/main/deploy/diagnose-sip.sh | sudo bash
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_ETC="${OWN_ETC:-/etc/ownswitch}"
ENV_FILE="${OWN_ETC}/ownswitch.env"
SIP_PORT="${SIP_PORT:-5060}"

cd "$SRC"
git fetch origin main >/dev/null 2>&1 || true
git reset --hard origin/main >/dev/null 2>&1 || true

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "${OWN_ETC}/install.conf" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
DETECT_IP="$(curl -4 -s --max-time 5 ifconfig.me 2>/dev/null || curl -4 -s --max-time 5 icanhazip.com 2>/dev/null || true)"
LOCAL_IPS="$(hostname -I 2>/dev/null || true)"
PRIV="$(grep -E '^PRIVATE_IP=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)"
PUB="$(grep -E '^PUBLIC_IP=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)"

echo "================ SIP DIAGNOSE ================"
echo "Domain          : $DOMAIN"
echo "DNS A record    : ${DNS_IP:-unknown}"
echo "Detected egress : ${DETECT_IP:-unknown}"
echo "hostname -I     : $LOCAL_IPS"
echo "env PRIVATE_IP  : ${PRIV:-unset}"
echo "env PUBLIC_IP   : ${PUB:-unset}"
echo

# --- repair env / opensips password (same as fix-opensips-provision) ---
bash "$SRC/deploy/fix-opensips-provision.sh" || true

# Align PUBLIC_IP with DNS if DNS points at this host
if [[ -n "$DNS_IP" && " $LOCAL_IPS " == *" $DNS_IP "* ]]; then
  echo "DNS IP is local to this host — good."
elif [[ -n "$DNS_IP" && -n "$DETECT_IP" && "$DNS_IP" != "$DETECT_IP" ]]; then
  echo "WARNING: DNS ($DNS_IP) != detected public IP ($DETECT_IP)."
  echo "         Softphones using $DOMAIN may hit the wrong server, or Hetzner Floating IP is not on this VM."
fi

# Prefer DNS IP as advertised public if it is assigned here
ADV_IP="${DETECT_IP:-$PUB}"
if [[ -n "$DNS_IP" && " $LOCAL_IPS " == *" $DNS_IP "* ]]; then
  ADV_IP="$DNS_IP"
fi
PRIV_IP="${PRIV:-}"
if [[ -z "$PRIV_IP" ]]; then
  PRIV_IP="$(echo "$LOCAL_IPS" | awk '{print $1}')"
fi

echo
echo "== OpenSIPS service =="
systemctl enable opensips >/dev/null 2>&1 || true
systemctl restart opensips || true
sleep 1
systemctl --no-pager --full status opensips | head -n 25 || true

echo
echo "== Listening sockets =="
ss -ulnp | grep -E ":${SIP_PORT}\\b" || echo "FAIL: nothing listening UDP ${SIP_PORT}"
ss -tlnp | grep -E ":${SIP_PORT}\\b" || echo "(no TCP ${SIP_PORT})"

echo
echo "== Firewall =="
if command -v ufw >/dev/null; then
  ufw allow "${SIP_PORT}/udp" comment 'Ownswitch SIP' || true
  ufw allow "${SIP_PORT}/tcp" comment 'Ownswitch SIP TCP' || true
  ufw allow 30000:40000/udp comment 'Ownswitch RTP' || true
  ufw status numbered | head -n 40 || true
fi
# Hetzner cloud firewall cannot be changed from here — print reminder
echo "Also open UDP/TCP ${SIP_PORT} and UDP 30000-40000 in Hetzner Cloud Firewall if used."

echo
echo "== opensips.cfg sockets (live) =="
grep -E '^socket=' /etc/opensips/opensips.cfg || true

# Re-render OpenSIPS with correct advertised address if private != public
if [[ -n "$PRIV_IP" && -n "$ADV_IP" && "$PRIV_IP" != "$ADV_IP" ]]; then
  echo "Re-rendering OpenSIPS sockets: udp:${PRIV_IP}:${SIP_PORT} as ${ADV_IP}:${SIP_PORT}"
  export PRIVATE_IP="$PRIV_IP" PUBLIC_IP="$ADV_IP" SIP_PORT
  # Persist for stage 50
  if [[ -f "${OWN_ETC}/install.conf" ]]; then
    grep -vE '^(PRIVATE_IP|PUBLIC_IP)=' "${OWN_ETC}/install.conf" > /tmp/ic.$$ || true
    echo "PRIVATE_IP=${PRIV_IP}" >> /tmp/ic.$$
    echo "PUBLIC_IP=${ADV_IP}" >> /tmp/ic.$$
    mv /tmp/ic.$$ "${OWN_ETC}/install.conf"
  fi
  bash "$SRC/deploy/install.sh" --only 50 --yes --non-interactive || true
  systemctl restart opensips || true
fi

echo
echo "== subscriber / location counts =="
mysql --protocol=socket -uroot opensips -e "
SELECT COUNT(*) AS subscribers FROM subscriber;
SELECT id,username,domain,LEFT(ha1,10) ha1 FROM subscriber LIMIT 15;
SELECT COUNT(*) AS contacts FROM location;
SELECT username,domain,contact,received,expires FROM location LIMIT 10;
SELECT COUNT(*) AS gateways FROM dr_gateways;
SELECT gwid,address,description FROM dr_gateways LIMIT 15;
SELECT COUNT(*) AS registrants FROM uac_registrant;
SELECT l_uuid,l_username,r_domain,auth_proxy FROM uac_registrant LIMIT 10;
" 2>/dev/null || echo "MySQL opensips query failed"

echo
echo "== ownswitch sip_accounts =="
mysql --protocol=socket -uroot ownswitch -e "SELECT id,username,domain,status FROM sip_accounts;" 2>/dev/null || true

echo
echo "== local SIP OPTIONS probe =="
if command -v sipsak >/dev/null; then
  sipsak -vv -s "sip:${DOMAIN}" -H "$ADV_IP" || true
elif command -v nc >/dev/null; then
  printf 'OPTIONS sip:%s SIP/2.0\r\nVia: SIP/2.0/UDP 127.0.0.1:9;branch=z9hG4bKdiag\r\nFrom: <sip:diag@%s>;tag=diag\r\nTo: <sip:diag@%s>\r\nCall-ID: diag@127.0.0.1\r\nCSeq: 1 OPTIONS\r\nContact: <sip:diag@127.0.0.1:9>\r\nMax-Forwards: 70\r\nContent-Length: 0\r\n\r\n' "$DOMAIN" "$DOMAIN" "$DOMAIN" \
    | nc -u -w 2 127.0.0.1 "$SIP_PORT" || true
else
  echo "(install sipsak for a richer probe)"
fi

echo
echo "== recent OpenSIPS log (REGISTER) =="
journalctl -u opensips -n 80 --no-pager 2>/dev/null | grep -iE 'REGISTER|auth|subscriber|error|fail' | tail -n 40 || true

CUR=$(readlink -f /opt/ownswitch/current)
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-trunks.php" || true

echo
echo "============================================================"
echo " SOFTPHONE — use these exact values"
echo "============================================================"
echo "  Username : <SIP username from portal>"
echo "  Password : reset in portal (Save shows it once)"
echo "  Domain   : ${DOMAIN}"
echo "  Registrar: ${DOMAIN}"
echo "  Proxy    : ${ADV_IP:-$DNS_IP}   (or ${DOMAIN})"
echo "  Port     : ${SIP_PORT}"
echo "  Transport: try UDP first; if it fails use TCP (Hetzner often blocks UDP until firewall rule added)"
echo "  Auth user: same as Username"
echo
echo " HETZNER CLOUD FIREWALL (required):"
echo "   Inbound UDP ${SIP_PORT}, TCP ${SIP_PORT}, UDP 30000-40000 → this server"
echo "   Without UDP ${SIP_PORT}, most softphones will never register."
echo
echo "After reset password in portal, check:"
echo "  mysql -uroot opensips -e \"SELECT username,domain FROM subscriber;\""
echo "  journalctl -u opensips -f   # while softphone registers"
echo
echo " CARRIER / VENDOR (Magnus)"
echo "------------------------------------------------------------"
echo " IP peer trunk: vendor must allowlist ${ADV_IP:-$DNS_IP}"
echo "   → Magnus will NOT show 'Registered' for IP authentication."
echo " Register trunk: portal Trunk → Auth mode = Register + user/pass"
echo "   → needs opensips-uac-module; check uac_registrant rows above."
echo "============================================================"
