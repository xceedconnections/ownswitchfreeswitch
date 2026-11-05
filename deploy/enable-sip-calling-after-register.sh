#!/usr/bin/env bash
# REGISTER works on minimal cfg; enable OUTBOUND calling (drouting) + keep digest REGISTER.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/enable-sip-calling-after-register.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
OWN_ETC=/etc/ownswitch
ENV_FILE="${OWN_ETC}/ownswitch.env"
IC="${OWN_ETC}/install.conf"
cd "$SRC"
git fetch origin main
git reset --hard origin/main

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "$IC" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
PUBLIC_IP="$(curl -4 -s --max-time 5 ifconfig.me || true)"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
LOCAL="$(hostname -I || true)"
if [[ -n "$DNS_IP" && " $LOCAL " == *" $DNS_IP "* ]]; then PUBLIC_IP="$DNS_IP"; fi
[[ -n "$PUBLIC_IP" ]] || PUBLIC_IP="${DNS_IP:-$(echo "$LOCAL" | awk '{print $1}')}"
PRIV="$(echo "$LOCAL" | awk '{print $1}')"
export OWNSWITCH_DOMAIN="$DOMAIN" PUBLIC_IP="$PUBLIC_IP" PRIVATE_IP="$PRIV" SIP_PORT="${SIP_PORT:-5060}"

echo "DOMAIN=$DOMAIN PUBLIC_IP=$PUBLIC_IP"
chattr -i "$CFG" 2>/dev/null || true

echo "======== Install MySQL dbschema package ========"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq || true
apt-get install -y opensips-mysql-dbschema opensips-mysql-module opensips-cgrates-module \
  opensips-auth-modules opensips-nathelper-module 2>/dev/null || true

echo "======== Ensure drouting / address / dialog tables ========"
for pair in "drouting:dr_gateways" "permissions:address" "dialog:dialog" "standard:acc"; do
  mod="${pair%%:*}"; tbl="${pair##*:}"
  if mysql --protocol=socket -uroot -N -e \
    "SELECT 1 FROM information_schema.tables WHERE table_schema='opensips' AND table_name='${tbl}'" | grep -q 1; then
    echo "OK $tbl"
    continue
  fi
  f=$(find /usr/share/opensips /usr/share/opensips-mysql-module /usr/share/doc \
    \( -name "${mod}-create.sql" -o -name "*${mod}*create*.sql" \) 2>/dev/null | head -1 || true)
  if [[ -n "${f:-}" ]]; then
    echo "Applying $f"
    mysql --protocol=socket -uroot opensips <"$f" || true
  else
    echo "WARN: no SQL for $mod ($tbl)"
  fi
done
mysql --protocol=socket -uroot opensips <<'SQL' || true
CREATE TABLE IF NOT EXISTS address (
  id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  grp SMALLINT(5) UNSIGNED DEFAULT 1 NOT NULL,
  ip CHAR(50) NOT NULL,
  mask TINYINT DEFAULT 32 NOT NULL,
  port SMALLINT(5) UNSIGNED DEFAULT 0 NOT NULL,
  proto CHAR(4) DEFAULT 'any' NOT NULL,
  pattern CHAR(64) DEFAULT NULL,
  context_info CHAR(32) DEFAULT NULL
) ENGINE=InnoDB;
DELETE FROM version WHERE table_name='address';
INSERT INTO version (table_name, table_version) VALUES ('address', 5);
SQL

echo "======== Stage 50 (full Ownswitch cfg) ========"
bash "$SRC/deploy/fix-opensips-provision.sh" || true
set +e
bash "$SRC/deploy/install.sh" --only 50 --yes --non-interactive
rc=$?
set -e

if [[ "$rc" -ne 0 ]] || ! grep -q 'do_routing' "$CFG" 2>/dev/null; then
  echo "Stage 50 incomplete — extending live cfg with outbound drouting (fallback)"
  bash "$SRC/deploy/patch-minimal-cfg-add-drouting.sh" || {
    echo "FATAL: could not enable outbound routing"
    tail -n 60 /var/log/ownswitch-install.log || true
    exit 1
  }
fi

install -d /etc/systemd/system/opensips.service.d
cat >/etc/systemd/system/opensips.service.d/ownswitch.conf <<EOF
[Service]
Type=simple
ExecStart=
ExecStart=/usr/sbin/opensips -F -f ${CFG}
Restart=on-failure
RestartSec=2
EOF
systemctl daemon-reload

grep -q 'www_authorize' "$CFG" || { echo "FATAL: digest REGISTER missing"; exit 1; }
grep -qE 'do_routing|dr_gateways' "$CFG" || { echo "FATAL: still no drouting in cfg"; exit 1; }

opensips -c -f "$CFG"
systemctl stop opensips || true
fuser -k 5060/udp 2>/dev/null || true
fuser -k 5060/tcp 2>/dev/null || true
sleep 1
systemctl start opensips
sleep 2
systemctl is-active opensips || { journalctl -u opensips -n 50 --no-pager; exit 1; }

echo "======== Sync portal SIP users + trunks ========"
bash "$SRC/deploy/refresh-ui.sh" || true
CUR="$(readlink -f /opt/ownswitch/current)"
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-trunks.php" || true

mysql --protocol=socket -uroot opensips -e \
  "SELECT COUNT(*) gateways FROM dr_gateways; SELECT COUNT(*) rules FROM dr_rules;" 2>/dev/null || true

echo
echo "################################################################"
echo " Calling stack ready. Re-register Eyebeam, then dial a PSTN number."
echo " If gateways=0: Portal → Trunks + Routes for this customer, then:"
echo "   sudo -u ownswitch env OWNSWITCH_ENV_FILE=$ENV_FILE php $CUR/bin/sync-opensips-trunks.php"
echo "################################################################"
