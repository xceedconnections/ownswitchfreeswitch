#!/usr/bin/env bash
# Repair OpenSIPS start + Eyebeam REGISTER after a bad cfg patch.
# Typical cause: xlog() with punctuation/em-dash immediately after $si / $rU / $T_reply_code
# (OpenSIPS refuses to start; `opensips -c` can still print listeners and look "OK").
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-opensips-start-now.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
ENV_FILE=/etc/ownswitch/ownswitch.env
cd "$SRC"
git fetch origin main
git reset --hard origin/main

echo "======== journal (last start errors) ========"
journalctl -u opensips -n 60 --no-pager 2>/dev/null | tail -n 60 || true

chattr -i "$CFG" 2>/dev/null || true
cp -a "$CFG" "/var/backups/ownswitch-config/opensips.cfg.pre-startfix.$(date -u +%Y%m%dT%H%M%SZ)" 2>/dev/null \
  || mkdir -p /var/backups/ownswitch-config && cp -a "$CFG" "/var/backups/ownswitch-config/opensips.cfg.pre-startfix.$(date -u +%Y%m%dT%H%M%SZ)"

python3 - "$CFG" <<'PY'
import re, sys
path = sys.argv[1]
text = open(path, encoding="utf-8", errors="replace").read()
orig = text

# ASCII-only in xlog strings: replace em/en dashes and arrows
text = (
    text.replace("\u2014", "-")
        .replace("\u2013", "-")
        .replace("\u2192", "to")
        .replace("→", "to")
)

# Known-bad / fragile auth fail lines → safe forms (no punct right after $pv)
replacements = [
    (
        r'xlog\(\s*"L_WARN"\s*,\s*"INVITE auth failed[^"]*"\s*\)\s*;',
        'xlog("L_WARN", "INVITE auth failed user=$fU from=$si\\n");',
    ),
    (
        r'xlog\(\s*"L_WARN"\s*,\s*"REGISTER auth failed[^"]*"\s*\)\s*;',
        'xlog("L_WARN", "REGISTER auth failed user=$tU realm=$td from=$si\\n");',
    ),
    (
        r'xlog\(\s*"L_WARN"\s*,\s*"failover after[^"]*"\s*\)\s*;',
        'xlog("L_WARN", "failover after code=$T_reply_code trying next trunk\\n");',
    ),
    (
        r'xlog\(\s*"L_WARN"\s*,\s*"carrier ACL but no AOR[^"]*"\s*\)\s*;',
        'xlog("L_WARN", "carrier ACL but no AOR for $rU - customer digest path\\n");',
    ),
    (
        r'xlog\(\s*"L_WARN"\s*,\s*"no route for[^"]*"\s*\)\s*;',
        'xlog("L_WARN", "no route for $rU from $fU - sync trunks/routes\\n");',
    ),
]
for pat, rep in replacements:
    text2, n = re.subn(pat, rep, text, count=1)
    if n:
        text = text2
        print(f"fixed: {rep[:48]}...")

# Ensure INVITE auth-fail log exists before proxy_challenge
if "INVITE auth failed" not in text and 'proxy_authorize("", "subscriber")' in text:
    text2, n = re.subn(
        r'(if\s*\(\s*!proxy_authorize\(\s*""\s*,\s*"subscriber"\s*\)\s*\)\s*\{)\s*\n(\s*)(proxy_challenge)',
        r'\1\n\2xlog("L_WARN", "INVITE auth failed user=$fU from=$si\\n");\n\2\3',
        text,
        count=1,
    )
    if n:
        text = text2
        print("inserted INVITE auth failed xlog")

def defined_routes(src: str) -> set[str]:
    return set(re.findall(r'(?:^|\n)\s*route\[([^\]]+)\]', src))

def called_routes(src: str) -> set[str]:
    return set(re.findall(r'\broute\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*\)', src))

def replace_named_block(src: str, header: str, new_block: str) -> str:
    start = src.find(header)
    if start < 0:
        return src + "\n" + new_block + "\n"
    brace = src.find("{", start)
    if brace < 0:
        return src
    depth = 0
    i = brace
    while i < len(src):
        if src[i] == "{":
            depth += 1
        elif src[i] == "}":
            depth -= 1
            if depth == 0:
                return src[:start] + new_block + src[i + 1 :]
        i += 1
    return src

# --- Emergency: live cfg often lacks apply_* routes that failover patches call.
# 1) Delete broken mid-file stubs from earlier fix attempts
text = re.sub(
    r'\n*route\[(?:apply_ruri_strip|apply_sip_cid|apply_trunk_attrs|acc_cli_gw|apply_media)\]\s*\{[^}]*stub[^}]*\}\n*',
    "\n",
    text,
    flags=re.I,
)

