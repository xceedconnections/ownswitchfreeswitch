#!/usr/bin/env bash
# Force Ownswitch OpenSIPS cfg (digest REGISTER). Run as root:
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main && bash deploy/PASTE-AS-ROOT-fix-REGISTER.sh
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "You must be root (not ownswitch)"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
CFG=/etc/opensips/opensips.cfg
OWN_ETC=/etc/ownswitch
IC="${OWN_ETC}/install.conf"
ENV_FILE="${OWN_ETC}/ownswitch.env"
BKDIR=/var/backups/ownswitch-config
install -d -m 0755 "$BKDIR"

restore_latest_backup() {
  local b
  b="$(ls -1t "$BKDIR"/opensips.cfg.* 2>/dev/null | head -1 || true)"
  if [[ -n "$b" && -f "$b" ]]; then
    echo "Restoring backup: $b"
    cp -a "$b" "$CFG"
    return 0
  fi
  return 1
}

# If previous paste left a broken cfg, restore before continuing
if [[ -f "$CFG" ]] && ! opensips -c -f "$CFG" >/tmp/os_c_check.txt 2>&1; then
  echo "Live cfg is broken (opensips -c failed). Restoring last backup..."
  cat /tmp/os_c_check.txt | tail -n 20 || true
  restore_latest_backup || true
fi

cd "$SRC"
git fetch origin main
git reset --hard origin/main

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "$IC" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
PUBLIC_IP="$(curl -4 -s --max-time 5 ifconfig.me || true)"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
LOCAL="$(hostname -I 2>/dev/null || true)"
if [[ -n "$DNS_IP" && " $LOCAL " == *" $DNS_IP "* ]]; then PUBLIC_IP="$DNS_IP"; fi
[[ -n "$PUBLIC_IP" ]] || PUBLIC_IP="$DNS_IP"
[[ -n "$PUBLIC_IP" ]] || PUBLIC_IP="$(echo "$LOCAL" | awk '{print $1}')"
PRIV="$(echo "$LOCAL" | awk '{print $1}')"
echo "DOMAIN=$DOMAIN PUBLIC_IP=$PUBLIC_IP"

grep -vE '^(PRIVATE_IP|PUBLIC_IP|OWNSWITCH_DOMAIN)=' "$IC" > /tmp/ic.$$ 2>/dev/null || true
{ echo "OWNSWITCH_DOMAIN=${DOMAIN}"; echo "PUBLIC_IP=${PUBLIC_IP}"; echo "PRIVATE_IP=${PRIV}"; cat /tmp/ic.$$; } > "$IC"
rm -f /tmp/ic.$$
touch "$ENV_FILE"
grep -q '^OPENSIPS_SIP_DOMAIN=' "$ENV_FILE" && sed -i "s/^OPENSIPS_SIP_DOMAIN=.*/OPENSIPS_SIP_DOMAIN=${DOMAIN}/" "$ENV_FILE" || echo "OPENSIPS_SIP_DOMAIN=${DOMAIN}" >>"$ENV_FILE"
grep -q '^SIP_PUBLIC_IP=' "$ENV_FILE" && sed -i "s/^SIP_PUBLIC_IP=.*/SIP_PUBLIC_IP=${PUBLIC_IP}/" "$ENV_FILE" || echo "SIP_PUBLIC_IP=${PUBLIC_IP}" >>"$ENV_FILE"
grep -q '^OPENSIPS_ENABLED=' "$ENV_FILE" && sed -i 's/^OPENSIPS_ENABLED=.*/OPENSIPS_ENABLED=1/' "$ENV_FILE" || echo 'OPENSIPS_ENABLED=1' >>"$ENV_FILE"

export OWNSWITCH_DOMAIN="$DOMAIN" PUBLIC_IP="$PUBLIC_IP" PRIVATE_IP="$PRIV" SIP_PORT="${SIP_PORT:-5060}"

echo "== Install OpenSIPS modules (MUST include opensips-auth-modules for auth.so) =="
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq || true
# auth.so lives in opensips-auth-modules (NOT in the base opensips package)
apt-get install -y opensips opensips-auth-modules opensips-mysql-module \
  opensips-http-modules opensips-nathelper-module opensips-cgrates-module \
  opensips-cli || true
# Retry auth package alone if the big install partially failed
apt-get install -y opensips-auth-modules || {
  echo "FATAL: cannot install opensips-auth-modules — check apt.opensips.org is configured"
  apt-cache search opensips-auth || true
  exit 1
}

