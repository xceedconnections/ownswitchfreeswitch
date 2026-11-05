#!/usr/bin/env bash
# Fix SIP registration status, Rate CAP (SIP+trunk), tariff/provider-rate bulk delete.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-reg-cap-tariffs.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
ENV_FILE=/etc/ownswitch/ownswitch.env
APP=/opt/ownswitch/current
CFG=/etc/opensips/opensips.cfg
cd "$SRC"
git fetch origin main
git reset --hard origin/main

bash "$SRC/deploy/refresh-ui.sh"

# Migration 028 rate_cap
set -a; [[ -f "$ENV_FILE" ]] && . "$ENV_FILE"; set +a
DB_NAME="${DB_DATABASE:-ownswitch}"
DB_USER="${DB_USERNAME:-ownswitch}"
DB_PASS="${DB_PASSWORD:-}"
if [[ -n "$DB_PASS" && -f "$SRC/database/migrations/028_rate_cap.sql" ]]; then
  mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SRC/database/migrations/028_rate_cap.sql" && echo "OK migration 028"
fi

# OpenSIPS exec module for Rate CAP dial check
apt-get install -y opensips-module-exec 2>/dev/null || apt-get install -y opensips-exec-module 2>/dev/null || true

# Patch live opensips.cfg for check_rate_cap + exec (safe stub if module missing)
chattr -i "$CFG" 2>/dev/null || true
cp -a "$CFG" "/var/backups/ownswitch-config/opensips.cfg.pre-cap.$(date -u +%Y%m%dT%H%M%SZ)" 2>/dev/null \
  || { mkdir -p /var/backups/ownswitch-config; cp -a "$CFG" "/var/backups/ownswitch-config/opensips.cfg.pre-cap.$(date -u +%Y%m%dT%H%M%SZ)"; }

PHP_BIN="$(command -v php || echo /usr/bin/php)"
CAP_SCRIPT="$APP/bin/dial-cap-check.php"
MOD_DIR="$(dirname "$(find /usr/lib -type f -name tm.so -path '*opensips/modules*' 2>/dev/null | head -n1)")"
HAS_EXEC=0
[[ -f "${MOD_DIR}/exec.so" ]] && HAS_EXEC=1

python3 - "$CFG" "$HAS_EXEC" "$PHP_BIN" "$CAP_SCRIPT" <<'PY'
import sys, re
path, has_exec, php, script = sys.argv[1], sys.argv[2] == "1", sys.argv[3], sys.argv[4]
text = open(path, encoding="utf-8", errors="replace").read()

if has_exec and 'loadmodule "exec.so"' not in text:
    text = text.replace(
        'loadmodule "drouting.so"',
        'loadmodule "drouting.so"\nloadmodule "exec.so"',
        1,
    )

cap_route = r'''
route[check_rate_cap] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(tid) = $(avp(dr_gw_attrs){param.value,tid});
	if ($var(tid) == NULL || $(var(tid){s.len}) == 0) {
		return;
	}
	$var(cap_user) = $au;
	if ($var(cap_user) == NULL || $(var(cap_user){s.len}) == 0) {
		$var(cap_user) = $fU;
	}
'''
if has_exec:
    cap_route += f'''	if (!exec("{php} {script} $var(cap_user) $rU $var(tid)")) {{
		xlog("L_WARN", "Rate Cap Exceeded user=$var(cap_user) dst=$rU trunk=$var(tid)\\n");
		if ($var(cap_tries) == NULL) {{
			$var(cap_tries) = 0;
		}}
		$var(cap_tries) = $(var(cap_tries){{s.int}}) + 1;
		# Next gw only — never call apply_* (often undefined on live patched cfg)
		if ($(var(cap_tries){{s.int}}) < 8 && use_next_gw()) {{
			route(check_rate_cap);
			return;
		}}
		send_reply(403, "Rate Cap Exceeded");
		exit;
	}}
}}
'''
else:
    cap_route += '\treturn;\n}\n'

def replace_route(src, name, body):
    marker = f'route[{name}]'
    start = src.find(marker)
    if start < 0:
        # insert before route[relay]
        r = src.find('route[relay]')
        if r >= 0:
            return src[:r] + body.strip() + "\n\n" + src[r:]
        return src.rstrip() + "\n\n" + body.strip() + "\n"
    brace = src.find('{', start)
    depth = 0
    i = brace
    while i < len(src):
        if src[i] == '{':
            depth += 1
        elif src[i] == '}':
            depth -= 1
            if depth == 0:
                return src[:start] + body.strip() + "\n" + src[i+1:]
        i += 1
    raise SystemExit('unbalanced route')

text = replace_route(text, 'check_rate_cap', cap_route)

# Ensure route(check_rate_cap) after check_trunk_cid_len or apply_trunk_attrs
if 'route(check_rate_cap);' not in text:
    if 'route(check_trunk_cid_len);' in text:
        text = text.replace('route(check_trunk_cid_len);', 'route(check_trunk_cid_len);\n\troute(check_rate_cap);', 1)
    elif 'route(apply_trunk_attrs);' in text:
        text = text.replace('route(apply_trunk_attrs);', 'route(apply_trunk_attrs);\n\troute(check_rate_cap);', 1)

open(path, 'w', encoding='utf-8').write(text)
print('OK patched check_rate_cap (exec=%s)' % has_exec)
PY

opensips -c -f "$CFG" || { echo "FATAL opensips -c"; exit 1; }
systemctl restart opensips
sleep 2
systemctl is-active --quiet opensips || { journalctl -u opensips -n 40 --no-pager; exit 1; }

# Purge obviously expired location rows so portal stops showing stale "registered"
PW=""
[[ -f /etc/ownswitch/secrets/mysql_opensips_password ]] && PW="$(tr -d '\n' </etc/ownswitch/secrets/mysql_opensips_password)"
[[ -n "$PW" ]] || PW="opensips"
mysql -uopensips -p"$PW" opensips -e "DELETE FROM location WHERE expires IS NOT NULL AND expires <= UNIX_TIMESTAMP();" 2>/dev/null || true

echo
echo "################################################################"
echo " Done: registration expiry fix, Rate CAP, tariff bulk delete."
echo " SIP Users → CAP field; Trunks → CAP field."
echo " Tariffs / Provider Rates → Delete selected / Delete matching filter."
echo "################################################################"
