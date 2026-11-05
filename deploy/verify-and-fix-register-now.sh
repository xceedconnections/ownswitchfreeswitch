#!/usr/bin/env bash
# One-shot: show WHY REGISTER is open, install auth modules, apply Ownswitch cfg, prove 401.
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main && bash deploy/verify-and-fix-register-now.sh
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
cd "$SRC"
git fetch origin main && git reset --hard origin/main

echo "======== 1) Process on 5060 ========"
ss -ulnp | grep -E ':5060\b' || true
ss -tlnp | grep -E ':5060\b' || true
echo "systemctl:"
systemctl cat opensips 2>/dev/null | grep -E 'ExecStart|FragmentPath' || true
MAINPID=$(systemctl show -p MainPID --value opensips 2>/dev/null || echo 0)
echo "MainPID=$MAINPID"
if [[ "${MAINPID}" != "0" && -r /proc/$MAINPID/cmdline ]]; then
  tr '\0' ' ' < /proc/$MAINPID/cmdline; echo
fi

echo "======== 2) Live cfg auth/REGISTER (must show www_authorize) ========"
if [[ ! -f "$CFG" ]]; then echo "MISSING $CFG"; exit 1; fi
grep -nE 'mpath=|loadmodule "auth|calculate_ha1|www_authorize|www_challenge|is_method\("REGISTER"\)|rewritehost|alias=' "$CFG" | head -n 60 || true
if ! grep -q 'www_authorize' "$CFG"; then
  echo ">>> LIVE CFG HAS NO www_authorize — that is why you get open 200"
fi
if ! grep -q 'loadmodule "auth.so"' "$CFG"; then
  echo ">>> LIVE CFG HAS NO loadmodule auth.so"
fi

echo "======== 3) Module files ========"
MPATH="$(dirname "$(find /usr/lib -name tm.so -path '*opensips/modules*' 2>/dev/null | head -1)")/"
echo "MPATH=$MPATH"
for so in auth.so auth_db.so usrloc.so registrar.so sipmsgops.so; do
  [[ -f "$MPATH$so" ]] && echo "OK $so" || echo "MISSING $so"
done

echo "======== 4) Install opensips-auth-modules + apply stage 50 ========"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq || true
apt-get install -y opensips-auth-modules opensips-mysql-module opensips-nathelper-module || true
[[ -f "${MPATH}auth.so" ]] || {
  echo "FATAL: auth.so still missing after apt install opensips-auth-modules"
  apt-cache policy opensips-auth-modules || true
  dpkg -L opensips-auth-modules 2>/dev/null | head || true
  exit 1
}

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' /etc/ownswitch/install.conf 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
PUBLIC_IP="$(curl -4 -s --max-time 5 ifconfig.me || true)"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
LOCAL="$(hostname -I || true)"
if [[ -n "$DNS_IP" && " $LOCAL " == *" $DNS_IP "* ]]; then PUBLIC_IP="$DNS_IP"; fi
[[ -n "$PUBLIC_IP" ]] || PUBLIC_IP="$DNS_IP"
PRIV="$(echo "$LOCAL" | awk '{print $1}')"
export OWNSWITCH_DOMAIN="$DOMAIN" PUBLIC_IP="$PUBLIC_IP" PRIVATE_IP="$PRIV" SIP_PORT=5060
echo "DOMAIN=$DOMAIN PUBLIC_IP=$PUBLIC_IP"

# Persist
IC=/etc/ownswitch/install.conf
grep -vE '^(PRIVATE_IP|PUBLIC_IP|OWNSWITCH_DOMAIN)=' "$IC" > /tmp/ic.$$ 2>/dev/null || true
{ echo "OWNSWITCH_DOMAIN=$DOMAIN"; echo "PUBLIC_IP=$PUBLIC_IP"; echo "PRIVATE_IP=$PRIV"; cat /tmp/ic.$$; } > "$IC"
rm -f /tmp/ic.$$

bash "$SRC/deploy/fix-opensips-provision.sh" || true
bash "$SRC/deploy/install.sh" --only 50 --yes --non-interactive

echo "======== 5) After stage 50 — cfg must contain www_authorize ========"
grep -nE 'www_authorize|calculate_ha1|is_method\("REGISTER"\)|rewritehost|alias="' "$CFG" | head -n 40
grep -q 'www_authorize' "$CFG" || { echo "FATAL: stage 50 left cfg without www_authorize"; tail -n 60 /var/log/ownswitch-install.log; exit 1; }

install -d /etc/systemd/system/opensips.service.d
cat >/etc/systemd/system/opensips.service.d/ownswitch.conf <<EOF
[Service]
Type=simple
ExecStart=
ExecStart=/usr/sbin/opensips -F -f /etc/opensips/opensips.cfg
Restart=on-failure
RestartSec=2
TimeoutStartSec=30
EOF
systemctl daemon-reload

opensips -c -f "$CFG"

echo "======== 6) Hard restart ========"
systemctl stop opensips || true
sleep 1
fuser -k 5060/udp 2>/dev/null || true
fuser -k 5060/tcp 2>/dev/null || true
sleep 1
systemctl start opensips
sleep 2
systemctl is-active opensips
ss -ulnp | grep 5060 || true

# seed password for owner
mysql --protocol=socket -uroot opensips -e \
  "UPDATE subscriber SET password='Openaccount@12345.' WHERE username='owner';" 2>/dev/null || true
bash "$SRC/deploy/refresh-ui.sh" || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
  php /opt/ownswitch/current/bin/sync-opensips-sip-users.php || true

echo "======== 7) Probe (MUST be 401) ========"
python3 - "$DOMAIN" "$PUBLIC_IP" <<'PY'
import socket, sys
domain, ip = sys.argv[1], sys.argv[2]

def probe(host):
    msg = (
      f"REGISTER sip:{host} SIP/2.0\r\n"
      f"Via: SIP/2.0/UDP 127.0.0.1:5099;branch=z9hG4bKv;rport\r\n"
      f"Max-Forwards: 70\r\n"
      f"From: <sip:v@{host}>;tag=t\r\n"
      f"To: <sip:v@{host}>\r\n"
      f"Call-ID: v-{host}@127.0.0.1\r\n"
      f"CSeq: 1 REGISTER\r\n"
      f"Contact: <sip:v@127.0.0.1:5099>\r\n"
      f"Expires: 60\r\n"
      f"Content-Length: 0\r\n\r\n"
    )
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.settimeout(3)
    s.sendto(msg.encode(), ("127.0.0.1", 5060))
    try:
        return s.recvfrom(8192)[0].decode(errors="replace").splitlines()[0]
    except Exception as e:
        return f"NO_RESPONSE {e}"
    finally:
        s.close()

for label, host in (("Domain", domain), ("IP", ip)):
    line = probe(host)
    print(f"{label} {host} → {line}")
    if not (line.startswith("SIP/2.0 401") or line.startswith("SIP/2.0 407")):
        raise SystemExit(f"FAIL: expected 401 for {host}, got {line}")
print("SUCCESS")
PY

echo "OK — now re-test with password:"
echo "  sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env php /opt/ownswitch/current/bin/test-sip-register.php owner 'Openaccount@12345.'"
