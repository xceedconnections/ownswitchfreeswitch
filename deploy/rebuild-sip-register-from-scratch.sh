#!/usr/bin/env bash
# =============================================================================
# Rebuild SIP user REGISTER from scratch (portal untouched).
# MagnusBilling/default OpenSIPS open-registrar → Ownswitch digest REGISTER.
#
# MUST run as root (prompt root@...). Do NOT use sudo -u ownswitch.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/rebuild-sip-register-from-scratch.sh
# =============================================================================
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "FATAL: run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
BKDIR=/var/backups/ownswitch-config
OWN_ETC=/etc/ownswitch
IC="${OWN_ETC}/install.conf"
ENV_FILE="${OWN_ETC}/ownswitch.env"
PORT=5060

cd "$SRC"
git fetch origin main
git reset --hard origin/main

install -d -m 0755 "$BKDIR"
ts="$(date -u +%Y%m%dT%H%M%SZ)"
[[ -f "$CFG" ]] && cp -a "$CFG" "${BKDIR}/opensips.cfg.pre-rebuild.${ts}"

echo "======== A) Stop everything on SIP ${PORT} ========"
systemctl stop opensips 2>/dev/null || true
systemctl stop asterisk 2>/dev/null || true
systemctl stop kamailio 2>/dev/null || true
# Magnus helpers that may rewrite opensips.cfg
systemctl stop mbilling_callcenter 2>/dev/null || true
systemctl disable --now opensips 2>/dev/null || true
sleep 1
fuser -k ${PORT}/udp 2>/dev/null || true
fuser -k ${PORT}/tcp 2>/dev/null || true
sleep 1
if ss -ulnp | grep -qE ":${PORT}\\b"; then
  echo "FATAL: something still listens on UDP ${PORT}:"
  ss -ulnp | grep -E ":${PORT}\\b" || true
  exit 1
fi
echo "Port ${PORT} is free."

echo "======== B) Packages (auth.so is REQUIRED) ========"
export DEBIAN_FRONTEND=noninteractive

wait_for_apt() {
  local i
  echo "Waiting for apt/dpkg lock (unattended-upgrades)..."
  for i in $(seq 1 180); do
    if ! fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 \
      && ! fuser /var/lib/dpkg/lock >/dev/null 2>&1 \
      && ! fuser /var/lib/apt/lists/lock >/dev/null 2>&1; then
      # also ensure no unattended-upgr / apt-get running
      if ! pgrep -x unattended-upgr >/dev/null 2>&1 \
        && ! pgrep -x apt-get >/dev/null 2>&1 \
        && ! pgrep -x dpkg >/dev/null 2>&1; then
        echo "apt lock free after ${i}s"
        return 0
      fi
    fi
    if (( i % 15 == 0 )); then
      echo "  still waiting... ${i}s (pid: $(pgrep -a unattended-upgr || pgrep -a apt-get || true))"
    fi
    sleep 1
  done
  echo "WARN: apt still busy after 180s — will try anyway / use existing modules"
  return 1
}

wait_for_apt || true

# Ensure apt.opensips.org present (stage 50 style)
if [[ ! -f /etc/apt/sources.list.d/opensips.list ]]; then
  curl -fsSL https://apt.opensips.org/opensips-org.gpg -o /usr/share/keyrings/opensips-org.gpg || true
  . /etc/os-release
  echo "deb [signed-by=/usr/share/keyrings/opensips-org.gpg] https://apt.opensips.org ${VERSION_CODENAME} 3.6-releases" \
    >/etc/apt/sources.list.d/opensips.list || true
fi
apt-get update -qq || true
apt-get install -y opensips opensips-auth-modules opensips-mysql-module opensips-cli || true
apt-get install -y opensips-auth-modules || true
# nathelper package name varies; .so is often already in the main opensips package
apt-get install -y opensips-nathelper-module 2>/dev/null || true

