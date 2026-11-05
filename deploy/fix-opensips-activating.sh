#!/usr/bin/env bash
# OpenSIPS exit 255 leaves systemd in "activating (auto-restart)".
# opensips -c can still pass. -E is fatal on 3.4+ and hides the real error.
# This script boots as the opensips user with stderr on, prints the error,
# applies safe fixes, and only succeeds if the process stays running.
#
#   sudo bash /opt/ownswitch-src/deploy/fix-opensips-activating.sh
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

CFG=/etc/opensips/opensips.cfg
[[ -f "$CFG" ]] || { echo "Missing $CFG"; exit 1; }
id opensips >/dev/null 2>&1 || { echo "opensips user missing"; exit 1; }

echo "======== Before ========"
systemctl is-active opensips 2>&1 || true
systemctl show opensips -p ActiveState -p SubState -p ExecMainStatus -p ExecMainCode --no-pager 2>&1 || true

# Readable by the service user; fifo writable by php-fpm (www-data).
chmod 0755 /etc/opensips 2>/dev/null || true
chown root:opensips "$CFG" /etc/opensips 2>/dev/null || true
chmod 0640 "$CFG"
install -d -m 0750 -o opensips -g opensips /run/opensips
if id www-data >/dev/null 2>&1; then
  usermod -aG opensips www-data 2>/dev/null || true
fi
sed -i \
  -e 's/modparam("mi_fifo", "fifo_mode", 0660)/modparam("mi_fifo", "fifo_mode", 0666)/' \
  -e 's/^stderror_enabled=no/stderror_enabled=yes/' \
  "$CFG"

