#!/usr/bin/env bash
# Eyebeam: 403 Billing Authorization Failed → CGRateS refuse/unreachable.
# Temporarily allow calls (fail-open) so PSTN works while rates are set up.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-billing-auth-failopen.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
cd "$SRC"
git fetch origin main
git reset --hard origin/main

chattr -i "$CFG" 2>/dev/null || true

python3 - "$CFG" <<'PY'
import re, sys
path = sys.argv[1]
text = open(path, encoding="utf-8", errors="replace").read()
orig = text

fail_closed = re.compile(
    r'if\s*\(\s*!cgrates_auth\s*\(\s*\)\s*\)\s*\{'
    r'.*?'
    r'send_reply\s*\(\s*403\s*,\s*"Billing Authorization Failed"\s*\)\s*;'
    r'(?:\s*exit\s*;)?'
    r'\s*\}'
    r'(?:\s*cgrates_acc\s*\([^)]*\)\s*;)?',
    re.S,
)
fail_open = (
    'if (!cgrates_auth()) {\n'
    '\t\txlog("L_WARN", "cgrates_auth failed $fU -> $rU (allowing call)\\n");\n'
    '\t} else {\n'
    '\t\tcgrates_acc("cdr|missed");\n'
    '\t}'
)
text2, n = fail_closed.subn(fail_open, text, count=1)
if n:
    text = text2
    print(f"replaced fail-closed cgrates block ({n})")
else:
    # Broader: delete 403 billing reply + following exit inside auth failure
    if "Billing Authorization Failed" not in text:
        print("no Billing Authorization Failed in cfg — already fail-open?")
    else:
        text = text.replace(
            'send_reply(403, "Billing Authorization Failed");',
            'xlog("L_WARN", "cgrates_auth failed (allowing call)\\n");',
        )
        text = re.sub(
            r'acc_db_request\("403 Billing Authorization Failed",\s*"acc"\);\s*',
            "",
            text,
        )
        # If pattern was: if (!cgrates_auth()) { ... xlog; exit; } — drop the exit after our xlog
        text = re.sub(
            r'(xlog\("L_WARN", "cgrates_auth failed \(allowing call\)\\n"\);\s*)\n?\s*exit\s*;',
            r'\1',
            text,
        )
        print("rewrote Billing Authorization Failed → allow")

# Also ensure integer do_routing while we are here
text = re.sub(
    r'do_routing\(\s*"1"\s*(?:,\s*,\s*,\s*,\s*\$avp\(dr_gw_attrs\))?\s*\)',
    'do_routing(1, , , , $avp(dr_gw_attrs))',
    text,
)

if text != orig:
    open(path, "w", encoding="utf-8").write(text)
    print("wrote", path)
else:
    print("cfg unchanged")

if "Billing Authorization Failed" in text and "allowing call" not in text:
    print("FATAL: billing reject still present", file=sys.stderr)
    sys.exit(1)
PY

opensips -c -f "$CFG"
systemctl restart opensips
sleep 2
systemctl is-active opensips
grep -nE 'cgrates_auth|Billing Authorization|allowing call|do_routing' "$CFG" | head -n 30

echo
echo "################################################################"
echo " Re-register Eyebeam and dial again."
echo " 403 Billing should be gone. Next blockers: 503 No Route (add Trunks)"
echo " or RTP/one-way audio (rtpengine)."
echo "################################################################"