MOD_DIR="$(dirname "$(find /usr/lib -type f -name tm.so -path '*opensips/modules*' 2>/dev/null | head -1)")"
[[ -d "$MOD_DIR" ]] || { echo "FATAL: modules dir missing"; exit 1; }
MPATH="${MOD_DIR}/"
echo "MPATH=$MPATH"
# uri.so removed in OpenSIPS 3.x — do not require it
# proto_udp.so / proto_tcp.so are BUILT INTO the opensips binary (no .so on disk)
# but loadmodule "proto_udp.so" is still required in the cfg.
for so in auth.so auth_db.so usrloc.so registrar.so sipmsgops.so db_mysql.so \
          signaling.so sl.so tm.so rr.so maxfwd.so mi_fifo.so; do
  [[ -f "${MPATH}${so}" ]] || { echo "FATAL: missing ${so}"; ls "$MPATH" | head -n 100; exit 1; }
  echo "  OK ${so}"
done
echo "  OK proto_udp/proto_tcp (built-in to opensips binary)"
if [[ -f "${MPATH}nathelper.so" ]]; then
  echo "  OK nathelper.so"
  USE_NATHELPER=1
else
  echo "  WARN nathelper.so missing — REGISTER still works; NAT softphones may need it"
  USE_NATHELPER=0
fi
export USE_NATHELPER

echo "======== C) Identity / DB ========"
DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "$IC" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
PUBLIC_IP="$(curl -4 -s --max-time 5 ifconfig.me || true)"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
LOCAL="$(hostname -I || true)"
if [[ -n "$DNS_IP" && " $LOCAL " == *" $DNS_IP "* ]]; then PUBLIC_IP="$DNS_IP"; fi
[[ -n "$PUBLIC_IP" ]] || PUBLIC_IP="${DNS_IP:-}"
[[ -n "$PUBLIC_IP" ]] || PUBLIC_IP="$(echo "$LOCAL" | awk '{print $1}')"
PRIV="$(echo "$LOCAL" | awk '{print $1}')"
echo "DOMAIN=$DOMAIN PUBLIC_IP=$PUBLIC_IP LOCAL=$LOCAL"

grep -vE '^(PRIVATE_IP|PUBLIC_IP|OWNSWITCH_DOMAIN)=' "$IC" > /tmp/ic.$$ 2>/dev/null || true
{ echo "OWNSWITCH_DOMAIN=${DOMAIN}"; echo "PUBLIC_IP=${PUBLIC_IP}"; echo "PRIVATE_IP=${PRIV}"; cat /tmp/ic.$$; } > "$IC"
rm -f /tmp/ic.$$
touch "$ENV_FILE"
grep -q '^OPENSIPS_SIP_DOMAIN=' "$ENV_FILE" && sed -i "s/^OPENSIPS_SIP_DOMAIN=.*/OPENSIPS_SIP_DOMAIN=${DOMAIN}/" "$ENV_FILE" || echo "OPENSIPS_SIP_DOMAIN=${DOMAIN}" >>"$ENV_FILE"
grep -q '^SIP_PUBLIC_IP=' "$ENV_FILE" && sed -i "s/^SIP_PUBLIC_IP=.*/SIP_PUBLIC_IP=${PUBLIC_IP}/" "$ENV_FILE" || echo "SIP_PUBLIC_IP=${PUBLIC_IP}" >>"$ENV_FILE"
grep -q '^OPENSIPS_ENABLED=' "$ENV_FILE" && sed -i 's/^OPENSIPS_ENABLED=.*/OPENSIPS_ENABLED=1/' "$ENV_FILE" || echo 'OPENSIPS_ENABLED=1' >>"$ENV_FILE"

PW=""
[[ -f "${OWN_ETC}/secrets/mysql_opensips_password" ]] && PW="$(tr -d '\n' <"${OWN_ETC}/secrets/mysql_opensips_password")"
[[ -n "$PW" ]] || PW="opensips"
DB_URL="$(python3 -c "import urllib.parse,sys; print('mysql://opensips:%s@127.0.0.1/opensips'%urllib.parse.quote(sys.argv[1],safe=''))" "$PW")"

