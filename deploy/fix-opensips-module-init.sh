#!/usr/bin/env bash
# Diagnose + recover OpenSIPS "failed to initialize modules".
#   sudo bash /opt/ownswitch-src/deploy/fix-opensips-module-init.sh
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

CFG=/etc/opensips/opensips.cfg
[[ -f "$CFG" ]] || { echo "Missing $CFG"; exit 1; }

echo "======== Last OpenSIPS errors ========"
journalctl -u opensips -n 120 --no-pager 2>/dev/null | grep -E 'ERROR|CRITICAL|fail to initialize|Failed to load' | tail -n 40 || true

echo
echo "======== Foreground init probe (5s) ========"
systemctl stop opensips >/dev/null 2>&1 || true
# Capture which module fails during real init
timeout 8 opensips -f "$CFG" -D -E -n 1 2>&1 | tee /tmp/opensips-init-probe.log | grep -E 'ERROR|CRITICAL|loading module|init' | tail -n 50 || true

echo
echo "======== Ensure registrant version row ========"
mysql --protocol=socket -uroot opensips -e "
CREATE TABLE IF NOT EXISTS registrant (
  id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  registrar CHAR(255) DEFAULT '' NOT NULL,
  proxy CHAR(255) DEFAULT NULL,
  aor CHAR(255) DEFAULT '' NOT NULL,
  third_party_registrant CHAR(255) DEFAULT NULL,
  username CHAR(64) DEFAULT NULL,
  password CHAR(64) DEFAULT NULL,
  binding_URI CHAR(255) DEFAULT '' NOT NULL,
  binding_params CHAR(64) DEFAULT NULL,
  expiry INT(10) UNSIGNED DEFAULT NULL,
  forced_socket CHAR(64) DEFAULT NULL,
  cluster_shtag CHAR(64) DEFAULT NULL,
  state INT DEFAULT 0 NOT NULL
) ENGINE=InnoDB;
INSERT INTO version (table_name, table_version)
  SELECT 'registrant', 3 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM version WHERE table_name='registrant');
UPDATE version SET table_version=3 WHERE table_name='registrant';
SELECT * FROM version WHERE table_name IN ('registrant','subscriber','location','dialog','address');
" 2>/dev/null || true

# Disable optional modules that commonly break init — keep core SIP working
disable_uac=0
if grep -qE 'Failed to load uac_auth|fail to initialize module uac|uac_registrant' /tmp/opensips-init-probe.log 2>/dev/null; then
  disable_uac=1
fi
if grep -qE 'fail to initialize module uac|Failed to load uac_auth|DB URL is not defined|table version' /tmp/opensips-init-probe.log 2>/dev/null; then
  disable_uac=1
fi
# Always prefer SIP up: if still not starting, strip UAC + dialog ping
cp -a "$CFG" "${CFG}.bak.$(date +%s)"

strip_optional() {
  sed -i \
    -e 's/^[[:space:]]*loadmodule "uac_auth.so"/# loadmodule "uac_auth.so"/' \
    -e 's/^[[:space:]]*loadmodule "uac_registrant.so"/# loadmodule "uac_registrant.so"/' \
    -e 's/^[[:space:]]*modparam("uac_registrant"/# modparam("uac_registrant"/' \
    -e 's/^[[:space:]]*modparam("dialog", "options_ping_interval"/# modparam("dialog", "options_ping_interval"/' \
    -e 's/^[[:space:]]*modparam("dialog", "delete_delay"/# modparam("dialog", "delete_delay"/' \
    -e 's/create_dialog("BPp")/create_dialog("B")/g' \
    "$CFG"
}

if [[ "$disable_uac" -eq 1 ]] || ! timeout 6 opensips -f "$CFG" -c >/dev/null 2>&1; then
  echo "Stripping optional UAC / dialog ping for recovery..."
  strip_optional
fi

# If probe log blamed UAC, strip it
if [[ "$disable_uac" -eq 1 ]]; then
  strip_optional
fi

# Last-resort: strip UAC/dialog ping only (do NOT strip exec.so — leaves exec() in routes and breaks opensips -c)
if ! timeout 8 opensips -f "$CFG" -D -E -n 1 >/tmp/opensips-init2.log 2>&1; then
  if grep -qiE 'fail to initialize|ERROR' /tmp/opensips-init2.log; then
    echo "Still failing — stripping UAC / dialog ping..."
    strip_optional
  fi
fi
# Ensure opensips -c still passes after edits
if ! opensips -c -f "$CFG" >/tmp/opensips-c-check.log 2>&1; then
  echo "opensips -c FAILED after strip — see /tmp/opensips-c-check.log"
  grep -E 'ERROR|CRITICAL|parse' /tmp/opensips-c-check.log | tail -n 20 || true
fi

# Fix Type=forking + -F mismatch that leaves unit stuck in "activating"
install -d /etc/systemd/system/opensips.service.d
cat >/etc/systemd/system/opensips.service.d/ownswitch.conf <<'EOF'
[Service]
Type=simple
ExecStart=
ExecStart=/usr/sbin/opensips -F -f /etc/opensips/opensips.cfg
Restart=on-failure
RestartSec=2
TimeoutStartSec=30
EOF
systemctl daemon-reload
systemctl stop opensips >/dev/null 2>&1 || true
pkill -9 -x opensips >/dev/null 2>&1 || true
sleep 1

systemctl reset-failed opensips >/dev/null 2>&1 || true
systemctl start opensips
for i in $(seq 1 20); do
  systemctl is-active --quiet opensips && break
  state="$(systemctl is-active opensips 2>/dev/null || true)"
  if [[ "$state" == "activating" && "$i" -ge 12 ]]; then
    echo "Still activating — killing orphans and retrying once..."
    systemctl kill -s SIGKILL opensips >/dev/null 2>&1 || true
    pkill -9 -x opensips >/dev/null 2>&1 || true
    sleep 1
    systemctl reset-failed opensips >/dev/null 2>&1 || true
    systemctl start opensips || true
  fi
  sleep 1
done

if systemctl is-active --quiet opensips; then
  echo "OK: opensips is active"
  systemctl --no-pager --full status opensips | head -n 20 || true
else
  echo "FAILED — full log:"
  systemctl --no-pager --full status opensips | head -n 30 || true
  journalctl -u opensips -n 80 --no-pager || true
  cat /tmp/opensips-init-probe.log 2>/dev/null | tail -n 40 || true
  exit 1
fi
