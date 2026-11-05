#!/usr/bin/env bash
# Capture trunk gwid on ACC → carrier cost/margin on CDRs; show balance on /users.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-carrier-cost-and-user-balance.sh
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

python3 - "$CFG" <<'PY'
import re, sys
path = sys.argv[1]
text = open(path, encoding="utf-8", errors="replace").read()

# Enable gw_id_avp (disabled by default on OpenSIPS 3.6)
if 'gw_id_avp' not in text:
    text = re.sub(
        r'(modparam\(\s*"drouting"\s*,\s*"db_url"[^\n]*\n)',
        r'\1modparam("drouting", "gw_id_avp", "$avp(dr_gw_id)")\n',
        text,
        count=1,
    )

acc_cli = '''route[acc_cli_gw] {
	$acc_extra(src) = $dlg_val(os_src);
	$acc_extra(dst) = $dlg_val(os_dst);
	$acc_extra(ip) = $si;
	$acc_extra(cli) = $fU;
	$var(gw) = $avp(dr_gw_id);
	if ($var(gw) == NULL || $(var(gw){s.len}) == 0) {
		$var(gw) = $avp(___dr_gw_id);
	}
	if (($var(gw) == NULL || $(var(gw){s.len}) == 0) && $avp(dr_gw_attrs) != NULL) {
		$var(tid) = $(avp(dr_gw_attrs){param.value,tid});
		if ($var(tid) != NULL && $(var(tid){s.len}) > 0) {
			$var(gw) = "T" + $var(tid);
		}
	}
	$acc_extra(gw) = $var(gw);
	xlog("L_INFO", "ACC cli=$fU sip=$dlg_val(os_src) gw=$var(gw)\\n");
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
    text = text.replace("route[relay]", acc_cli + "\nroute[relay]", 1)

if "route(acc_cli_gw)" not in text:
    text = re.sub(r'(route\(apply_trunk_attrs\);\s*)', r'\1\n\troute(acc_cli_gw);\n', text, count=2)

open(path, "w", encoding="utf-8").write(text)
print("OK opensips gw_id_avp + acc_cli_gw")
PY

opensips -c -f "$CFG"
systemctl restart opensips
sleep 1
systemctl is-active opensips

bash "$SRC/deploy/refresh-ui.sh"
CUR="$(readlink -f /opt/ownswitch/current)"

# Re-sync trunks so attrs include tid=
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-trunks.php" 2>/dev/null \
  || runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true

# Backfill trunk + carrier buy cost / margin on recent CDRs
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/reattribute_cdrs.php" --limit=1000 || true

systemctl restart ownswitch-worker 2>/dev/null || true

echo
echo "################################################################"
echo " Carrier trunk now stored on ACC (gw_id_avp + tid attrs)."
echo " Existing answered CDRs re-costed when a provider rate matches."
echo " /users list shows Balance."
echo " Place a new answered call → Trunk name + Cost + Margin should fill."
echo " If buy stays 0: check carrier tariff prefix matches the dialed number."
echo "################################################################"
