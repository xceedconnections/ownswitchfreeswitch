#!/usr/bin/env bash
# Calls Online live dialogs; trunk LCR/random; failover SIP codes; CDR delete + SIP trace.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-calls-online-failover-cdr.sh
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

# Patch failure_route — only call route() names that already exist in live cfg
python3 - "$CFG" <<'PY'
import re, sys
path = sys.argv[1]
text = open(path, encoding="utf-8", errors="replace").read()
pat = "408|480|486|503|500|502|504"
defined = set(re.findall(r'(?:^|\n)\s*route\[([^\]]+)\]', text))
calls = []
for name in ("apply_ruri_strip", "apply_sip_cid", "apply_trunk_attrs", "acc_cli_gw"):
    if name in defined:
        calls.append(f"\t\troute({name});")
call_block = ("\n".join(calls) + "\n") if calls else ""
block = f'''failure_route[carrier_failover] {{
\tif (t_was_cancelled()) {{
\t\texit;
\t}}
\tif (!t_check_status("{pat}")) {{
\t\txlog("L_INFO", "failover skip: reply $T_reply_code not in failover list\\n");
\t\trtpengine_delete();
\t\texit;
\t}}
\txlog("L_WARN", "failover after code=$T_reply_code trying next trunk\\n");
\tif (use_next_gw()) {{
{call_block}\t\tt_on_failure("carrier_failover");
\t\tt_relay();
\t\texit;
\t}}
\trtpengine_delete();
}}
'''
start = text.find('failure_route[carrier_failover]')
if start >= 0:
    brace = text.find('{', start)
    depth = 0
    i = brace
    while i < len(text):
        if text[i] == '{': depth += 1
        elif text[i] == '}':
            depth -= 1
            if depth == 0:
                text = text[:start] + block + text[i+1:]
                break
        i += 1
else:
    text += "\n" + block

if 'gw_id_avp' not in text:
    text = re.sub(
        r'(modparam\(\s*"drouting"\s*,\s*"db_url"[^\n]*\n)',
        r'\1modparam("drouting", "gw_id_avp", "$avp(dr_gw_id)")\n',
        text, count=1,
    )

open(path, "w", encoding="utf-8").write(text)
print("OK failover_route calls=", calls or ["(none — use_next_gw + t_relay only)"])
PY

opensips -c -f "$CFG"
systemctl restart opensips
sleep 1

bash "$SRC/deploy/refresh-ui.sh"
CUR="$(readlink -f /opt/ownswitch/current)"

runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/migrate.php" || true

# Push failover codes from portal settings + re-sync trunks/routes (LCR/random sort)
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php -r '
require "'"$CUR"'/app/bootstrap.php";
$pdo = Ownswitch\App::db();
$r = (new Ownswitch\Services\Routing\FailoverSipCodesSync($pdo))->sync();
echo "failover sync: ".json_encode($r)."\n";
$prov = Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner::fromConfig();
if ($prov->enabled()) {
  $w = (new Ownswitch\Services\Routing\OpensipsRoutingSync($pdo, $prov))->syncAll();
  echo "route sync trunks={$w["trunks"]} rules={$w["rules"]}\n";
}
' || true

opensips -c -f "$CFG" && systemctl restart opensips
systemctl restart ownswitch-worker 2>/dev/null || true

echo
echo "################################################################"
echo " Calls Online: dlg_list + dialog table; Trying/Ringing/Connected."
echo " Trunk: LCR reorders waterfall on save; Random uses sort_alg=R."
echo " Failover SIP codes from Settings gate use_next_gw()."
echo " CDR: SIP trace icon, code labels, delete selected/filtered/all,"
echo "      CSV + Excel export."
echo "################################################################"
