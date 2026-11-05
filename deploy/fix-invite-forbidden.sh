#!/usr/bin/env bash
# Eyebeam INVITE → "Forbidden" (SIP 403) after REGISTER works.
# Usual causes: proxy_authorize realm mismatch ($fd vs Domain), or leftover billing.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-invite-forbidden.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
cd "$SRC"
git fetch origin main
git reset --hard origin/main
chattr -i "$CFG" 2>/dev/null || true

echo "======== INVITE / auth / billing (before) ========"
grep -nE 'proxy_authorize|proxy_challenge|cgrates_auth|Billing|do_routing|send_reply\(403' "$CFG" | head -n 40 || true

python3 - "$CFG" <<'PY'
import re, sys
path = sys.argv[1]
text = open(path, encoding="utf-8", errors="replace").read()
orig = text

# 1) INVITE digest: empty realm (Magnus-style) — avoids $fd≠Domain → 403 Forbidden
#    Match common proxy_authorize("$fd"...)/proxy_challenge("$fd"...) pairs
text = re.sub(
    r'proxy_authorize\(\s*"\$fd"\s*,\s*"subscriber"\s*\)',
    'proxy_authorize("", "subscriber")',
    text,
)
text = re.sub(
    r"proxy_authorize\(\s*'\$fd'\s*,\s*'subscriber'\s*\)",
    'proxy_authorize("", "subscriber")',
    text,
)
text = re.sub(
    r'proxy_challenge\(\s*"\$fd"\s*,\s*"auth"\s*\)',
    'proxy_challenge("", "auth")',
    text,
)
text = re.sub(
    r"proxy_challenge\(\s*'\$fd'\s*,\s*'auth'\s*\)",
    'proxy_challenge("", "auth")',
    text,
)

# 2) Strip ALL cgrates_auth / cgrates_acc from call path (billing off until provisioned)
#    Replace whole if (!cgrates_auth()) { ... } else { ... } with a log line
text = re.sub(
    r'if\s*\(\s*!cgrates_auth\s*\(\s*\)\s*\)\s*\{.*?\}\s*else\s*\{.*?\}',
    'xlog("L_INFO", "OWNSWITCH: cgrates bypassed for $fU -> $rU\\n");',
    text,
    count=1,
    flags=re.S,
)
# Or fail-open / fail-closed single if without else
text = re.sub(
    r'if\s*\(\s*!cgrates_auth\s*\(\s*\)\s*\)\s*\{.*?\}\s*(?:cgrates_acc\s*\([^)]*\)\s*;)?',
    'xlog("L_INFO", "OWNSWITCH: cgrates bypassed for $fU -> $rU\\n");',
    text,
    count=1,
    flags=re.S,
)
text = re.sub(r'[ \t]*cgrates_acc\s*\([^)]*\)\s*;\s*\n?', '', text)
text = re.sub(
    r'[ \t]*send_reply\(\s*403\s*,\s*"Billing Authorization Failed"\s*\)\s*;\s*\n?',
    '',
    text,
)
text = re.sub(
    r'[ \t]*acc_db_request\("403 Billing Authorization Failed",\s*"acc"\);\s*\n?',
    '',
    text,
)

# 3) do_routing group id integer
text = re.sub(
    r'do_routing\(\s*"1"\s*(?:,\s*,\s*,\s*,\s*\$avp\(dr_gw_attrs\))?\s*\)',
    'do_routing(1, , , , $avp(dr_gw_attrs))',
    text,
)

# 4) Log every initial INVITE decision point (insert once after consume_credentials if missing)
if "OWNSWITCH INVITE" not in text:
    text = text.replace(
        "consume_credentials();",
        'consume_credentials();\n'
        '\t\txlog("L_WARN", "OWNSWITCH INVITE $fU -> $rU from $si auth=ok\\n");',
        1,
    )

# 5) Clearer 503 when no trunk
text = text.replace(
    'send_reply(503, "No Route");',
    'xlog("L_WARN", "OWNSWITCH no drouting for $rU\\n");\n'
    '\t\t\tsend_reply(503, "No Route");',
)

open(path, "w", encoding="utf-8").write(text)
print("wrote" if text != orig else "unchanged", path)
if "Billing Authorization Failed" in text:
    print("WARN: billing phrase still in cfg")
if 'proxy_authorize("$fd"' in text:
    print("WARN: proxy_authorize still uses $fd")
else:
    print("OK: proxy_authorize uses empty realm")
PY

echo "======== INVITE section (after) ========"
grep -nE 'proxy_authorize|proxy_challenge|cgrates|OWNSWITCH|do_routing|send_reply\(403|No Route' "$CFG" | head -n 40 || true

opensips -c -f "$CFG"
systemctl restart opensips
sleep 2
systemctl is-active opensips || { journalctl -u opensips -n 40 --no-pager; exit 1; }

echo "======== gateways / rules ========"
mysql --protocol=socket -uroot opensips -e \
  "SELECT COUNT(*) gateways FROM dr_gateways; SELECT COUNT(*) rules FROM dr_rules;
   SELECT id,gwid,address FROM dr_gateways LIMIT 10;
   SELECT ruleid,groupid,prefix,gwlist FROM dr_rules LIMIT 10;" 2>/dev/null || true

GW=$(mysql --protocol=socket -uroot -N opensips -e "SELECT COUNT(*) FROM dr_gateways" 2>/dev/null || echo 0)

echo
echo "################################################################"
echo " 1) Eyebeam: Unregister → Register"
echo " 2) Place one call"
echo " 3) Run:  journalctl -u opensips -n 80 --no-pager | grep -iE 'OWNSWITCH|INVITE|403|503|auth|route'"
echo " Gateways now: ${GW}"
if [[ "$GW" == "0" ]]; then
  echo " *** NO TRUNKS — PSTN will get 503 No Route. Add Trunks+Routes in portal, then:"
  echo "   sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env php \$(readlink -f /opt/ownswitch/current)/bin/sync-opensips-trunks.php"
fi
echo "################################################################"
