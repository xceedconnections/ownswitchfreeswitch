#!/usr/bin/env bash
# Login username|email, firewall ban logs+country, CDR media IP,
# trunk/SIP CallerID min/max (484), prepaid empty → 402, disabled SIP no ACC/CDR.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-login-firewall-cid-balance.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
ENV_FILE=/etc/ownswitch/ownswitch.env
APP=/opt/ownswitch/current
cd "$SRC"
git fetch origin main
git reset --hard origin/main

# Portal code + assets (must land before OpenSIPS patch)
bash "$SRC/deploy/refresh-ui.sh"
# Prove new release is live
LOGIN_VIEW="$(readlink -f /opt/ownswitch/current)/app/Views/auth/login.php"
grep -q 'Username or email' "$LOGIN_VIEW" \
  || { echo "FATAL: UI refresh did not deploy login.php with Username or email"; exit 1; }
grep -q 'cid_min_len' "$(readlink -f /opt/ownswitch/current)/app/Controllers/SipUserController.php" \
  || { echo "FATAL: SipUserController missing cid_min_len after refresh"; exit 1; }
grep -q 'GeoIp' "$(readlink -f /opt/ownswitch/current)/app/Support/GeoIp.php" \
  || { echo "FATAL: GeoIp.php missing after refresh"; exit 1; }
echo "OK portal release has login/CID/GeoIp"

# Fail2ban helper (ip-log for ban reason lines)
if [[ -f "$SRC/deploy/templates/fail2ban/ownswitch-fail2ban.sh" ]]; then
  install -m 0755 "$SRC/deploy/templates/fail2ban/ownswitch-fail2ban.sh" /usr/local/sbin/ownswitch-fail2ban
fi
if [[ -f "$SRC/deploy/templates/fail2ban/ownswitch-fail2ban.sudoers" ]]; then
  install -m 0440 "$SRC/deploy/templates/fail2ban/ownswitch-fail2ban.sudoers" /etc/sudoers.d/ownswitch-fail2ban
  visudo -cf /etc/sudoers.d/ownswitch-fail2ban >/dev/null
fi

# DB migration 027 (cid len + media_ip)
if [[ -f "$SRC/database/migrations/027_cid_len_media_ip.sql" ]]; then
  # shellcheck disable=SC1090
  set -a; [[ -f "$ENV_FILE" ]] && . "$ENV_FILE"; set +a
  DB_NAME="${DB_DATABASE:-ownswitch}"
  DB_USER="${DB_USERNAME:-ownswitch}"
  DB_PASS="${DB_PASSWORD:-}"
  if [[ -n "$DB_PASS" ]]; then
    mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SRC/database/migrations/027_cid_len_media_ip.sql" \
      && echo "OK migration 027" || echo "WARN migration 027"
  elif [[ -x "$SRC/bin/migrate.php" ]]; then
    sudo -u ownswitch env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$APP/bin/migrate.php" || true
  fi
fi

# Patch live opensips.cfg for 402 / CID length / load_credentials
chattr -i "$CFG" 2>/dev/null || true
cp -a "$CFG" "/var/backups/ownswitch-config/opensips.cfg.pre-cid-balance.$(date -u +%Y%m%dT%H%M%SZ)" 2>/dev/null \
  || { mkdir -p /var/backups/ownswitch-config; cp -a "$CFG" "/var/backups/ownswitch-config/opensips.cfg.pre-cid-balance.$(date -u +%Y%m%dT%H%M%SZ)"; }

python3 - "$CFG" <<'PY'
import re, sys
path = sys.argv[1]
text = open(path, encoding="utf-8", errors="replace").read()
orig = text

# Expand load_credentials for cid + allow_calls
cred_new = (
    'modparam("auth_db", "load_credentials", '
    '"$avp(os_media)=media_policy;$avp(os_dtmf)=dtmf_pass;$avp(os_cid_mode)=cid_mode;'
    '$avp(os_cid)=rpid;$avp(os_cid_min)=cid_min_len;$avp(os_cid_max)=cid_max_len;$avp(os_allow)=allow_calls")'
)
text = re.sub(
    r'modparam\(\s*"auth_db"\s*,\s*"load_credentials"\s*,\s*"[^"]*"\s*\)',
    cred_new,
    text,
    count=1,
)

pay_block = '''\t# Disabled SIP / prepaid empty balance → Payment Required (before ACC / dialog — no CDR)
\tif ($avp(os_allow) != NULL && $(avp(os_allow){s.int}) == 0) {
\t\txlog("L_WARN", "Payment Required user=$au from=$si\\n");
\t\tsend_reply(402, "Payment Required");
\t\texit;
\t}
'''

# Insert Payment Required after consume_credentials block if missing
if 'Payment Required' not in text:
    # Prefer after first consume_credentials();
    m = re.search(r'consume_credentials\(\);\s*\n\t\}', text)
    if m:
        insert_at = m.end()
        text = text[:insert_at] + "\n\n" + pay_block + text[insert_at:]
    else:
        text = text.replace(
            '@@OPENSIPS_ALIAS_LOOKUP@@',
            pay_block + '\n\t@@OPENSIPS_ALIAS_LOOKUP@@',
            1,
        )

# Ensure route calls after apply_sip_cid / apply_trunk_attrs
def ensure_after(hay: str, anchor: str, insert: str) -> str:
    if insert.strip() in hay:
        return hay
    # only first occurrence in main invite path (before failure_route)
    idx = hay.find('failure_route[carrier_failover]')
    head, tail = (hay[:idx], hay[idx:]) if idx >= 0 else (hay, '')
    if anchor in head and insert not in head:
        head = head.replace(anchor, anchor + "\n\t" + insert, 1)
    return head + tail

