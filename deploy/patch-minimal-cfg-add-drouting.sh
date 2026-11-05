#!/usr/bin/env bash
# Extend working minimal digest-REGISTER cfg with outbound drouting.
# Live minimal uses: lookup → route(relay) → 404 "User not registered" → exit
# (older patchers looked for t_relay() and never matched).
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || exit 1

CFG=/etc/opensips/opensips.cfg
[[ -f "$CFG" ]] || { echo "missing $CFG"; exit 1; }
chattr -i "$CFG" 2>/dev/null || true

PW=""
[[ -f /etc/ownswitch/secrets/mysql_opensips_password ]] && PW="$(tr -d '\n' </etc/ownswitch/secrets/mysql_opensips_password)"
[[ -n "$PW" ]] || PW="opensips"
DB_URL="$(python3 -c "import urllib.parse,sys; print('mysql://opensips:%s@127.0.0.1/opensips'%urllib.parse.quote(sys.argv[1],safe=''))" "$PW")"

MOD_DIR="$(dirname "$(find /usr/lib -type f -name tm.so -path '*opensips/modules*' 2>/dev/null | head -1)")"
MPATH="${MOD_DIR}/"

python3 - "$CFG" "$DB_URL" "$MPATH" <<'PY'
import os, re, sys
path, db_url, mpath = sys.argv[1:4]
text = open(path, encoding="utf-8", errors="replace").read()

def ensure_mod(src, name, block_after=""):
    if f'loadmodule "{name}"' in src:
        return src
    insert = f'\nloadmodule "{name}"\n{block_after}'
    m = re.search(r'loadmodule "registrar\.so".*\n', src)
    if m:
        return src[:m.end()] + insert + src[m.end():]
    return src + insert

extras = [
    ("dialog.so", f'modparam("dialog", "db_url", "{db_url}")\nmodparam("dialog", "db_mode", 2)\nmodparam("dialog", "default_timeout", 7200)\n'),
    ("permissions.so", f'modparam("permissions", "db_url", "{db_url}")\n'),
    ("drouting.so", f'modparam("drouting", "db_url", "{db_url}")\n'),
    ("rtpengine.so", 'modparam("rtpengine", "rtpengine_sock", "udp:127.0.0.1:22222")\n'),
    ("acc.so", f'modparam("acc", "db_url", "{db_url}")\nmodparam("acc", "early_media", 0)\nmodparam("acc", "report_cancels", 1)\n'),
]
cgrates_so = os.path.join(mpath, "cgrates.so")
if os.path.isfile(cgrates_so):
    extras.append(("cgrates.so", 'modparam("cgrates", "cgrates_engine", "127.0.0.1:2014")\n'))

for name, params in extras:
    text = ensure_mod(text, name, params)

# Repair prior bad gw_attrs_avp strip (orphan ') line) + remove obsolete param
text = re.sub(
    r"""^[ \t]*modparam\(\s*"drouting"\s*,\s*"gw_attrs_avp"\s*,\s*'[^'\n]*'\s*\)\s*\n?""",
    "",
    text,
    flags=re.M,
)
text = re.sub(r"""^[ \t]*'\)\s*\n""", "", text, flags=re.M)

# dialog must load before cgrates
if 'loadmodule "cgrates.so"' in text and 'loadmodule "dialog.so"' in text:
    di = text.find('loadmodule "dialog.so"')
    ci = text.find('loadmodule "cgrates.so"')
    if di > ci:
        m = re.search(r'loadmodule "dialog\.so"\n(?:modparam\("dialog"[^\n]*\n)*', text)
        if m:
            block = m.group(0)
            text = text[: m.start()] + text[m.end() :]
            ci = text.find('loadmodule "cgrates.so"')
            text = text[:ci] + block + text[ci:]
elif 'loadmodule "cgrates.so"' in text and 'loadmodule "dialog.so"' not in text:
    text = re.sub(r'^[ \t]*loadmodule\s+"cgrates\.so"\s*\n?', "", text, flags=re.M)
    text = re.sub(r'^[ \t]*modparam\(\s*"cgrates"[^\n]*\n?', "", text, flags=re.M)

# Billing off until CGRateS accounts/rates exist (Eyebeam "Forbidden"/403 otherwise)
cgrates_block = '		xlog("L_INFO", "OWNSWITCH: cgrates bypassed for $fU -> $rU\\n");\n'

# Replacement stays INSIDE if (is_method("INVITE")) { ... }
replacement = (
    '		if (lookup("location")) {\n'
    '			xlog("L_INFO", "local AOR hit for $rU from $fU\\n");\n'
    '			record_route();\n'
    '			create_dialog("B");\n'
    '			do_accounting("db", "cdr|missed|failed");\n'
    '			rtpengine_manage();\n'
    '			t_on_failure("carrier_failover");\n'
    '			if (!t_relay()) {\n'
    '				send_reply(500, "Error");\n'
    '			}\n'
    '			exit;\n'
    '		}\n'
    '\n'
    '		# Non-local RURI → PSTN via drouting group 1 (portal Trunks + Routes)\n'
    '		record_route();\n'
    '		create_dialog("B");\n'
    + cgrates_block +
    '		do_accounting("db", "cdr|missed|failed");\n'
    '		rtpengine_manage();\n'
    '		t_on_failure("carrier_failover");\n'
    '		if (!do_routing(1, , , , $avp(dr_gw_attrs))) {\n'
    '			xlog("L_WARN", "no route for $rU from $fU — sync trunks/routes\\n");\n'
    '			send_reply(503, "No Route");\n'
    '			exit;\n'
    '		}\n'
    '		route(apply_outbound_cid);\n'
    '		if (!t_relay()) {\n'
    '			send_reply(500, "Error");\n'
    '		}\n'
    '		exit;'
)

