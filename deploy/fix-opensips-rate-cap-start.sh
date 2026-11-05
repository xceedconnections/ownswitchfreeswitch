#!/usr/bin/env bash
# Emergency: OpenSIPS down because check_rate_cap called undefined apply_ruri_strip.
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-opensips-rate-cap-start.sh | sudo bash
# Or:
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-opensips-rate-cap-start.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
cd "$SRC"
git fetch origin main
git reset --hard origin/main

chattr -i "$CFG" 2>/dev/null || true
cp -a "$CFG" "/var/backups/ownswitch-config/opensips.cfg.pre-capfix.$(date -u +%Y%m%dT%H%M%SZ)" 2>/dev/null \
  || { mkdir -p /var/backups/ownswitch-config; cp -a "$CFG" "/var/backups/ownswitch-config/opensips.cfg.pre-capfix.$(date -u +%Y%m%dT%H%M%SZ)"; }

PHP_BIN="$(command -v php || echo /usr/bin/php)"
CAP_SCRIPT=/opt/ownswitch/current/bin/dial-cap-check.php
MOD_DIR="$(dirname "$(find /usr/lib -type f -name tm.so -path '*opensips/modules*' 2>/dev/null | head -n1)")"
HAS_EXEC=0
[[ -f "${MOD_DIR}/exec.so" ]] && HAS_EXEC=1

python3 - "$CFG" "$HAS_EXEC" "$PHP_BIN" "$CAP_SCRIPT" <<'PY'
import sys
path, has_exec, php, script = sys.argv[1], sys.argv[2] == "1", sys.argv[3], sys.argv[4]
text = open(path, encoding="utf-8", errors="replace").read()

# Ensure exec module line if .so present
if has_exec and 'loadmodule "exec.so"' not in text:
    if 'loadmodule "drouting.so"' in text:
        text = text.replace('loadmodule "drouting.so"', 'loadmodule "drouting.so"\nloadmodule "exec.so"', 1)

# Safe CAP route: never call apply_ruri_strip / apply_sip_cid / apply_trunk_attrs
if has_exec:
    body = f'''route[check_rate_cap] {{
	if ($avp(dr_gw_attrs) == NULL) {{
		return;
	}}
	$var(tid) = $(avp(dr_gw_attrs){{param.value,tid}});
	if ($var(tid) == NULL || $(var(tid){{s.len}}) == 0) {{
		return;
	}}
	$var(cap_user) = $au;
	if ($var(cap_user) == NULL || $(var(cap_user){{s.len}}) == 0) {{
		$var(cap_user) = $fU;
	}}
	if (!exec("{php} {script} $var(cap_user) $rU $var(tid)")) {{
		xlog("L_WARN", "Rate Cap Exceeded user=$var(cap_user) dst=$rU trunk=$var(tid)\\n");
		if ($var(cap_tries) == NULL) {{
			$var(cap_tries) = 0;
		}}
		$var(cap_tries) = $(var(cap_tries){{s.int}}) + 1;
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
    body = '''route[check_rate_cap] {
	return;
}
'''

def replace_route(src: str, name: str, new_body: str) -> str:
    marker = f"route[{name}]"
    start = src.find(marker)
    if start < 0:
        # insert before failure_route or at EOF
        fr = src.find("failure_route[")
        if fr >= 0:
            return src[:fr] + new_body.strip() + "\n\n" + src[fr:]
        return src.rstrip() + "\n\n" + new_body.strip() + "\n"
    brace = src.find("{", start)
    depth = 0
    i = brace
    while i < len(src):
        if src[i] == "{":
            depth += 1
        elif src[i] == "}":
            depth -= 1
            if depth == 0:
                return src[:start] + new_body.strip() + "\n" + src[i + 1 :]
        i += 1
    raise SystemExit(f"unbalanced route[{name}]")

text = replace_route(text, "check_rate_cap", body)

# Strip any remaining route(apply_*) calls inside check_rate_cap only — already replaced whole route

# If main path never calls check_rate_cap, add after do_routing / apply block once
if "route(check_rate_cap);" not in text:
    for anchor in (
        "route(check_trunk_cid_len);",
        "route(apply_trunk_attrs);",
        "route(acc_cli_gw);",
    ):
        if anchor in text:
            text = text.replace(anchor, anchor + "\n\troute(check_rate_cap);", 1)
            break

open(path, "w", encoding="utf-8").write(text)
print("OK wrote safe check_rate_cap (exec=%s)" % has_exec)
PY

echo "======== opensips -c ========"
opensips -c -f "$CFG"

echo "======== restart ========"
systemctl stop opensips 2>/dev/null || true
pkill -9 opensips 2>/dev/null || true
sleep 1
systemctl reset-failed opensips 2>/dev/null || true
systemctl start opensips
sleep 2
systemctl --no-pager --full status opensips | head -n 20 || true

if systemctl is-active --quiet opensips; then
  echo
  echo "################################################################"
  echo " OpenSIPS is UP. Rate CAP route no longer calls apply_ruri_strip."
  echo "################################################################"
else
  echo "FATAL: still down. Paste: journalctl -u opensips -n 80 --no-pager"
  exit 1
fi
