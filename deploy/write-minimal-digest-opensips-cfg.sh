#!/usr/bin/env bash
# OVERWRITE /etc/opensips/opensips.cfg with a minimal digest-REGISTER config.
# Stops IP open-200 and Domain 483. Run as root:
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/write-minimal-digest-opensips-cfg.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

CFG=/etc/opensips/opensips.cfg
BKDIR=/var/backups/ownswitch-config
install -d "$BKDIR"
[[ -f "$CFG" ]] && cp -a "$CFG" "$BKDIR/opensips.cfg.before-minimal.$(date -u +%Y%m%dT%H%M%SZ)"

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq || true
apt-get install -y opensips-auth-modules opensips-mysql-module opensips-nathelper-module || true

MOD_DIR="$(dirname "$(find /usr/lib -type f -name tm.so -path '*opensips/modules*' 2>/dev/null | head -1)")"
[[ -d "$MOD_DIR" ]] || { echo "FATAL: no opensips modules"; exit 1; }
MPATH="${MOD_DIR}/"
echo "MPATH=$MPATH"
for so in auth.so auth_db.so usrloc.so registrar.so sipmsgops.so db_mysql.so signaling.so sl.so tm.so rr.so maxfwd.so uri.so mi_fifo.so proto_udp.so proto_tcp.so nathelper.so; do
  [[ -f "$MPATH$so" ]] || { echo "FATAL: missing $so"; ls "$MPATH" | head -n 80; exit 1; }
  echo "OK $so"
done

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' /etc/ownswitch/install.conf 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
PUBLIC_IP="$(curl -4 -s --max-time 5 ifconfig.me || true)"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
LOCAL="$(hostname -I || true)"
if [[ -n "$DNS_IP" && " $LOCAL " == *" $DNS_IP "* ]]; then PUBLIC_IP="$DNS_IP"; fi
[[ -n "$PUBLIC_IP" ]] || PUBLIC_IP="$DNS_IP"
[[ -n "$PUBLIC_IP" ]] || PUBLIC_IP="$(echo "$LOCAL" | awk '{print $1}')"
PORT=5060

PW=""
[[ -f /etc/ownswitch/secrets/mysql_opensips_password ]] && PW="$(tr -d '\n' </etc/ownswitch/secrets/mysql_opensips_password)"
[[ -n "$PW" ]] || PW="opensips"
# Escape for embedding in cfg string
PW_ESC="${PW//\\/\\\\}"
PW_ESC="${PW_ESC//\"/\\\"}"
DB_URL="mysql://opensips:${PW_ESC}@127.0.0.1/opensips"

echo "DOMAIN=$DOMAIN PUBLIC_IP=$PUBLIC_IP"

bash /opt/ownswitch-src/deploy/fix-opensips-provision.sh || true
mysql --protocol=socket -uroot opensips -e \
  "ALTER TABLE subscriber ADD COLUMN password VARCHAR(64) NOT NULL DEFAULT ''" 2>/dev/null || true
mysql --protocol=socket -uroot opensips -e \
  "UPDATE subscriber SET password='Openaccount@12345.' WHERE username='owner'; SELECT username, IF(password='','EMPTY','set') pwd FROM subscriber WHERE username='owner';" \
  || true

EXTRA_ALIASES=""
for ip in $LOCAL; do
  [[ "$ip" == "$PUBLIC_IP" ]] && continue
  EXTRA_ALIASES="${EXTRA_ALIASES}alias=\"${ip}\"
"
done

