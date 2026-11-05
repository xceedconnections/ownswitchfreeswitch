#!/usr/bin/env bash
# Fix: CDR customer/SIP attribution + billing; admin host metrics; trunk ASR/failed/long>12s.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-billing-dashboard-trunk-stats.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
ENV_FILE=/etc/ownswitch/ownswitch.env
cd "$SRC"
git fetch origin main
git reset --hard origin/main
chattr -i "$CFG" 2>/dev/null || true

# --- PHP-FPM open_basedir: allow /proc /sys / for host widgets ---
for f in /etc/php/*/fpm/pool.d/ownswitch.conf; do
  [[ -f "$f" ]] || continue
  if grep -q 'open_basedir' "$f"; then
    if ! grep -q ':/proc' "$f"; then
      sed -i 's|php_admin_value\[open_basedir\] = \(.*\)|php_admin_value[open_basedir] = \1:/proc:/sys:/|' "$f"
      # de-dupe if run twice
      sed -i 's|:/proc:/sys:/:/proc:/sys:/|:/proc:/sys:/|g' "$f"
      echo "Patched open_basedir in $f"
    fi
  fi
done
systemctl reload php*-fpm 2>/dev/null || systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true

# --- OpenSIPS: prefer $au (digest user) for ACC src attribution ---
python3 - "$CFG" <<'PY'
import re, sys
path = sys.argv[1]
text = open(path, encoding="utf-8", errors="replace").read()

# Ensure ACC extras include cli/gw
if "cli->cli" not in text and 'extra_fields' in text:
    text = re.sub(
        r'modparam\(\s*"acc"\s*,\s*"extra_fields"\s*,\s*"[^"]*"\s*\)',
        'modparam("acc", "extra_fields", "db: src->src_user; dst->dst_user; ip->src_ip; cli->cli; gw->gw")',
        text,
        count=1,
    )

au_block = '''\t$dlg_val(os_src) = $au;
\tif ($dlg_val(os_src) == NULL || $(dlg_val(os_src){s.len}) == 0) {
\t\t$dlg_val(os_src) = $fU;
\t}
\t$dlg_val(os_dst) = $rU;
\t$acc_extra(src) = $dlg_val(os_src);
\t$acc_extra(dst) = $rU;
\t$acc_extra(ip) = $si;'''

# Replace naive $dlg_val(os_src) = $fU; blocks
text2 = re.sub(
    r'\$dlg_val\(os_src\)\s*=\s*\$fU;\s*'
    r'\$dlg_val\(os_dst\)\s*=\s*\$rU;\s*'
    r'(?:\$acc_extra\(src\)\s*=\s*\$fU;\s*)?'
    r'(?:\$acc_extra\(dst\)\s*=\s*\$rU;\s*)?'
    r'(?:\$acc_extra\(ip\)\s*=\s*\$si;\s*)?',
    au_block + "\n\t",
    text,
    count=1,
)
if text2 == text and "$au" not in text:
    text2 = text.replace(
        "$dlg_val(os_src) = $fU;",
        "$dlg_val(os_src) = $au;\n\tif ($dlg_val(os_src) == NULL || $(dlg_val(os_src){s.len}) == 0) {\n\t\t$dlg_val(os_src) = $fU;\n\t}",
        1,
    )
text = text2

# Ensure acc_cli_gw exists and sets src from dlg_val
acc_cli = '''route[acc_cli_gw] {
\t$acc_extra(src) = $dlg_val(os_src);
\t$acc_extra(dst) = $dlg_val(os_dst);
\t$acc_extra(ip) = $si;
\t$acc_extra(cli) = $fU;
\t$acc_extra(gw) = $avp(___dr_gw_id);
\txlog("L_INFO", "ACC cli=$fU sip=$dlg_val(os_src) gw=$avp(___dr_gw_id)\\n");
}
'''

def replace_route(src, name, body):
    start = src.find(name)
    if start < 0:
        return None
    brace = src.find("{", start)
    depth = 0
    i = brace
    while i < len(src):
        if src[i] == "{":
            depth += 1
        elif src[i] == "}":
            depth -= 1
            if depth == 0:
                return src[:start] + body.rstrip() + "\n" + src[i+1:]
        i += 1
    raise SystemExit("unbalanced " + name)

if "route[acc_cli_gw]" in text:
    text = replace_route(text, "route[acc_cli_gw]", acc_cli)
else:
    if "route[relay]" in text:
        text = text.replace("route[relay]", acc_cli + "\nroute[relay]", 1)
    else:
        text += "\n" + acc_cli

if "route(acc_cli_gw)" not in text:
    text = re.sub(
        r'(route\(apply_trunk_attrs\);\s*)',
        r'\1\n\troute(acc_cli_gw);\n',
        text,
        count=2,
    )

# Strip quotes from display_name CID if apply_sip_cid present
if "re.subst,/[^0-9+]//g" not in text and "route[apply_sip_cid]" in text:
    text = text.replace(
        "$var(dn) = $fn;",
        "$var(dn) = $fn;\n\t\t\t$var(dn) = $(var(dn){re.subst,/[^0-9+]//g});",
        1,
    )

# Never let CID rewrite overwrite ACC src with presented CallerID digits
text = re.sub(
    r'\$acc_extra\(src\)\s*=\s*\$var\(user\);',
    '$acc_extra(cli) = $var(user);',
    text,
)

open(path, "w", encoding="utf-8").write(text)
print("OK opensips.cfg attribution")
PY

mysql --protocol=socket -uroot opensips <<'SQL' || true
ALTER TABLE acc ADD COLUMN cli VARCHAR(64) NULL;
ALTER TABLE acc ADD COLUMN gw VARCHAR(64) NULL;
ALTER TABLE missed_calls ADD COLUMN cli VARCHAR(64) NULL;
ALTER TABLE missed_calls ADD COLUMN gw VARCHAR(64) NULL;
SQL

opensips -c -f "$CFG"
systemctl restart opensips
sleep 2
systemctl is-active opensips

bash "$SRC/deploy/refresh-ui.sh"
CUR="$(readlink -f /opt/ownswitch/current)"

# Re-attribute + rate existing unattributed CDRs (customer / SIP / charges)
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/reattribute_cdrs.php" --limit=1000 || true

# Pull any new ACC rows
PDB=$(grep -E '^DB_DATABASE=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)
PDB="${PDB:-ownswitch}"
# Do NOT reset last_id — would duplicate. Just process pending queue.
systemctl restart ownswitch-worker 2>/dev/null || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/worker_cdr.php" --limit=500 || true

echo
echo "################################################################"
echo " Billing: SIP user from \$au; sole-SIP failsafe; reattribute run."
echo " Dashboard host: open_basedir + /proc; RAM/Disk/NIC should populate."
echo " Trunk edit: ASR, connected, failed, >12s long-call sort."
echo " Place a new answered call after re-register to verify live charge."
echo " If CDRs say unrated: assign a customer tariff/rate plan."
echo "################################################################"