# sqlops was injected with a nested @@OPENSIPS_DB_URL@@ that the renderer does not expand.
# That string is not a DB driver, so sqlops init exits 255.
if grep -q '@@OPENSIPS_DB_URL@@' "$CFG"; then
  dburl="$(sed -n 's/.*modparam("auth_db", "db_url", "\([^"]*\)").*/\1/p' "$CFG" | head -n 1)"
  if [[ -z "$dburl" || "$dburl" == *'@@'* ]]; then
    echo "Cannot fix @@OPENSIPS_DB_URL@@ — auth_db db_url is missing" >&2
    exit 1
  fi
  python3 - "$CFG" "$dburl" <<'PY'
import pathlib, sys
path, url = sys.argv[1], sys.argv[2]
file = pathlib.Path(path)
file.write_text(file.read_text().replace("@@OPENSIPS_DB_URL@@", url))
print("Replaced leftover @@OPENSIPS_DB_URL@@ in", path)
PY
fi
if grep -qE '@@[A-Za-z0-9_]+@@' "$CFG"; then
  echo "Unresolved placeholders still in ${CFG}:" >&2
  grep -nE '@@[A-Za-z0-9_]+@@' "$CFG" >&2 || true
fi

# SIP OPTIONS ping flags on REGISTER made phones get no reply (request timeout).
# Strip them so save("location") runs again and OpenSIPS answers REGISTER.
python3 - "$CFG" <<'PY'
import pathlib, re, sys
path = pathlib.Path(sys.argv[1])
text = path.read_text()
orig = text
text = re.sub(r'(?m)^modparam\("nathelper", "natping_interval".*\n', '', text)
text = re.sub(r'(?m)^modparam\("nathelper", "ping_nated_only".*\n', '', text)
text = re.sub(r'(?m)^modparam\("nathelper", "sipping_bflag".*\n', '', text)
text = re.sub(r'(?m)^modparam\("nathelper", "sipping_latency_flag".*\n', '', text)
text = re.sub(r'(?m)^modparam\("nathelper", "sipping_from".*\n', '', text)
text = re.sub(r'(?m)^modparam\("nathelper", "sipping_method".*\n', '', text)
text = re.sub(r'(?m)^[ \t]*setbflag\("SIP_PING"\);\n', '', text)
text = re.sub(r'(?m)^[ \t]*setbflag\("SIP_PING_MS"\);\n', '', text)
if text != orig:
    path.write_text(text)
    print("Removed SIP ping flags from REGISTER so phones get a reply")
else:
    print("REGISTER path already has no SIP ping flags")
PY

# AppArmor on Ubuntu 24.04 often kills a custom unit before any log line (exit 255).
if [[ -f /etc/apparmor.d/usr.sbin.opensips ]] && command -v apparmor_parser >/dev/null 2>&1; then
  install -d /etc/apparmor.d/disable
  ln -sfn /etc/apparmor.d/usr.sbin.opensips /etc/apparmor.d/disable/usr.sbin.opensips
  apparmor_parser -R /etc/apparmor.d/usr.sbin.opensips 2>/dev/null || true
  echo "AppArmor profile usr.sbin.opensips unloaded"
fi

rm -f /etc/systemd/system/opensips.service.d/*.conf
cat >/etc/systemd/system/opensips.service <<'EOF'
[Unit]
Description=OpenSIPS SIP server (Ownswitch)
After=network-online.target mysql.service
Wants=network-online.target
StartLimitIntervalSec=60
StartLimitBurst=5

[Service]
Type=simple
User=opensips
Group=opensips
RuntimeDirectory=opensips
RuntimeDirectoryMode=0750
WorkingDirectory=/run/opensips
ExecStart=/usr/sbin/opensips -F -f /etc/opensips/opensips.cfg
ExecReload=/bin/kill -HUP $MAINPID
Restart=on-failure
RestartSec=2
TimeoutStartSec=20
TimeoutStopSec=10
LimitNOFILE=65536
KillMode=mixed
StandardOutput=journal
StandardError=journal
SyslogIdentifier=opensips

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload

stop_all() {
  systemctl stop opensips >/dev/null 2>&1 || true
  systemctl kill -s SIGKILL opensips >/dev/null 2>&1 || true
  pkill -9 -x opensips >/dev/null 2>&1 || true
  sleep 1
}

dump_reason() {
  echo "======== boot log (user opensips, no -E) ========"
  if [[ -s /tmp/opensips-boot.log ]]; then
    tail -n 80 /tmp/opensips-boot.log
  else
    echo "(empty)"
  fi
  echo "======== journal (unit) ========"
  journalctl -u opensips -n 40 --no-pager 2>/dev/null || true
  echo "======== journal (tag opensips) ========"
  journalctl -t opensips -n 40 --no-pager 2>/dev/null || true
}

# Boot once as the service user so stderr is the real failure, not a root -c pass.
probe_boot() {
  stop_all
  : >/tmp/opensips-boot.log
  # timeout exits 124 when the process stays up — that is success.
  set +e
  timeout 5 runuser -u opensips -- opensips -f "$CFG" -F >/tmp/opensips-boot.log 2>&1
  local rc=$?
  set -e
  if [[ "$rc" -eq 124 ]]; then
    echo "probe: process stayed up"
    pkill -9 -x opensips >/dev/null 2>&1 || true
    sleep 1
    return 0
  fi
  echo "probe: exited rc=${rc}"
  return 1
}

apply_boot_fixes() {
  local log=$1
  local changed=0
  [[ -s "$log" ]] || return 1
  local line table ver mod
  while IFS= read -r line; do
    if [[ "$line" =~ db\ table\ ([A-Za-z0-9_]+),?\ required\ version\ ([0-9]+) ]]; then
      table="${BASH_REMATCH[1]}"
      ver="${BASH_REMATCH[2]}"
      echo "Updating opensips.version ${table}=${ver}"
      mysql --protocol=socket -uroot opensips -e \
        "INSERT INTO version (table_name, table_version) SELECT '${table}', ${ver} FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM version WHERE table_name='${table}'); UPDATE version SET table_version=${ver} WHERE table_name='${table}';" \
        2>/dev/null || true
      changed=1
    fi
    if [[ "$line" =~ fail\ to\ initialize\ module\ ([A-Za-z0-9_]+) ]]; then
      mod="${BASH_REMATCH[1]}"
      case "$mod" in
        uac|uac_auth|uac_registrant|exec|cachedb_redis|httpd|mi_http|json|rest_client)
          echo "Commenting optional module ${mod}"
          sed -i \
            -e "s/^[[:space:]]*loadmodule \"${mod}.so\"/# loadmodule \"${mod}.so\"/" \
            -e "s/^[[:space:]]*modparam(\"${mod}\"/# modparam(\"${mod}\"/" \
            "$CFG"
          changed=1
          ;;
        *)
          echo "Required module failed init: ${mod}"
          ;;
      esac
    fi
  done <"$log"
  if grep -qE 'Address already in use|bind.*5060' "$log"; then
    echo "Freeing SIP port 5060"
    fuser -k 5060/udp >/dev/null 2>&1 || true
    fuser -k 5060/tcp >/dev/null 2>&1 || true
    changed=1
  fi
  if grep -qE 'Failed to load uac_|uac_registrant|DB URL is not defined' "$log"; then
    sed -i \
      -e 's/^[[:space:]]*loadmodule "uac_auth.so"/# loadmodule "uac_auth.so"/' \
      -e 's/^[[:space:]]*loadmodule "uac_registrant.so"/# loadmodule "uac_registrant.so"/' \
      -e 's/^[[:space:]]*modparam("uac_registrant"/# modparam("uac_registrant"/' \
      "$CFG"
    changed=1
  fi
  return $(( changed == 0 ))
}

echo "======== Foreground boot probe ========"
fixed=0
if ! probe_boot; then
  dump_reason
  if apply_boot_fixes /tmp/opensips-boot.log; then
    fixed=1
    echo "Re-probe after fixes..."
    probe_boot || true
    dump_reason
  fi
fi

if ! opensips -c -f "$CFG" >/tmp/opensips-c-check.log 2>&1; then
  echo "opensips -c FAILED"
  tail -n 40 /tmp/opensips-c-check.log || true
  exit 1
fi

stop_all
systemctl reset-failed opensips >/dev/null 2>&1 || true
systemctl start opensips || true

ok=0
stable=0
for i in $(seq 1 12); do
  active="$(systemctl show -p ActiveState --value opensips 2>/dev/null || true)"
  sub="$(systemctl show -p SubState --value opensips 2>/dev/null || true)"
  code="$(systemctl show -p ExecMainStatus --value opensips 2>/dev/null || true)"
  echo "  wait ${i}s active=${active} sub=${sub} exit=${code}"
  if [[ "$active" == "active" && "$sub" == "running" ]]; then
    stable=$((stable + 1))
    if (( stable >= 3 )); then
      ok=1
      break
    fi
  else
    stable=0
  fi
  if [[ "$active" == "failed" || "$sub" == "auto-restart" ]]; then
    # One controlled retry after we have the journal text.
    if (( fixed == 0 )); then
      journalctl -u opensips -n 80 --no-pager >/tmp/opensips-boot.log 2>/dev/null || true
      journalctl -t opensips -n 80 --no-pager >>/tmp/opensips-boot.log 2>/dev/null || true
      if apply_boot_fixes /tmp/opensips-boot.log; then
        fixed=1
        stop_all
        systemctl reset-failed opensips >/dev/null 2>&1 || true
        systemctl start opensips || true
        stable=0
        continue
      fi
    fi
    break
  fi
  sleep 1
done

if [[ "$ok" -ne 1 ]]; then
  echo "======== NOT ACTIVE ========"
  systemctl --no-pager --full status opensips || true
  dump_reason
  stop_all
  exit 1
fi

sleep 1
if [[ -p /run/opensips/opensips_fifo ]]; then
  chmod 0666 /run/opensips/opensips_fifo || true
fi
echo "======== After ========"
systemctl --no-pager --full status opensips | head -n 12 || true
ss -H -ulnp 'sport = :5060' 2>/dev/null || true
ss -H -ltn 'sport = :8888' 2>/dev/null || true
echo "OK: opensips is active (stayed running)"
systemctl enable opensips >/dev/null 2>&1 || true
exit 0
