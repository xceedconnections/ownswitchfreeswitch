#!/usr/bin/env bash
# Nuclear fix: Ownswitch OpenSIPS MUST digest-challenge REGISTER (401).
# Fixes: IP → open 200 without auth, Domain → 483 Too Many Hops.
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/emergency-fix-register-auth.sh | sudo bash
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_ETC=/etc/ownswitch
ENV_FILE="${OWN_ETC}/ownswitch.env"
IC="${OWN_ETC}/install.conf"
CFG=/etc/opensips/opensips.cfg
FIFO=/run/opensips/opensips_fifo

echo "======== BEFORE (this is why softphone fails) ========"
echo "--- listeners 5060 ---"
ss -ulnp 2>/dev/null | grep -E ':5060\b' || true
ss -tlnp 2>/dev/null | grep -E ':5060\b' || true
echo "--- systemd opensips ---"
systemctl cat opensips 2>/dev/null | head -n 50 || true
echo "--- live cfg REGISTER / auth (first 30 hits) ---"
if [[ -f "$CFG" ]]; then
  grep -nE 'is_method\("REGISTER"\)|www_authorize|www_challenge|save\("location"\)|calculate_ha1|alias=|mf_process_maxfwd|rewritehost' "$CFG" | head -n 40 || true
else
  echo "MISSING $CFG"
fi

cd "$SRC"
git remote -v || true
git fetch origin main
git reset --hard origin/main

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "$IC" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
DETECT_IP="$(curl -4 -s --max-time 5 ifconfig.me 2>/dev/null || true)"
LOCAL_IPS="$(hostname -I 2>/dev/null || true)"
PUBLIC_IP="$DETECT_IP"
if [[ -n "$DNS_IP" && " $LOCAL_IPS " == *" $DNS_IP "* ]]; then
  PUBLIC_IP="$DNS_IP"
elif [[ -z "${PUBLIC_IP:-}" && -n "$DNS_IP" ]]; then
  PUBLIC_IP="$DNS_IP"
fi
[[ -n "${PUBLIC_IP:-}" ]] || PUBLIC_IP="$(echo "$LOCAL_IPS" | awk '{print $1}')"
PRIV="$(echo "$LOCAL_IPS" | awk '{print $1}')"
echo "DOMAIN=$DOMAIN PUBLIC_IP=$PUBLIC_IP DNS=$DNS_IP LOCAL=$LOCAL_IPS"

install -d -m 0755 "$OWN_ETC" /var/backups/ownswitch-config
grep -vE '^(PRIVATE_IP|PUBLIC_IP|OWNSWITCH_DOMAIN)=' "$IC" > /tmp/ic.$$ 2>/dev/null || true
{ echo "OWNSWITCH_DOMAIN=${DOMAIN}"; echo "PUBLIC_IP=${PUBLIC_IP}"; echo "PRIVATE_IP=${PRIV}"; cat /tmp/ic.$$; } > "$IC"
rm -f /tmp/ic.$$
touch "$ENV_FILE"
grep -q '^OPENSIPS_SIP_DOMAIN=' "$ENV_FILE" && sed -i "s/^OPENSIPS_SIP_DOMAIN=.*/OPENSIPS_SIP_DOMAIN=${DOMAIN}/" "$ENV_FILE" || echo "OPENSIPS_SIP_DOMAIN=${DOMAIN}" >>"$ENV_FILE"
grep -q '^SIP_PUBLIC_IP=' "$ENV_FILE" && sed -i "s/^SIP_PUBLIC_IP=.*/SIP_PUBLIC_IP=${PUBLIC_IP}/" "$ENV_FILE" || echo "SIP_PUBLIC_IP=${PUBLIC_IP}" >>"$ENV_FILE"
grep -q '^OPENSIPS_ENABLED=' "$ENV_FILE" && sed -i 's/^OPENSIPS_ENABLED=.*/OPENSIPS_ENABLED=1/' "$ENV_FILE" || echo 'OPENSIPS_ENABLED=1' >>"$ENV_FILE"

export OWNSWITCH_DOMAIN="$DOMAIN" PUBLIC_IP="$PUBLIC_IP" PRIVATE_IP="$PRIV" SIP_PORT="${SIP_PORT:-5060}"

# Template must exist with digest REGISTER
TPL="$SRC/deploy/templates/opensips.cfg.tpl"
[[ -f "$TPL" ]] || { echo "FATAL: missing $TPL"; exit 1; }
grep -q 'www_authorize' "$TPL" || { echo "FATAL: template has no www_authorize"; exit 1; }
grep -q 'rewritehost' "$TPL" || { echo "FATAL: template has no rewritehost"; exit 1; }

echo "======== Apply DB + stage 50 (OpenSIPS cfg) ========"
bash "$SRC/deploy/fix-opensips-provision.sh" || true
set +e
bash "$SRC/deploy/install.sh" --only 50 --yes --non-interactive
rc50=$?
set -e
if [[ "$rc50" -ne 0 ]]; then
  echo "FATAL: stage 50 failed (exit $rc50). Live cfg was NOT replaced — that is why you still see open 200 / 483."
  echo "Last install log lines:"
  tail -n 80 /var/log/ownswitch-install.log 2>/dev/null || true
  exit 1