extra_routes = '''
route[apply_outbound_cid] {
	$var(user) = $fU;
	$var(dom) = $fd;
	$var(forced) = 0;
	if ($avp(dr_gw_attrs) != NULL) {
		$var(attrs) = $avp(dr_gw_attrs);
		$var(fu) = $(var(attrs){param.value,fu});
		$var(fd) = $(var(attrs){param.value,fd});
		if ($var(fu) != NULL && $(var(fu){s.len}) > 0) {
			$var(user) = $(var(fu){s.unescape.param});
			$var(forced) = 1;
		}
		if ($var(fd) != NULL && $(var(fd){s.len}) > 0) {
			$var(dom) = $(var(fd){s.unescape.param});
			$var(forced) = 1;
		}
	}
	if ($var(forced) == 0 && !($fU =~ "^[+0-9]{3,30}$")) {
		if ($avp(os_cid) != NULL && $(avp(os_cid){s.len}) > 0 && $avp(os_cid) =~ "^[+0-9]{3,30}$") {
			$var(user) = $avp(os_cid);
			$var(forced) = 1;
		}
	}
	if ($var(forced) == 0 && $avp(os_cid_mode) == "fixed" && $avp(os_cid) != NULL && $(avp(os_cid){s.len}) > 0) {
		$var(user) = $avp(os_cid);
		$var(forced) = 1;
	}
	if ($var(forced) == 1) {
		remove_hf("From");
		append_hf("From: <sip:$var(user)@$var(dom)>;tag=$ft\\r\\n");
		xlog("L_WARN", "OWNSWITCH outbound From $fU → sip:$var(user)@$var(dom)\\n");
	}
}

failure_route[carrier_failover] {
	if (t_was_cancelled()) {
		exit;
	}
	if (use_next_gw()) {
		t_on_failure("carrier_failover");
		route(apply_outbound_cid);
		t_relay();
		exit;
	}
	rtpengine_delete();
}
'''

if 'do_routing' in text and 'User not registered' not in text:
    print("drouting already present — nothing to replace")
else:
    # Match from lookup(...) through 404 reply (+ optional exit). Do NOT eat INVITE's closing brace.
    pat = re.compile(
        r'[ \t]*if\s*\(\s*lookup\s*\(\s*"location"\s*\)\s*\)\s*\{'
        r'.*?'
        r'send_reply\s*\(\s*404\s*,\s*"User not registered"\s*\)\s*;'
        r'(?:\s*exit\s*;)?',
        re.S,
    )
    m = pat.search(text)
    if not m:
        print("FATAL: could not find INVITE lookup / User not registered block", file=sys.stderr)
        for i, line in enumerate(text.splitlines(), 1):
            if "User not registered" in line or 'lookup("location")' in line or "lookup('location')" in line:
                print(f"  L{i}: {line}", file=sys.stderr)
        sys.exit(1)
    text = text[: m.start()] + replacement + text[m.end() :]
    print("replaced User-not-registered dead-end with drouting")

if "failure_route[carrier_failover]" not in text:
    text = text.rstrip() + "\n" + extra_routes
    print("appended failure_route[carrier_failover]")

open(path, "w", encoding="utf-8").write(text)

if "do_routing" not in text:
    print("FATAL: do_routing still missing", file=sys.stderr)
    sys.exit(1)
if "User not registered" in text:
    print("FATAL: User not registered still present", file=sys.stderr)
    sys.exit(1)
print("OK: outbound drouting present in", path)
PY

mysql --protocol=socket -uroot opensips <<'SQL' 2>/dev/null || true
CREATE TABLE IF NOT EXISTS dr_gateways (
  id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  gwid CHAR(64) DEFAULT NULL,
  type INT(11) UNSIGNED DEFAULT 0 NOT NULL,
  address CHAR(128) NOT NULL,
  strip INT(11) UNSIGNED DEFAULT 0 NOT NULL,
  pri_prefix CHAR(16) DEFAULT NULL,
  attrs CHAR(255) DEFAULT NULL,
  probe_mode INT(11) UNSIGNED DEFAULT 0 NOT NULL,
  state INT(11) UNSIGNED DEFAULT 0 NOT NULL,
  socket CHAR(128) DEFAULT NULL,
  description CHAR(128) DEFAULT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS dr_rules (
  ruleid INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  groupid CHAR(255) NOT NULL,
  prefix CHAR(64) NOT NULL,
  timerec CHAR(255) DEFAULT NULL,
  priority INT(11) DEFAULT 0 NOT NULL,
  routeid CHAR(255) DEFAULT NULL,
  gwlist CHAR(255) NOT NULL,
  sort_alg CHAR(1) DEFAULT 'N' NOT NULL,
  sort_profile INT(10) UNSIGNED DEFAULT NULL,
  attrs CHAR(255) DEFAULT NULL,
  description CHAR(128) DEFAULT NULL
) ENGINE=InnoDB;
SQL

opensips -c -f "$CFG"
echo "patch-minimal-cfg-add-drouting: OK"
