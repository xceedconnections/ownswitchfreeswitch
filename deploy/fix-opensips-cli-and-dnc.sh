#!/usr/bin/env bash
# Apply BOTH Softphone Display Name → CDR CallerID AND DNC block, without one undoing the other.
#
# Order:
#   1) REGISTER-safe column ensure
#   2) CLI/CDR (os_cli) routes + ACC field fixes
#   3) DNC routes + outbound pins
#   4) Re-assert CLI ACC (so DNC pin/rewrite cannot leave cli=$fU)
#   5) Sync DNC cache + trunks + SIP users (cid_mode)
#
# Usage: sudo bash /opt/ownswitch-src/deploy/fix-opensips-cli-and-dnc.sh
set -Eeuo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
CFG="${1:-/etc/opensips/opensips.cfg}"

echo "======== Combined CLI + DNC fix ========"
echo "SRC=$SRC  CFG=$CFG"

bash "$SRC/deploy/fix-opensips-cli-cdr.sh" "$CFG"
bash "$SRC/deploy/fix-opensips-dnc.sh" "$CFG"

# --- Epilogue: force CLI ACC semantics again (DNC must not leave cli=$fU) ---
BAK="${CFG}.bak-cli-dnc-epilogue-$(date +%Y%m%d%H%M%S)"
cp -a "$CFG" "$BAK"
python3 - "$CFG" <<'PY'
import re, sys
cfg = sys.argv[1]
text = open(cfg, encoding="utf-8", errors="replace").read()
changed = False

# 1) Any leftover cli=$fU → os_cli (multiline — OpenSIPS-safe)
CLI_BLOCK = (
    "if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) {\n"
    "\t\t$acc_extra(cli) = $dlg_val(os_cli);\n"
    "\t} else {\n"
    "\t\t$acc_extra(cli) = $fU;\n"
    "\t}"
)
# Also flatten any prior one-line if/else back to multiline
text2 = re.sub(
    r'if \(\$dlg_val\(os_cli\) != NULL && \$\(dlg_val\(os_cli\)\{s\.len\}\) > 0\) \{ \$acc_extra\(cli\) = \$dlg_val\(os_cli\); \} else \{ \$acc_extra\(cli\) = \$fU; \}',
    CLI_BLOCK,
    text,
)
if text2 != text:
    text = text2
    changed = True
    print("epilogue: normalized one-line cli if/else → multiline")
if "$acc_extra(cli) = $fU;" in text:
    text = text.replace("$acc_extra(cli) = $fU;", CLI_BLOCK)
    changed = True
    print("epilogue: rewrote remaining acc_extra(cli)=$fU")

# 2) Ensure apply_sip_cid sets os_cli + acc_extra(cli) (inject after display_name success xlog if missing)
if "os_cli=$dlg_val(os_cli)" not in text and 'SIP CID mode=$avp(os_cid_mode)' in text:
    text = text.replace(
        'xlog("L_INFO", "SIP CID mode=$avp(os_cid_mode)',
        '$dlg_val(os_cli) = $var(user);\n\t$acc_extra(cli) = $var(user);\n\txlog("L_INFO", "SIP CID mode=$avp(os_cid_mode)',
        1,
    )
    changed = True
    print("epilogue: injected os_cli into SIP CID xlog path")

# 3) Pin DNC before acc_cli_gw / before-relay (CLI-safe anchors)
for anchor in (
    "route(acc_cli_gw);",
    'xlog("L_INFO", "OWNSWITCH acc_extra before relay',
    'xlog("L_INFO", "ACC cli=',
):
    if anchor in text and "route[check_dnc]" in text:
        pinned = "route(check_dnc);\n\t" + anchor
        if pinned not in text:
            text = text.replace(anchor, pinned, 1)
            changed = True
            print("epilogue: pinned check_dnc before", anchor[:40])
        break

# 4) before-relay must set src=os_src and cli=os_cli
if "OWNSWITCH acc_extra before relay" in text:
    # Fix classic broken pattern
    text2, n = re.subn(
        r'\$acc_extra\(src\)\s*=\s*\$fU;',
        '$acc_extra(src) = $dlg_val(os_src);',
        text,
    )
    if n:
        text = text2
        changed = True
        print(f"epilogue: before-relay src=$fU → os_src ({n})")
    if "$acc_extra(cli) = $dlg_val(os_cli)" not in text.split("OWNSWITCH acc_extra before relay")[0][-400:]:
        text = text.replace(
            'xlog("L_INFO", "OWNSWITCH acc_extra before relay',
            'if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) { $acc_extra(cli) = $dlg_val(os_cli); }\n'
            '\t$acc_extra(src) = $dlg_val(os_src);\n'
            '\txlog("L_INFO", "OWNSWITCH acc_extra before relay',
            1,
        )
        changed = True
        print("epilogue: injected os_cli before custom before-relay xlog")

if 'is_method("REGISTER")' not in text and "is_method('REGISTER')" not in text:
    raise SystemExit("FATAL: REGISTER missing after epilogue")

if changed:
    open(cfg, "w", encoding="utf-8").write(text)
    print("epilogue wrote", cfg)
else:
    print("epilogue: no further changes")
PY

if ! opensips -c -f "$CFG"; then
  echo "ERROR: opensips -c failed after epilogue — restoring $BAK" >&2
  cp -a "$BAK" "$CFG"
  exit 1
fi
systemctl restart opensips
sleep 1
if ! systemctl is-active --quiet opensips; then
  echo "FATAL: opensips down — restoring $BAK" >&2
  cp -a "$BAK" "$CFG"
  systemctl restart opensips || true
  exit 1
fi
fail2ban-client unban --all >/dev/null 2>&1 || true

# Always finish with REGISTER repair (columns + load_credentials + unban)
echo
echo "==== REGISTER safety pass ===="
bash "$SRC/deploy/fix-opensips-register.sh" "$CFG"

echo
echo "==== Sync portal → OpenSIPS ===="
sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
  php /opt/ownswitch/current/bin/sync-dnc-cache.php || true
sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
  php /opt/ownswitch/current/bin/sync-opensips-trunks.php || true
sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
  php /opt/ownswitch/current/bin/sync-opensips-sip-users.php || true

echo
echo "==== Verify cfg markers ===="
echo "DNC calls:"; grep -nE 'route\(check_dnc\)|route\(check_sip_dnc\)' "$CFG" | head -n 15 || echo "(none)"
echo "CLI markers:"; grep -nE 'os_cli|display_name|ACC cli=' "$CFG" | head -n 20 || echo "(none)"
echo "subscriber:"; mysql -Nuroot opensips -e "SELECT username, cid_mode, dnc_group_id FROM subscriber LIMIT 20" 2>/dev/null || true
echo "gateways dncid:"; mysql -Nuroot opensips -e "SELECT gwid, attrs FROM dr_gateways" 2>/dev/null || true
echo
echo "opensips: $(systemctl is-active opensips)"
ss -ulnp 2>/dev/null | grep 5060 || echo "WARN: no UDP 5060"
echo
echo "Re-register Eyebeam, then test:"
echo "  journalctl -u opensips -f | grep -E 'REGISTER|SIP CID|ACC cli|DNC'"
echo "  1) REGISTER OK"
echo "  2) Normal call → ACC cli=<display digits> sip=<user>"
echo "  3) DNC number  → DNC hit … 403"
echo "Done."