fi

echo "======== Verify LIVE $CFG ========"
grep -q 'www_authorize' "$CFG" || { echo "FATAL: live cfg still has no www_authorize"; exit 1; }
grep -qE 'calculate_ha1",[ ]*1' "$CFG" || { echo "FATAL: calculate_ha1!=1"; grep -n calculate_ha1 "$CFG" || true; exit 1; }
grep -q "alias=\"${DOMAIN}\"" "$CFG" || echo "WARN: missing alias $DOMAIN"
grep -q "alias=\"${PUBLIC_IP}\"" "$CFG" || echo "WARN: missing alias $PUBLIC_IP"
reg_line="$(grep -n 'is_method("REGISTER")' "$CFG" | head -1 | cut -d: -f1 || true)"
mf_line="$(grep -n 'mf_process_maxfwd_header' "$CFG" | head -1 | cut -d: -f1 || true)"
echo "REGISTER line=$reg_line Max-Forwards line=$mf_line"
if [[ -n "$reg_line" && -n "$mf_line" && "$reg_line" -gt "$mf_line" ]]; then
  echo "FATAL: REGISTER still after Max-Forwards"
  exit 1
fi
sed -n "${reg_line},$((reg_line+30))p" "$CFG"

# Ensure systemd uses this cfg
if systemctl cat opensips 2>/dev/null | grep -qE 'ExecStart=.*opensips'; then
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
fi

echo "======== Hard restart OpenSIPS ========"
systemctl stop opensips || true
sleep 1
# Kill anything still bound to 5060 (stale opensips / wrong binary)
fuser -k 5060/udp 2>/dev/null || true
fuser -k 5060/tcp 2>/dev/null || true
sleep 1
opensips -c -f "$CFG"
systemctl start opensips
sleep 2
systemctl --no-pager -l status opensips | head -n 25 || true
ss -ulnp 2>/dev/null | grep -E ':5060\b' || true

# Sync passwords into subscriber.password
CUR="$(readlink -f /opt/ownswitch/current 2>/dev/null || true)"
bash "$SRC/deploy/refresh-ui.sh" || true
CUR="$(readlink -f /opt/ownswitch/current)"
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true
mysql --protocol=socket -uroot opensips -e \
  "SELECT username, IF(password IS NULL OR password='', 'EMPTY','set') pwd FROM subscriber;" 2>/dev/null || true

probe() {
  local host="$1"
  PROBE_HOST="$host" python3 - <<'PY'
import os, socket
host = os.environ["PROBE_HOST"]
msg = (
  f"REGISTER sip:{host} SIP/2.0\r\n"
  f"Via: SIP/2.0/UDP 127.0.0.1:5099;branch=z9hG4bKemrg;rport\r\n"
  f"Max-Forwards: 70\r\n"
  f"From: <sip:probe@{host}>;tag=emrg\r\n"
  f"To: <sip:probe@{host}>\r\n"
  f"Call-ID: emrg-{host}@127.0.0.1\r\n"
  f"CSeq: 1 REGISTER\r\n"
  f"Contact: <sip:probe@127.0.0.1:5099>\r\n"
  f"Expires: 60\r\n"
  f"Content-Length: 0\r\n\r\n"
)
s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
s.settimeout(3)
s.sendto(msg.encode(), ("127.0.0.1", 5060))
try:
    data, _ = s.recvfrom(8192)
    print(data.decode(errors="replace").splitlines()[0])
except Exception as e:
    print(f"NO_RESPONSE {e}")
s.close()
PY
}

echo
echo "======== AFTER probes (must be 401/407, never 200/483) ========"
D_CODE="$(probe "$DOMAIN")"
I_CODE="$(probe "$PUBLIC_IP")"
echo "Domain $DOMAIN → $D_CODE"
echo "IP     $PUBLIC_IP → $I_CODE"

fail=0
[[ "$D_CODE" == SIP/2.0\ 401* || "$D_CODE" == SIP/2.0\ 407* ]] || fail=1
[[ "$I_CODE" == SIP/2.0\ 401* || "$I_CODE" == SIP/2.0\ 407* ]] || fail=1
if [[ "$fail" -eq 1 ]]; then
  echo "FATAL: still broken after apply."
  echo "--- journal ---"
  journalctl -u opensips --no-pager -n 100 || true
  echo "--- cfg auth lines ---"
  grep -nE 'REGISTER|www_authorize|rewritehost|calculate_ha1|alias=' "$CFG" | head -n 50
  exit 1
fi

echo
echo "OK: digest challenge works for Domain + IP."
echo "Next:"
echo "  1) Portal → SIP Users → owner → set password Openaccount@12345. → Save"
echo "  2) Softphone: User=owner Pass=... Domain=${PUBLIC_IP} Proxy=BLANK Port=5060 UDP"
echo "  3) sudo -u ownswitch env OWNSWITCH_ENV_FILE=$ENV_FILE php $CUR/bin/test-sip-register.php owner 'Openaccount@12345.'"