python3 - "$CFG" "$MPATH" "$DOMAIN" "$PUBLIC_IP" "$PORT" "$DB_URL" "$EXTRA_ALIASES" <<'PY'
import sys
path, mpath, domain, public_ip, port, db_url, extra = sys.argv[1:8]
cfg = f'''#
# Ownswitch MINIMAL digest REGISTER (Domain FQDN or IP, no Proxy)
#
log_level=3
xlog_level=3
stderror_enabled=no
syslog_enabled=yes
auto_aliases=yes
mpath="{mpath}"

socket=udp:0.0.0.0:{port} as {public_ip}:{port}
socket=tcp:0.0.0.0:{port} as {public_ip}:{port}
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
loadmodule "rr.so"
loadmodule "maxfwd.so"
loadmodule "sipmsgops.so"
loadmodule "uri.so"
loadmodule "mi_fifo.so"
modparam("mi_fifo", "fifo_name", "/run/opensips/opensips_fifo")
modparam("mi_fifo", "fifo_mode", 0660)

loadmodule "auth.so"
loadmodule "auth_db.so"
modparam("auth_db", "db_url", "{db_url}")
modparam("auth_db", "calculate_ha1", 1)
modparam("auth_db", "password_column", "password")
modparam("auth_db", "use_domain", 0)

loadmodule "usrloc.so"
modparam("usrloc", "working_mode_preset", "single-instance-sql-write-through")
modparam("usrloc", "db_url", "{db_url}")
modparam("usrloc", "use_domain", 0)

loadmodule "registrar.so"
modparam("registrar", "default_expires", 3600)

loadmodule "nathelper.so"
modparam("nathelper", "received_avp", "$avp(received)")

route {{
	if (is_method("REGISTER")) {{
		rewritehost("{public_ip}");
		xlog("L_INFO", "MINREG from $si user=$tU@$td\\n");
		if (!www_authorize("$td", "subscriber")) {{
			www_challenge("$td", "auth");
			exit;
		}}
		force_rport();
		if (nat_uac_test("19")) {{
			fix_nated_register();
		}}
		if (!save("location")) {{
			sl_reply_error();
			exit;
		}}
		xlog("L_INFO", "MINREG OK $tU@$td\\n");
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
		}} else {{
			send_reply(404, "Not here");
		}}
		exit;
	}}

	if (is_method("INVITE")) {{
		if (!proxy_authorize("", "subscriber")) {{
			proxy_challenge("", "auth");
			exit;
		}}
		consume_credentials();
		if (lookup("location")) {{
			record_route();
			route(relay);
			exit;
		}}
		send_reply(404, "User not registered");
		exit;
	}}

	send_reply(405, "Method Not Allowed");
}}

route[relay] {{
	if (!t_relay()) {{
		send_reply(500, "Error");
	}}
	exit;
}}
'''
open(path, "w", encoding="utf-8").write(cfg)
print("wrote", path)
PY

install -d -m 0750 -o opensips -g opensips /run/opensips
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

echo "opensips -c ..."
opensips -c -f "$CFG"

systemctl stop opensips || true
fuser -k ${PORT}/udp 2>/dev/null || true
fuser -k ${PORT}/tcp 2>/dev/null || true
sleep 1
systemctl start opensips
sleep 2
systemctl is-active opensips
ss -ulnp | grep 5060 || true

echo "==== cfg REGISTER head ===="
grep -nE 'mpath=|alias=|auth\.so|www_authorize|REGISTER|rewritehost' "$CFG" | head -n 40

python3 - "$DOMAIN" "$PUBLIC_IP" <<'PY'
import socket, sys
domain, ip = sys.argv[1], sys.argv[2]

def probe(host):
    msg = (
      f"REGISTER sip:{host} SIP/2.0\r\n"
      f"Via: SIP/2.0/UDP 127.0.0.1:5099;branch=z9hG4bKm;rport\r\n"
      f"Max-Forwards: 70\r\n"
      f"From: <sip:m@{host}>;tag=t\r\n"
      f"To: <sip:m@{host}>\r\n"
      f"Call-ID: m-{host}@127.0.0.1\r\n"
      f"CSeq: 1 REGISTER\r\n"
      f"Contact: <sip:m@127.0.0.1:5099>\r\n"
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
        raise SystemExit(f"FAIL expected 401 got {line}")
print("SUCCESS: digest REGISTER works for Domain + IP")
PY

echo "Softphone: Domain=$PUBLIC_IP OR $DOMAIN | Proxy=BLANK | User=owner | Pass=Openaccount@12345. | 5060 UDP"
echo "Test: sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env php /opt/ownswitch/current/bin/test-sip-register.php owner 'Openaccount@12345.'"
