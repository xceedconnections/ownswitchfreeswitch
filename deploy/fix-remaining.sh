#!/usr/bin/env bash
# Fix the three remaining Ownswitch failures: OpenSIPS schema, CGRateS, real portal deploy.
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-remaining.sh | sudo bash
set -Eeuo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
LOG=/var/log/ownswitch-fix-remaining.log
exec > >(tee -a "$LOG") 2>&1

echo "==== $(date -u -Iseconds) fix-remaining ===="

apt-get install -y -qq git curl ca-certificates rsync >/dev/null || true
git -C "$SRC" fetch --depth 1 origin main
git -C "$SRC" reset --hard origin/main
find "$SRC/deploy" -name '*.sh' -exec chmod +x {} +

# Force APP_SRC to the real checkout
mkdir -p /etc/ownswitch
grep -v '^APP_SRC=' /etc/ownswitch/install.conf > /tmp/ic.conf 2>/dev/null || true
mv /tmp/ic.conf /etc/ownswitch/install.conf 2>/dev/null || true
echo "APP_SRC=${SRC}" >> /etc/ownswitch/install.conf
export APP_SRC="$SRC"
export MYSQL_INSTALL_METHOD=direct
export NON_INTERACTIVE=1
export FREESWITCH_ENABLED=0

[[ -f "$SRC/public/index.php" ]] || { echo "FATAL: $SRC/public/index.php missing"; exit 1; }
echo "APP_SRC OK: $SRC/public/index.php"

cd "$SRC"
for n in 50 60 90 95 99; do
  echo "==== stage ${n} ===="
  bash deploy/install.sh --only "$n" --yes --non-interactive || {
    echo "stage ${n} failed"
    [[ "$n" == "60" ]] && journalctl -u cgrates -n 50 --no-pager || true
    [[ "$n" == "50" ]] && journalctl -u opensips -n 30 --no-pager || true
  }
done

echo "==== status ===="
for u in mysql redis-server opensips cgrates php8.3-fpm nginx; do
  printf '%-16s %s\n' "$u" "$(systemctl is-active "$u" 2>/dev/null || echo missing)"
done
echo "subscriber table:" 
mysql --protocol=socket -uroot -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='opensips' AND table_name='subscriber'" 2>/dev/null || echo err
echo "placeholder marker:" 
ls /opt/ownswitch/current/.ownswitch-placeholder 2>/dev/null || echo "(none — good)"
echo "portal:"
curl -ksI https://127.0.0.1/ -H "Host: voip.xceedconnections.com" | head -n 8
echo
grep -E 'SUMMARY:|\[FAIL\]|\[WARN\]' /var/lib/ownswitch-install/reports/latest.txt 2>/dev/null || true
[[ -f /root/ownswitch-credentials.txt ]] && echo "Admin: /root/ownswitch-credentials.txt"
echo "Log: $LOG"