text = ensure_after(text, 'route(apply_sip_cid);', 'route(check_cid_len);')
text = ensure_after(text, 'route(apply_trunk_attrs);', 'route(check_trunk_cid_len);')

# failure_route next-gw: also check CID lengths
if 'failure_route[carrier_failover]' in text and 'route(check_cid_len);' not in text.split('failure_route[carrier_failover]', 1)[-1]:
    text = text.replace(
        'route(apply_sip_cid);\n\t\troute(apply_trunk_attrs);',
        'route(apply_sip_cid);\n\t\troute(check_cid_len);\n\t\troute(apply_trunk_attrs);\n\t\troute(check_trunk_cid_len);',
        1,
    )

cid_routes = r'''
# SIP-user CallerID digit length (from subscriber cid_min_len / cid_max_len)
route[check_cid_len] {
	$var(clid) = $(fU{re.subst,/[^0-9]//g});
	$var(clen) = $(var(clid){s.len});
	if ($avp(os_cid_min) != NULL && $(avp(os_cid_min){s.int}) > 0 && $var(clen) < $(avp(os_cid_min){s.int})) {
		xlog("L_WARN", "Wrong CallerID Length min user=$au len=$var(clen)\n");
		send_reply(484, "Wrong CallerID Length");
		exit;
	}
	if ($avp(os_cid_max) != NULL && $(avp(os_cid_max){s.int}) > 0 && $var(clen) > $(avp(os_cid_max){s.int})) {
		xlog("L_WARN", "Wrong CallerID Length max user=$au len=$var(clen)\n");
		send_reply(484, "Wrong CallerID Length");
		exit;
	}
}

# Trunk attrs cidmin=/cidmax= after From rewrite
route[check_trunk_cid_len] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(attrs) = $avp(dr_gw_attrs);
	$var(cmin) = $(var(attrs){param.value,cidmin});
	$var(cmax) = $(var(attrs){param.value,cidmax});
	$var(clid) = $(fU{re.subst,/[^0-9]//g});
	$var(clen) = $(var(clid){s.len});
	if ($var(cmin) != NULL && $(var(cmin){s.int}) > 0 && $var(clen) < $(var(cmin){s.int})) {
		xlog("L_WARN", "Wrong CallerID Length trunk min len=$var(clen)\n");
		send_reply(484, "Wrong CallerID Length");
		exit;
	}
	if ($var(cmax) != NULL && $(var(cmax){s.int}) > 0 && $var(clen) > $(var(cmax){s.int})) {
		xlog("L_WARN", "Wrong CallerID Length trunk max len=$var(clen)\n");
		send_reply(484, "Wrong CallerID Length");
		exit;
	}
}
'''

def replace_or_append_route(src: str, name: str, body: str) -> str:
    marker = f'route[{name}]'
    start = src.find(marker)
    if start < 0:
        # insert before route[relay] if present
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
                return src[:start] + body.strip() + "\n" + src[i + 1:]
        i += 1
    raise SystemExit(f'unbalanced {name}')

# Split cid_routes into two route bodies
parts = re.split(r'(?=route\[check_trunk_cid_len\])', cid_routes.strip())
text = replace_or_append_route(text, 'check_cid_len', parts[0].strip() + "\n")
if len(parts) > 1:
    text = replace_or_append_route(text, 'check_trunk_cid_len', parts[1].strip() + "\n")

if text != orig:
    open(path, 'w', encoding='utf-8').write(text)
    print('OK patched opensips.cfg')
else:
    print('OK opensips.cfg already up to date')
PY

# Ensure OpenSIPS subscriber columns
PW=""
[[ -f /etc/ownswitch/secrets/mysql_opensips_password ]] && PW="$(tr -d '\n' </etc/ownswitch/secrets/mysql_opensips_password)"
[[ -n "$PW" ]] || PW="opensips"
mysql -uopensips -p"$PW" opensips <<'SQL' || true
SET @db := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='subscriber' AND COLUMN_NAME='cid_min_len')=0,
  'ALTER TABLE subscriber ADD COLUMN cid_min_len INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='subscriber' AND COLUMN_NAME='cid_max_len')=0,
  'ALTER TABLE subscriber ADD COLUMN cid_max_len INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='subscriber' AND COLUMN_NAME='allow_calls')=0,
  'ALTER TABLE subscriber ADD COLUMN allow_calls TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SQL

opensips -c -f "$CFG" || { echo "FATAL: opensips -c failed"; exit 1; }
systemctl restart opensips
sleep 2
systemctl is-active --quiet opensips || { echo "FATAL: opensips not active"; journalctl -u opensips -n 40 --no-pager; exit 1; }

# Re-provision SIP users (allow_calls + cid) and trunks (cidmin/cidmax attrs)
if [[ -f "$APP/bin/sync-opensips-sip-users.php" ]]; then
  sudo -u ownswitch env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$APP/bin/sync-opensips-sip-users.php" || true
fi
if [[ -f "$APP/bin/sync-opensips-trunks.php" ]]; then
  sudo -u ownswitch env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$APP/bin/sync-opensips-trunks.php" || true
fi

echo
echo "################################################################"
echo " Deployed: login username|email, firewall geo+ban logs,"
echo " CDR Media IP, CID min/max → 484, no balance → 402,"
echo " disabled SIP blocked (no ACC/CDR)."
echo "################################################################"
