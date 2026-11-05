#!/usr/bin/env bash
# Surgical patch: force digest REGISTER on whatever opensips.cfg is live.
# Does NOT depend on full Ownswitch template / stage 50 succeeding.
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/patch-opensips-register-auth-now.sh | sudo bash
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root (sudo bash ...), not sudo -u ownswitch"; exit 1; }

CFG="$(systemctl show -p ExecStart opensips 2>/dev/null | sed -n 's/.*-f[[:space:]]*\([^[:space:]]*\).*/\1/p' || true)"
[[ -n "$CFG" && -f "$CFG" ]] || CFG=/etc/opensips/opensips.cfg
[[ -f "$CFG" ]] || { echo "FATAL: no opensips.cfg at $CFG"; exit 1; }

OWN_ETC=/etc/ownswitch
IC="${OWN_ETC}/install.conf"
ENV_FILE="${OWN_ETC}/ownswitch.env"
DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "$IC" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
DETECT_IP="$(curl -4 -s --max-time 5 ifconfig.me 2>/dev/null || true)"
LOCAL_IPS="$(hostname -I 2>/dev/null || true)"
PUBLIC_IP="${DETECT_IP:-}"
if [[ -n "$DNS_IP" && " $LOCAL_IPS " == *" $DNS_IP "* ]]; then PUBLIC_IP="$DNS_IP"; fi
[[ -n "$PUBLIC_IP" ]] || PUBLIC_IP="$DNS_IP"
[[ -n "$PUBLIC_IP" ]] || PUBLIC_IP="$(echo "$LOCAL_IPS" | awk '{print $1}')"
[[ -n "$PUBLIC_IP" ]] || { echo "FATAL: no PUBLIC_IP"; exit 1; }

echo "CFG=$CFG"
echo "DOMAIN=$DOMAIN PUBLIC_IP=$PUBLIC_IP"
echo "ExecStart: $(systemctl show -p ExecStart opensips 2>/dev/null | head -1 || true)"

BACKUP="/var/backups/ownswitch-config/opensips.cfg.pre-patch.$(date -u +%Y%m%dT%H%M%SZ)"
install -d -m 0755 /var/backups/ownswitch-config
cp -a "$CFG" "$BACKUP"
echo "Backup: $BACKUP"

python3 - "$CFG" "$DOMAIN" "$PUBLIC_IP" <<'PY'
import re, sys
path, domain, public_ip = sys.argv[1], sys.argv[2], sys.argv[3]
text = open(path, encoding="utf-8", errors="replace").read()

# --- auth_db: plaintext password / any realm (Magnus-style) ---
def set_modparam(src, module, param, value):
    # value is raw inside modparam, e.g. 1 or "password"
    pat = re.compile(
        r'(modparam\(\s*"' + re.escape(module) + r'"\s*,\s*"' + re.escape(param) + r'"\s*,\s*)([^)]+)(\))',
        re.I,
    )
    if pat.search(src):
        return pat.sub(r'\g<1>' + value + r'\g<3>', src, count=1)
    # insert after auth_db load if present
    m = re.search(r'loadmodule\s+"auth_db\.so"', src)
    if not m:
        return src
    insert = f'\nmodparam("auth_db", "{param}", {value})'
    return src[: m.end()] + insert + src[m.end() :]

text = set_modparam(text, "auth_db", "calculate_ha1", "1")
text = set_modparam(text, "auth_db", "password_column", '"password"')
text = set_modparam(text, "auth_db", "use_domain", "0")

# --- aliases ---
def ensure_alias(src, host):
    line = f'alias="{host}"'
    if re.search(r'(?m)^alias\s*=\s*"' + re.escape(host) + r'"\s*;?\s*$', src):
        return src
    # after auto_aliases or first socket
    m = re.search(r'(?m)^auto_aliases\s*=.*$', src)
    if m:
        return src[: m.end()] + "\n" + line + src[m.end() :]
    m = re.search(r'(?m)^socket\s*=.*$', src)
    if m:
        return src[: m.end()] + "\n" + line + src[m.end() :]
    return line + "\n" + src

text = ensure_alias(text, domain)
text = ensure_alias(text, public_ip)
text = ensure_alias(text, "127.0.0.1")

