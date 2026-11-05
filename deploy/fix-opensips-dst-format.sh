#!/usr/bin/env bash
# Patch live opensips.cfg for Destination format (E.164 +/-) on SIP user + trunk.
set -Eeuo pipefail
CFG="${1:-/etc/opensips/opensips.cfg}"
[[ -f "$CFG" ]] || { echo "Missing $CFG"; exit 1; }

BAK="${CFG}.bak-dstfmt-$(date +%Y%m%d%H%M%S)"
cp -a "$CFG" "$BAK"
echo "Backup: $BAK"

python3 - "$CFG" <<'PY'
import re, sys
cfg = sys.argv[1]
text = open(cfg, encoding="utf-8", errors="replace").read()

# Expand load_credentials with dst_format
lc = re.search(r'modparam\("auth_db",\s*"load_credentials",\s*"([^"]*)"\)', text)
if lc:
    creds = lc.group(1)
    if "os_dstfmt" not in creds:
        if not creds.endswith(";"):
            creds += ";"
        creds += "$avp(os_dstfmt)=dst_format"
        text = text[: lc.start(1)] + creds + text[lc.end(1) :]
        print("load_credentials: added os_dstfmt")
else:
    print("WARN: load_credentials not found")

# Ensure route calls after check_dnc (or check_rate_cap)
def ensure_calls(src: str) -> str:
    if "route(apply_sip_dst_format)" in src:
        return src
    for anchor in ("route(check_dnc);", "route(check_rate_cap);"):
        if anchor in src:
            return src.replace(
                anchor,
                anchor + "\n\troute(apply_sip_dst_format);\n\troute(apply_trunk_dst_format);",
                1,  # first only — failover handled below
            )
    return src

text = ensure_calls(text)
# Failover path: if only one occurrence was added, add after second check_rate_cap block
if text.count("route(apply_sip_dst_format)") < 2 and "failure_route" in text:
    text = text.replace(
        "route(check_dnc);",
        "route(check_dnc);\n\t\troute(apply_sip_dst_format);\n\t\troute(apply_trunk_dst_format);",
    )
    # undo double-add on first if we over-replaced
    # normalize: collapse duplicate consecutive calls
    text = re.sub(
        r"(route\(apply_sip_dst_format\);\s*\n\s*route\(apply_trunk_dst_format\);\s*\n\s*){2,}",
        "route(apply_sip_dst_format);\n\troute(apply_trunk_dst_format);\n\t",
        text,
    )

routes = r'''
route[apply_sip_dst_format] {
	if ($avp(os_dstfmt) == NULL || $(avp(os_dstfmt){s.len}) == 0 || $avp(os_dstfmt) == "as_is") {
		return;
	}
	$var(d) = $(rU{re.subst,/[^0-9]//g});
	if ($(var(d){s.len}) < 3) {
		return;
	}
	if ($avp(os_dstfmt) == "plus" || $avp(os_dstfmt) == "e164_plus") {
		$rU = "+" + $var(d);
		xlog("L_INFO", "sip dst_format=plus RURI=$rU\n");
		return;
	}
	if ($avp(os_dstfmt) == "digits" || $avp(os_dstfmt) == "e164_digits") {
		$rU = $var(d);
		xlog("L_INFO", "sip dst_format=digits RURI=$rU\n");
	}
}

route[apply_trunk_dst_format] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(df) = $(avp(dr_gw_attrs){param.value,dstfmt});
	if ($var(df) == NULL || $(var(df){s.len}) == 0 || $var(df) == "as_is") {
		return;
	}
	$var(d) = $(rU{re.subst,/[^0-9]//g});
	if ($(var(d){s.len}) < 3) {
		return;
	}
	if ($var(df) == "plus" || $var(df) == "e164_plus") {
		$rU = "+" + $var(d);
		xlog("L_INFO", "trunk dst_format=plus RURI=$rU\n");
		return;
	}
	if ($var(df) == "digits" || $var(df) == "e164_digits") {
		$rU = $var(d);
		xlog("L_INFO", "trunk dst_format=digits RURI=$rU\n");
	}
}
'''

text = re.sub(r"(?ms)^route\[apply_sip_dst_format\]\s*\{.*?\n\}\s*", "", text)
text = re.sub(r"(?ms)^route\[apply_trunk_dst_format\]\s*\{.*?\n\}\s*", "", text)
if "route[relay]" in text:
    text = text.replace("route[relay]", routes + "\nroute[relay]", 1)
else:
    text += "\n" + routes

open(cfg, "w", encoding="utf-8").write(text)
print("Patched", cfg)
PY

# Ensure OpenSIPS subscriber.dst_format
PW=""
[[ -s /etc/ownswitch/secrets/mysql_opensips_password ]] && PW="$(tr -d '\n' </etc/ownswitch/secrets/mysql_opensips_password)"
if [[ -n "$PW" ]]; then
  mysql -uopensips -p"$PW" opensips -e \
    "ALTER TABLE subscriber ADD COLUMN dst_format VARCHAR(16) NOT NULL DEFAULT 'as_is'" 2>/dev/null || true
fi

if ! opensips -c -f "$CFG"; then
  echo "ERROR: opensips -c failed — restoring $BAK" >&2
  cp -a "$BAK" "$CFG"
  exit 1
fi
systemctl restart opensips
echo "Destination format routes applied."
echo "Re-save SIP users / trunks (or: php bin/sync-opensips-trunks.php) so dstfmt attrs sync."