MOD="$(find /usr/lib -type f -name tm.so -path '*opensips/modules*' 2>/dev/null | head -n1 || true)"
[[ -n "$MOD" ]] || { echo "FATAL: OpenSIPS modules dir not found"; find /usr/lib -name 'opensips' -type d 2>/dev/null | head; exit 1; }
MPATH="$(dirname "$MOD")/"
echo "MPATH=$MPATH"
missing=0
for so in auth.so auth_db.so usrloc.so registrar.so sipmsgops.so; do
  if [[ -f "${MPATH}${so}" ]]; then
    echo "  OK ${so}"
  else
    echo "  MISSING ${so}"
    missing=1
  fi
done
if [[ "$missing" -ne 0 ]]; then
  echo "FATAL: required .so missing under $MPATH"
  dpkg -L opensips-auth-modules 2>/dev/null | head -n 40 || true
  exit 1
fi

echo "== Ensure opensips DB + subscriber.password =="
bash "$SRC/deploy/fix-opensips-provision.sh" || true
mysql --protocol=socket -uroot opensips -e \
  "ALTER TABLE subscriber ADD COLUMN password VARCHAR(64) NOT NULL DEFAULT ''" 2>/dev/null || true

echo "== Apply Ownswitch OpenSIPS config (stage 50) =="
set +e
bash "$SRC/deploy/install.sh" --only 50 --yes --non-interactive
rc50=$?
set -e
if [[ "$rc50" -ne 0 ]]; then
  echo "FATAL: stage 50 failed — Ownswitch cfg not applied"
  tail -n 100 /var/log/ownswitch-install.log || true
  exit 1
fi

echo "== Verify live cfg =="
grep -n 'mpath=' "$CFG" | head -3
grep -nE 'loadmodule "auth|www_authorize|calculate_ha1|is_method\("REGISTER"\)|rewritehost' "$CFG" | head -n 30
grep -q 'www_authorize' "$CFG" || { echo "FATAL: no www_authorize in live cfg"; exit 1; }
grep -q 'mpath=' "$CFG" || { echo "FATAL: no mpath in live cfg"; exit 1; }

# Force systemd to use this file
install -d /etc/systemd/system/opensips.service.d
cat >/etc/systemd/system/opensips.service.d/ownswitch.conf <<EOF
[Service]
Type=simple
ExecStart=
ExecStart=/usr/sbin/opensips -F -f ${CFG}
Restart=on-failure
RestartSec=2
TimeoutStartSec=30
EOF
systemctl daemon-reload

opensips -c -f "$CFG"

systemctl stop opensips || true
fuser -k 5060/udp 2>/dev/null || true
fuser -k 5060/tcp 2>/dev/null || true
sleep 1
systemctl start opensips
sleep 2
systemctl --no-pager -l status opensips | head -n 25 || true

# Sync portal passwords into subscriber.password
bash "$SRC/deploy/refresh-ui.sh" || true
CUR="$(readlink -f /opt/ownswitch/current)"
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true
mysql --protocol=socket -uroot opensips -e \
  "UPDATE subscriber SET password='Openaccount@12345.' WHERE username='owner' AND (password IS NULL OR password=''); SELECT username, IF(password='','EMPTY','set') pwd FROM subscriber WHERE username='owner';" \
  || true

python3 - "$DOMAIN" "$PUBLIC_IP" <<'PY'
import socket, sys
domain, ip = sys.argv[1], sys.argv[2]

def probe(host):
    msg = (
      f"REGISTER sip:{host} SIP/2.0\r\n"
      f"Via: SIP/2.0/UDP 127.0.0.1:5099;branch=z9hG4bKx;rport\r\n"
      f"Max-Forwards: 70\r\n"
      f"From: <sip:x@{host}>;tag=t\r\n"
      f"To: <sip:x@{host}>\r\n"
      f"Call-ID: x-{host}@127.0.0.1\r\n"
      f"CSeq: 1 REGISTER\r\n"
      f"Contact: <sip:x@127.0.0.1:5099>\r\n"
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

d, i = probe(domain), probe(ip)
print(f"Domain {domain} → {d}")
print(f"IP     {ip} → {i}")
ok_d = d.startswith("SIP/2.0 401") or d.startswith("SIP/2.0 407")
ok_i = i.startswith("SIP/2.0 401") or i.startswith("SIP/2.0 407")
if not (ok_d and ok_i):
    raise SystemExit("FAIL: still not 401 — journalctl -u opensips -n 80 --no-pager")
print("SUCCESS: digest REGISTER is active.")
PY

echo "Softphone: Domain=$PUBLIC_IP Proxy=BLANK User=owner Pass=(portal password) Port=5060 UDP"
echo "Test: sudo -u ownswitch env OWNSWITCH_ENV_FILE=$ENV_FILE php $CUR/bin/test-sip-register.php owner 'Openaccount@12345.'"