REGISTER_BLOCK = f'''
\tif (is_method("REGISTER")) {{
\t\trewritehost("{public_ip}");
\t\txlog("L_INFO", "OWNREGISTER from $si user=$tU@$td\\n");
\t\tif (!www_authorize("$td", "subscriber")) {{
\t\t\txlog("L_WARN", "OWNREGISTER auth fail $tU@$td\\n");
\t\t\twww_challenge("$td", "auth");
\t\t\texit;
\t\t}}
\t\tif (is_present_hf("Contact")) {{
\t\t\tif (!save("location")) {{
\t\t\t\tsl_reply_error();
\t\t\t\texit;
\t\t\t}}
\t\t}}
\t\txlog("L_INFO", "OWNREGISTER OK $tU@$td\\n");
\t\texit;
\t}}
'''

# Remove existing top-level REGISTER handlers inside route { ... } (best-effort)
# Strategy: find first `route {` / `route{` and inject our block right after `{`,
# and comment out previous is_method("REGISTER") blocks by renaming to DISABLED_REGISTER.

def disable_register_blocks(src):
    # Turn if (is_method("REGISTER")) into if (0 && is_method("REGISTER"))
    return re.sub(
        r'if\s*\(\s*is_method\(\s*"REGISTER"\s*\)\s*\)',
        'if (0 && is_method("REGISTER"))',
        src,
    )

text = disable_register_blocks(text)

# Inject after first route {
m = re.search(r'(?m)^(route\s*\{)\s*$', text)
if not m:
    m = re.search(r'route\s*\{', text)
if not m:
    sys.stderr.write("FATAL: cannot find route { in cfg\n")
    sys.exit(1)

inject_at = m.end()
text = text[:inject_at] + "\n" + REGISTER_BLOCK + text[inject_at:]

open(path, "w", encoding="utf-8").write(text)
print("Patched", path)
PY

# Ensure subscriber.password column exists and portal secrets are synced
SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
if [[ -d "$SRC" ]]; then
  cd "$SRC"
  git fetch origin main 2>/dev/null && git reset --hard origin/main 2>/dev/null || true
  bash "$SRC/deploy/fix-opensips-provision.sh" || true
fi

mysql --protocol=socket -uroot opensips -e "ALTER TABLE subscriber ADD COLUMN password VARCHAR(64) NOT NULL DEFAULT ''" 2>/dev/null || true

# Push passwords from portal if possible
if [[ -f /opt/ownswitch/current/bin/sync-opensips-sip-users.php ]]; then
  runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" \
    php /opt/ownswitch/current/bin/sync-opensips-sip-users.php || true
fi

echo "Syntax check..."
if ! opensips -c -f "$CFG"; then
  echo "FATAL: patched cfg failed opensips -c — restoring backup"
  cp -a "$BACKUP" "$CFG"
  opensips -c -f "$CFG" || true
  exit 1
fi

systemctl stop opensips || true
fuser -k 5060/udp 2>/dev/null || true
fuser -k 5060/tcp 2>/dev/null || true
sleep 1
systemctl start opensips
sleep 2
systemctl --no-pager -l status opensips | head -n 20 || true

probe() {
  local host="$1"
  PROBE_HOST="$host" python3 - <<'PY'
import os, socket
host = os.environ["PROBE_HOST"]
msg = (
  f"REGISTER sip:{host} SIP/2.0\r\n"
  f"Via: SIP/2.0/UDP 127.0.0.1:5099;branch=z9hG4bKpatch;rport\r\n"
  f"Max-Forwards: 70\r\n"
  f"From: <sip:patch@{host}>;tag=p\r\n"
  f"To: <sip:patch@{host}>\r\n"
  f"Call-ID: patch-{host}@127.0.0.1\r\n"
  f"CSeq: 1 REGISTER\r\n"
  f"Contact: <sip:patch@127.0.0.1:5099>\r\n"
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
echo "==== Probes (must be 401) ===="
D="$(probe "$DOMAIN")"
I="$(probe "$PUBLIC_IP")"
echo "Domain $DOMAIN → $D"
echo "IP     $PUBLIC_IP → $I"
fail=0
[[ "$D" == SIP/2.0\ 401* || "$D" == SIP/2.0\ 407* ]] || fail=1
[[ "$I" == SIP/2.0\ 401* || "$I" == SIP/2.0\ 407* ]] || fail=1
if [[ "$fail" -eq 1 ]]; then
  echo "FATAL: patch did not take effect"
  grep -nE 'OWNREGISTER|www_authorize|calculate_ha1|alias=' "$CFG" | head -n 40
  journalctl -u opensips --no-pager -n 60 || true
  exit 1
fi

echo
echo "SUCCESS: REGISTER now requires digest."
echo "Softphone: User=owner  Domain=${PUBLIC_IP}  Proxy=BLANK  Port=5060 UDP"
echo "Re-save SIP password in portal, then:"
echo "  sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env php /opt/ownswitch/current/bin/test-sip-register.php owner 'YOUR_PASS'"