# 2) Drop route() calls to names that are not defined (anywhere in cfg)
defined = defined_routes(text)
for name in ("apply_ruri_strip", "apply_sip_cid", "apply_trunk_attrs", "acc_cli_gw", "apply_media"):
    if name not in defined:
        text2, n = re.subn(rf'(?m)^\s*route\(\s*{name}\s*\)\s*;\s*\n', "", text)
        if n:
            text = text2
            print(f"removed {n}x route({name}) — not defined")

# 3) Pull failure_route out if present, then append a clean top-level copy at EOF
pat_codes = "408|480|486|503|500|502|504"
m = re.search(r't_check_status\("([^"]+)"\)', text)
if m and "|" in m.group(1) and "@@" not in m.group(1):
    pat_codes = m.group(1)

defined = defined_routes(text)
calls = []
for name in ("apply_ruri_strip", "apply_sip_cid", "apply_trunk_attrs", "acc_cli_gw"):
    if name in defined:
        calls.append(f"\t\troute({name});")
call_block = ("\n".join(calls) + "\n") if calls else ""
failover = f'''failure_route[carrier_failover] {{
	if (t_was_cancelled()) {{
		exit;
	}}
	if (!t_check_status("{pat_codes}")) {{
		xlog("L_INFO", "failover skip: reply $T_reply_code not in failover list\\n");
		rtpengine_delete();
		exit;
	}}
	xlog("L_WARN", "failover after code=$T_reply_code trying next trunk\\n");
	if (use_next_gw()) {{
{call_block}		t_on_failure("carrier_failover");
		t_relay();
		exit;
	}}
	rtpengine_delete();
}}
'''

# Remove every existing carrier_failover block (may sit illegally inside route{})
while "failure_route[carrier_failover]" in text:
    start = text.find("failure_route[carrier_failover]")
    brace = text.find("{", start)
    if brace < 0:
        break
    depth = 0
    i = brace
    end = -1
    while i < len(text):
        if text[i] == "{":
            depth += 1
        elif text[i] == "}":
            depth -= 1
            if depth == 0:
                end = i + 1
                break
        i += 1
    if end < 0:
        break
    text = text[:start] + text[end:]
    print("removed embedded failure_route[carrier_failover]")

text = text.rstrip() + "\n\n" + failover + "\n"
print("appended clean failure_route[carrier_failover] at EOF calls=", calls or ["(t_relay only)"])

if text != orig:
    open(path, "w", encoding="utf-8").write(text)
    print("OK wrote sanitized opensips.cfg")
else:
    print("OK no textual changes needed")
PY

# Update fail2ban filter to match new log wording
if [[ -f "$SRC/deploy/templates/fail2ban/opensips-auth.conf" ]]; then
  install -m 0644 "$SRC/deploy/templates/fail2ban/opensips-auth.conf" /etc/fail2ban/filter.d/opensips-auth.conf
fi

echo "======== opensips -c ========"
if ! opensips -c -f "$CFG"; then
  echo "FATAL: config still invalid — see errors above"
  exit 1
fi

echo "======== hard restart ========"
systemctl stop opensips 2>/dev/null || true
# Drop zombies holding :5060
pkill -9 opensips 2>/dev/null || true
sleep 1
systemctl reset-failed opensips 2>/dev/null || true
systemctl start opensips
sleep 2
systemctl --no-pager --full status opensips | head -n 25 || true

echo "======== UDP/TCP 5060 ========"
ss -ulnp | grep ':5060' || echo "FAIL: no UDP 5060 listener"
ss -tlnp | grep ':5060' || echo "WARN: no TCP 5060"

# Keep host FW open for softphones
ufw allow 5060/udp comment 'sip udp' 2>/dev/null || true
ufw allow 5060/tcp comment 'sip tcp' 2>/dev/null || true

# Unban everything on SIP jail in case Eyebeam IP got blocked during tests
if command -v fail2ban-client >/dev/null 2>&1; then
  fail2ban-client reload 2>/dev/null || systemctl restart fail2ban 2>/dev/null || true
  fail2ban-client set opensips-auth unbanip --all 2>/dev/null || true
fi

# Re-sync SIP users → subscriber so password auth works
bash "$SRC/deploy/refresh-ui.sh" || true
CUR="$(readlink -f /opt/ownswitch/current)"
if [[ -f "$CUR/bin/sync-opensips-sip-users.php" ]]; then
  runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true
fi

echo
echo "== Recent REGISTER lines =="
journalctl -u opensips --no-pager -n 30 2>/dev/null | grep -iE 'REGISTER|auth failed|OK|ERROR|CRITICAL' | tail -n 20 || true

if systemctl is-active --quiet opensips; then
  echo
  echo "################################################################"
  echo " OpenSIPS is UP. Re-register Eyebeam now:"
  echo "   Domain = voip.xceedconnections.com   Port=5060   Transport=UDP"
  echo " If still failing, run:"
  echo "   bash /opt/ownswitch-src/deploy/fix-softphone-register-magnus.sh"
  echo "################################################################"
else
  echo
  echo "FATAL: opensips still not active. Paste:"
  echo "  journalctl -u opensips -n 80 --no-pager"
  exit 1
fi