bash "$SRC/deploy/fix-opensips-provision.sh" || true
# location + subscriber.password + address (permissions) schema
mysql --protocol=socket -uroot <<'SQL' || true
CREATE DATABASE IF NOT EXISTS opensips CHARACTER SET utf8mb4;
USE opensips;
CREATE TABLE IF NOT EXISTS version (
  table_name CHAR(32) NOT NULL PRIMARY KEY,
  table_version INT UNSIGNED NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS location (
  contact_id BIGINT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  username CHAR(64) DEFAULT '' NOT NULL,
  domain CHAR(64) DEFAULT NULL,
  contact TEXT NOT NULL,
  received CHAR(255) DEFAULT NULL,
  path CHAR(255) DEFAULT NULL,
  expires INT(10) UNSIGNED NOT NULL,
  q FLOAT(10,2) DEFAULT 1.0 NOT NULL,
  callid CHAR(255) DEFAULT 'Default-Call-ID' NOT NULL,
  cseq INT(11) DEFAULT 13 NOT NULL,
  last_modified DATETIME DEFAULT '1900-01-01 00:00:01' NOT NULL,
  flags INT(11) DEFAULT 0 NOT NULL,
  cflags CHAR(255) DEFAULT NULL,
  user_agent CHAR(255) DEFAULT '' NOT NULL,
  socket CHAR(64) DEFAULT NULL,
  methods INT(11) DEFAULT NULL,
  sip_instance CHAR(255) DEFAULT NULL,
  kv_store TEXT(512) DEFAULT NULL,
  attr CHAR(255) DEFAULT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO version (table_name, table_version) VALUES ('location', 1013);
SQL
# Apply packaged schemas if subscriber / address missing
for want in standard permissions usrloc auth_db; do
  if [[ "$want" == "permissions" ]]; then
    mysql --protocol=socket -uroot -N -e "SELECT 1 FROM information_schema.tables WHERE table_schema='opensips' AND table_name='address'" | grep -q 1 && continue
  elif [[ "$want" == "usrloc" ]]; then
    mysql --protocol=socket -uroot -N -e "SELECT 1 FROM information_schema.tables WHERE table_schema='opensips' AND table_name='location'" | grep -q 1 && continue
  elif [[ "$want" == "standard" || "$want" == "auth_db" ]]; then
    mysql --protocol=socket -uroot -N -e "SELECT 1 FROM information_schema.tables WHERE table_schema='opensips' AND table_name='subscriber'" | grep -q 1 && [[ "$want" != "standard" ]] && continue
    if [[ "$want" == "standard" ]]; then
      mysql --protocol=socket -uroot -N -e "SELECT 1 FROM information_schema.tables WHERE table_schema='opensips' AND table_name='subscriber'" | grep -q 1 && continue
    fi
  fi
  f=$(find /usr/share/opensips /usr/share/doc /usr/share/opensips-mysql-module \
    \( -name "${want}-create.sql" -o -name "*${want}*create*.sql" \) 2>/dev/null | head -1 || true)
  if [[ -n "${f:-}" ]]; then
    echo "Applying schema $f"
    mysql --protocol=socket -uroot opensips <"$f" || true
  fi
done
# Fallback address table for permissions module (ver 5)
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

# FORCE correct OpenSIPS 3.6 location schema (stale/Magnus tables cause REGISTER → 500)
echo "Recreating opensips.location (usrloc 1013)..."
mysql --protocol=socket -uroot opensips <<'SQL'
DROP TABLE IF EXISTS location;
CREATE TABLE location (
  contact_id BIGINT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  username CHAR(64) DEFAULT '' NOT NULL,
  domain CHAR(64) DEFAULT NULL,
  contact TEXT NOT NULL,
  received CHAR(255) DEFAULT NULL,
  path CHAR(255) DEFAULT NULL,
  expires INT(10) UNSIGNED NOT NULL,
  q FLOAT(10,2) DEFAULT 1.0 NOT NULL,
  callid CHAR(255) DEFAULT 'Default-Call-ID' NOT NULL,
  cseq INT(11) DEFAULT 13 NOT NULL,
  last_modified DATETIME DEFAULT '1900-01-01 00:00:01' NOT NULL,
  flags INT(11) DEFAULT 0 NOT NULL,
  cflags CHAR(255) DEFAULT NULL,
  user_agent CHAR(255) DEFAULT '' NOT NULL,
  socket CHAR(64) DEFAULT NULL,
  methods INT(11) DEFAULT NULL,
  sip_instance CHAR(255) DEFAULT NULL,
  kv_store TEXT(512) DEFAULT NULL,
  attr CHAR(255) DEFAULT NULL
) ENGINE=InnoDB;
DELETE FROM version WHERE table_name='location';
INSERT INTO version (table_name, table_version) VALUES ('location', 1013);
SQL

mysql --protocol=socket -uroot opensips -e \
  "ALTER TABLE subscriber ADD COLUMN password VARCHAR(64) NOT NULL DEFAULT ''" 2>/dev/null || true
mysql --protocol=socket -uroot opensips -e \
  "ALTER TABLE subscriber ADD COLUMN media_policy VARCHAR(16) NOT NULL DEFAULT 'relay'" 2>/dev/null || true
mysql --protocol=socket -uroot opensips -e \
  "ALTER TABLE subscriber ADD COLUMN dtmf_pass VARCHAR(8) NOT NULL DEFAULT 'yes'" 2>/dev/null || true
mysql --protocol=socket -uroot opensips -e \
  "ALTER TABLE subscriber ADD COLUMN cid_mode VARCHAR(16) NOT NULL DEFAULT 'passthrough'" 2>/dev/null || true
mysql --protocol=socket -uroot opensips -e \
  "ALTER TABLE subscriber ADD COLUMN rpid VARCHAR(64) NOT NULL DEFAULT ''" 2>/dev/null || true
# Grants for opensips DB user
PW_SQL="$PW"
mysql --protocol=socket -uroot -e \
  "GRANT ALL PRIVILEGES ON opensips.* TO 'opensips'@'localhost'; GRANT ALL PRIVILEGES ON opensips.* TO 'opensips'@'127.0.0.1'; FLUSH PRIVILEGES;" || true

echo "======== D) Write Ownswitch digest REGISTER cfg (atomic) ========"
# Unlock if previously immutable
chattr -i "$CFG" 2>/dev/null || true

EXTRA=""
for ip in $LOCAL; do
  [[ "$ip" == "$PUBLIC_IP" ]] && continue
  EXTRA="${EXTRA}alias=\"${ip}\"
"
done

NAT_BLOCK=""
NAT_REG=""
if [[ "${USE_NATHELPER:-0}" == "1" ]]; then
  NAT_BLOCK='loadmodule "nathelper.so"
modparam("nathelper", "received_avp", "$avp(received)")'
  # OpenSIPS 3.x: nat_uac_test() uses named flags, not numeric "19" — always fix NAT
  NAT_REG='force_rport();
		fix_nated_register();'
fi

python3 - "$CFG" "$MPATH" "$DOMAIN" "$PUBLIC_IP" "$PORT" "$DB_URL" "$EXTRA" "$NAT_BLOCK" "$NAT_REG" <<'PY'
import sys
path, mpath, domain, public_ip, port, db_url, extra, nat_block, nat_reg = sys.argv[1:10]
open(path, "w", encoding="utf-8").write(f'''#
# Ownswitch SIP REGISTER + local delivery (rebuilt from scratch)
# Domain = public IP OR any FQDN pointed here. Outbound Proxy = blank.
#
log_level=3
xlog_level=3
stderror_enabled=no
syslog_enabled=yes
syslog_facility=LOG_LOCAL0
syslog_name="opensips"
auto_aliases=yes
mpath="{mpath}"

socket=udp:{public_ip}:{port}
socket=tcp:{public_ip}:{port}
socket=udp:127.0.0.1:{port}
socket=tcp:127.0.0.1:{port}
alias="{domain}"
alias="{public_ip}"
alias="127.0.0.1"
{extra}
loadmodule "db_mysql.so"
loadmodule "proto_udp.so"
loadmodule "proto_tcp.so"
loadmodule "signaling.so"
loadmodule "sl.so"
loadmodule "tm.so"
modparam("tm", "fr_timeout", 5)
modparam("tm", "fr_inv_timeout", 60)
loadmodule "rr.so"
loadmodule "maxfwd.so"
loadmodule "sipmsgops.so"
loadmodule "mi_fifo.so"
modparam("mi_fifo", "fifo_name", "/run/opensips/opensips_fifo")
modparam("mi_fifo", "fifo_mode", 0660)

loadmodule "auth.so"
loadmodule "auth_db.so"
modparam("auth_db", "db_url", "{db_url}")
modparam("auth_db", "calculate_ha1", 1)
modparam("auth_db", "password_column", "password")
modparam("auth_db", "use_domain", 0)
modparam("auth_db", "load_credentials", "$avp(os_media)=media_policy;$avp(os_dtmf)=dtmf_pass;$avp(os_cid_mode)=cid_mode;$avp(os_cid)=rpid")

loadmodule "usrloc.so"
modparam("usrloc", "working_mode_preset", "single-instance-sql-write-through")
modparam("usrloc", "db_url", "{db_url}")
modparam("usrloc", "use_domain", 0)

loadmodule "registrar.so"
modparam("registrar", "max_contacts", 5)
modparam("registrar", "default_expires", 3600)

{nat_block}

# permissions/address table applied later by stage 50 — omit here so REGISTER works now

route {{
	# ---- REGISTER first (never DNS-loop → 483) ----
	if (is_method("REGISTER")) {{
		sethost("{public_ip}");
		xlog("L_INFO", "REG $si $tU@$td\\n");
		if (!www_authorize("$td", "subscriber")) {{
			www_challenge("$td", "auth");
			exit;
		}}
		{nat_reg}
		if (!save("location")) {{
			sl_reply_error();
			exit;
		}}
		xlog("L_INFO", "REG OK $tU@$td\\n");
		exit;
	}}

	if (!mf_process_maxfwd_header(10)) {{
		send_reply(483, "Too Many Hops");
		exit;
	}}

	if (is_method("OPTIONS")) {{
		send_reply(200, "OK");
		exit;
	}}

	if (has_totag()) {{
		if (loose_route()) {{
			t_relay();
		}} else if (is_method("ACK") && t_check_trans()) {{
			t_relay();
		}} else {{
			send_reply(404, "Not here");
		}}
		exit;
	}}

	if (is_method("CANCEL")) {{
		if (t_check_trans()) t_relay();
		exit;
	}}

	t_check_trans();

	if (!is_method("INVITE")) {{
		send_reply(405, "Method Not Allowed");
		exit;
	}}

	# Digest auth for INVITE (IP ACL needs permissions.address — stage 50)
	if (!proxy_authorize("$fd", "subscriber")) {{
		proxy_challenge("$fd", "auth");
		exit;
	}}
	consume_credentials();

	if (lookup("location")) {{
		record_route();
		t_relay();
		exit;
	}}

	send_reply(404, "User not registered");
}}
''')
print("wrote", path)
PY

grep -q 'www_authorize' "$CFG" || { echo "FATAL: write failed"; exit 1; }
grep -qE 'sethost\(|\$rd\s*=' "$CFG" || { echo "FATAL: no sethost/\$rd in REGISTER"; exit 1; }
grep -q "alias=\"${DOMAIN}\"" "$CFG" || { echo "FATAL: no domain alias"; exit 1; }

# Prevent Magnus/cron from silently overwriting until we unlock
chattr +i "$CFG" 2>/dev/null || echo "WARN: chattr +i not available (cfg may be overwritten by other tools)"

install -d -m 0750 -o opensips -g opensips /run/opensips
if [[ -f /etc/default/opensips ]]; then
  sed -i 's/^RUN_OPENSIPS=no/RUN_OPENSIPS=yes/' /etc/default/opensips || true
fi
install -d /etc/systemd/system/opensips.service.d
# Package unit is Type=forking; -F (foreground) requires Type=simple or start fails
cat >/etc/systemd/system/opensips.service.d/ownswitch.conf <<EOF
[Service]
Type=simple
ExecStart=
ExecStart=/usr/sbin/opensips -F -f ${CFG}
Restart=on-failure
RestartSec=2
TimeoutStartSec=20
EOF
systemctl daemon-reload
systemctl enable opensips

echo "======== E) Syntax + start ========"
opensips -c -f "$CFG"
# Clear any stale workers still holding 5060 from earlier failed runs
systemctl stop opensips 2>/dev/null || true
fuser -k ${PORT}/udp 2>/dev/null || true
fuser -k ${PORT}/tcp 2>/dev/null || true
sleep 1
systemctl start opensips
sleep 2
if ! systemctl is-active --quiet opensips; then
  echo "FATAL: opensips.service failed to start"
  systemctl --no-pager -l status opensips || true
  journalctl -u opensips --no-pager -n 80 || true
  exit 1
fi
ss -ulnp | grep -E ":${PORT}\\b" || { echo "FATAL: not listening on UDP ${PORT}"; journalctl -u opensips -n 50 --no-pager; exit 1; }
ss -tlnp | grep -E ":${PORT}\\b" || echo "WARN: no TCP ${PORT}"

echo "======== F) Sync portal SIP users → subscriber.password ========"
bash "$SRC/deploy/refresh-ui.sh" || true
CUR="$(readlink -f /opt/ownswitch/current)"
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true
# Ensure owner has a working plaintext password for tests
mysql --protocol=socket -uroot opensips -e \
  "UPDATE subscriber SET password='Openaccount@12345.' WHERE username='owner'; SELECT username, domain, IF(password='','EMPTY','set') pwd FROM subscriber;" \
  || true

echo "======== G) Prove digest (401 then authenticated 200) ========"
python3 - "$DOMAIN" "$PUBLIC_IP" "owner" "Openaccount@12345." <<'PY'
import hashlib, socket, sys, re
domain, ip, user, password = sys.argv[1:5]

def recv_sip(sock):
    data = sock.recv(8192)
    return data.decode(errors="replace")

def register(host, with_auth=False, realm=None, nonce=None):
    call_id = f"rebuild-{host}@127.0.0.1"
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.settimeout(3)
    uri = f"sip:{host}"
    auth = ""
    cseq = 1
    if with_auth and realm and nonce:
        ha1 = hashlib.md5(f"{user}:{realm}:{password}".encode()).hexdigest()
        ha2 = hashlib.md5(f"REGISTER:{uri}".encode()).hexdigest()
        resp = hashlib.md5(f"{ha1}:{nonce}:{ha2}".encode()).hexdigest()
        auth = (
            f'Authorization: Digest username="{user}", realm="{realm}", '
            f'nonce="{nonce}", uri="{uri}", response="{resp}", algorithm=MD5\r\n'
        )
        cseq = 2
    msg = (
        f"REGISTER {uri} SIP/2.0\r\n"
        f"Via: SIP/2.0/UDP 127.0.0.1:5099;branch=z9hG4bKreb;rport\r\n"
        f"Max-Forwards: 70\r\n"
        f"From: <sip:{user}@{host}>;tag=reb\r\n"
        f"To: <sip:{user}@{host}>\r\n"
        f"Call-ID: {call_id}\r\n"
        f"CSeq: {cseq} REGISTER\r\n"
        f"Contact: <sip:{user}@127.0.0.1:5099>\r\n"
        f"{auth}"
        f"Expires: 60\r\n"
        f"Content-Length: 0\r\n\r\n"
    )
    sock.sendto(msg.encode(), ("127.0.0.1", 5060))
    try:
        text = recv_sip(sock)
    except Exception as e:
        sock.close()
        return f"NO_RESPONSE {e}", None, None
    sock.close()
    line = text.splitlines()[0] if text else "EMPTY"
    rm = re.search(r'realm="([^"]+)"', text)
    nm = re.search(r'nonce="([^"]+)"', text)
    return line, (rm.group(1) if rm else None), (nm.group(1) if nm else None)

fail = 0
for label, host in (("Domain", domain), ("IP", ip)):
    line, realm, nonce = register(host, with_auth=False)
    print(f"{label} {host} unauth → {line}")
    if not (line.startswith("SIP/2.0 401") or line.startswith("SIP/2.0 407")):
        print(f"FAIL: expected 401 challenge for {host}")
        fail = 1
        continue
    line2, _, _ = register(host, with_auth=True, realm=realm, nonce=nonce)
    print(f"{label} {host} digest → {line2}")
    if not line2.startswith("SIP/2.0 200"):
        print(f"FAIL: expected 200 after digest for {host}")
        fail = 1

if fail:
    print("--- journalctl opensips (save/location errors) ---")
    import subprocess
    subprocess.run(["journalctl", "-u", "opensips", "--no-pager", "-n", "40"], check=False)
    raise SystemExit(1)
print("SUCCESS: Domain + IP REGISTER require password and succeed with digest.")
PY

echo
echo "################################################################"
echo " SIP REGISTER rebuilt."
echo " Softphone: User=owner  Pass=Openaccount@12345."
echo "   Domain = ${PUBLIC_IP}  OR  ${DOMAIN}  OR any FQDN → ${PUBLIC_IP}"
echo "   Proxy  = BLANK"
echo "   Port   = 5060 UDP"
echo " Portal unchanged. opensips.cfg is immutable (chattr +i)."
echo " To unlock later: chattr -i ${CFG}"
echo "################################################################"
