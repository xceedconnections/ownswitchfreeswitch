#!/usr/bin/env bash
# Eyebeam registered + portal REGISTERED but INVITE → 404 "User not registered"
# = live opensips.cfg still REGISTER-only OR broken drouting modparams.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/force-outbound-calls-now.sh
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

echo "======== BEFORE ========"
grep -nE 'do_routing|User not registered|gw_attrs_avp|loadmodule "dialog|loadmodule "cgrates' "$CFG" | head -n 50 || true

echo "======== Sanitize / repair opensips.cfg ========"
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
orig = text

# 1) Remove obsolete gw_attrs_avp lines (full line; value may contain parentheses)
text = re.sub(
    r"""^[ \t]*modparam\(\s*"drouting"\s*,\s*"gw_attrs_avp"\s*,\s*'[^'\n]*'\s*\)\s*\n?""",
    "",
    text,
    flags=re.M,
)
text = re.sub(
    r'''^[ \t]*modparam\(\s*"drouting"\s*,\s*"gw_attrs_avp"\s*,\s*"[^"\n]*"\s*\)\s*\n?''',
    "",
    text,
    flags=re.M,
)

# 2) Repair damage from prior bad regex that left orphan: ')
text = re.sub(r"""^[ \t]*'\)\s*\n""", "", text, flags=re.M)
text = re.sub(r'''^[ \t]*"\)\s*\n''', "", text, flags=re.M)

# 3) do_routing: group id must be INT (OpenSIPS 3.6 rejects "1" string)
#    5th arg = gw attrs pvar
text = re.sub(
    r'do_routing\(\s*"1"\s*(?:,\s*,\s*,\s*,\s*\$avp\(dr_gw_attrs\))?\s*\)',
    'do_routing(1, , , , $avp(dr_gw_attrs))',
    text,
)
text = re.sub(
    r"do_routing\(\s*'1'\s*(?:,\s*,\s*,\s*,\s*\$avp\(dr_gw_attrs\))?\s*\)",
    'do_routing(1, , , , $avp(dr_gw_attrs))',
    text,
)
if 'do_routing(1)' in text and 'do_routing(1, , , , $avp(dr_gw_attrs))' not in text:
    text = text.replace('do_routing(1)', 'do_routing(1, , , , $avp(dr_gw_attrs))')

# 3b) CGRateS fail-open — "Billing Authorization Failed" blocks all PSTN until rates exist
fail_closed = re.compile(
    r'if\s*\(\s*!cgrates_auth\s*\(\s*\)\s*\)\s*\{'
    r'[^}]*'
    r'send_reply\s*\(\s*403\s*,\s*"Billing Authorization Failed"\s*\)\s*;'
    r'[^}]*\}'
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
if fail_closed.search(text):
    text = fail_closed.sub(fail_open, text, count=1)
    print("cgrates: fail-closed → fail-open")
elif "Billing Authorization Failed" in text:
    # strip any remaining 403 billing replies
    text = re.sub(
        r'[ \t]*acc_db_request\("403 Billing Authorization Failed",\s*"acc"\);\s*\n?',
        "",
        text,
    )
    text = re.sub(
        r'[ \t]*send_reply\(\s*403\s*,\s*"Billing Authorization Failed"\s*\)\s*;\s*\n?',
        '\t\txlog("L_WARN", "cgrates_auth failed (allowing call)\\n");\n',
        text,
    )
    # remove exit that immediately followed billing reject inside the if
    print("cgrates: removed Billing Authorization Failed replies")

def ensure_mod(src, name, block_after=""):
    if f'loadmodule "{name}"' in src:
        return src
    insert = f'\nloadmodule "{name}"\n{block_after}'
    m = re.search(r'loadmodule "registrar\.so".*\n', src)
    if m:
        return src[: m.end()] + insert + src[m.end() :]
    return src + insert

# 4) dialog BEFORE cgrates (hard dependency)
dialog_so = os.path.join(mpath, "dialog.so")
cgrates_so = os.path.join(mpath, "cgrates.so")
if os.path.isfile(dialog_so):
    text = ensure_mod(
        text,
        "dialog.so",
        f'modparam("dialog", "db_url", "{db_url}")\n'
        f'modparam("dialog", "db_mode", 2)\n'
        f'modparam("dialog", "default_timeout", 7200)\n',
    )
else:
    # Cannot satisfy cgrates — drop it so opensips can start
    if 'loadmodule "cgrates.so"' in text:
        text = re.sub(r'^[ \t]*loadmodule\s+"cgrates\.so"\s*\n?', "", text, flags=re.M)
        text = re.sub(r'^[ \t]*modparam\(\s*"cgrates"[^)]*\)\s*\n?', "", text, flags=re.M)
        print("WARN: no dialog.so — removed cgrates loadmodule")

# If cgrates is loaded but dialog still missing, strip cgrates
if 'loadmodule "cgrates.so"' in text and 'loadmodule "dialog.so"' not in text:
    text = re.sub(r'^[ \t]*loadmodule\s+"cgrates\.so"\s*\n?', "", text, flags=re.M)
    text = re.sub(
        r'^[ \t]*modparam\(\s*"cgrates"\s*,\s*"[^"]*"\s*,\s*[^)\n]+\)\s*\n?',
        "",
        text,
        flags=re.M,
    )
    print("WARN: stripped cgrates (dialog not loaded)")

# Move dialog loadmodule block above cgrates if both present and dialog is after
if 'loadmodule "cgrates.so"' in text and 'loadmodule "dialog.so"' in text:
    di = text.find('loadmodule "dialog.so"')
    ci = text.find('loadmodule "cgrates.so"')
    if di > ci:
        # extract dialog loadmodule + following modparam("dialog"... lines
        m = re.search(
            r'loadmodule "dialog\.so"\n(?:modparam\("dialog"[^\n]*\n)*',
            text,
        )
        if m:
            block = m.group(0)
            text = text[: m.start()] + text[m.end() :]
            ci = text.find('loadmodule "cgrates.so"')
            text = text[:ci] + block + text[ci:]
            print("reordered dialog before cgrates")

open(path, "w", encoding="utf-8").write(text)
print("wrote", path)
if text != orig:
    print("cfg changed")
else:
    print("cfg unchanged after sanitize")
PY

echo "======== Ensure packages / tables ========"
export DEBIAN_FRONTEND=noninteractive
apt-get install -y opensips-mysql-dbschema opensips-mysql-module \
  opensips-auth-modules opensips-nathelper-module opensips-dialog-module 2>/dev/null || true
apt-get install -y opensips-cgrates-module 2>/dev/null || true

echo "======== Apply OpenSIPS MySQL schemas (dialog/drouting/acc/…) ========"
# Stop flapping restarts while we fix DB
systemctl stop opensips 2>/dev/null || true

mysql --protocol=socket -uroot opensips -e "
CREATE TABLE IF NOT EXISTS version (
  table_name CHAR(32) NOT NULL,
  table_version INT UNSIGNED DEFAULT 0 NOT NULL,
  CONSTRAINT t_name_idx UNIQUE (table_name)
) ENGINE=InnoDB;
" 2>/dev/null || true

# Prefer official packaged SQL (creates tables + version rows). Ignore dup errors.
for name in dialog drouting permissions standard acc auth_db usrloc; do
  f=$(find /usr/share/opensips /usr/share/opensips-mysql-module /usr/share/doc \
    -type f \( -name "${name}-create.sql" -o -iname "*${name}*create*.sql" \) 2>/dev/null | head -1 || true)
  if [[ -n "${f:-}" ]]; then
    echo "Applying $f"
    mysql --protocol=socket -uroot opensips <"$f" 2>/dev/null || true
  else
    echo "WARN: no ${name}-create.sql on disk"
  fi
done

# Force version rows OpenSIPS 3.6.10 checks (even if tables already existed
# and packaged INSERT was skipped as duplicate / never applied).
mysql --protocol=socket -uroot opensips <<'SQL'
DELETE FROM version WHERE table_name IN (
  'dialog','dr_gateways','dr_rules','dr_carriers','dr_groups','dr_partitions',
  'address','acc'
);
INSERT INTO version (table_name, table_version) VALUES
  ('dialog', 12),
  ('dr_gateways', 6),
  ('dr_rules', 4),
  ('dr_carriers', 3),
  ('dr_groups', 2),
  ('dr_partitions', 1),
  ('address', 5),
  ('acc', 7);
SQL

# Fallback DDL when packaged schema files are absent
mysql --protocol=socket -uroot opensips <<'SQL'
CREATE TABLE IF NOT EXISTS dialog (
  dlg_id BIGINT(10) UNSIGNED PRIMARY KEY NOT NULL,
  callid CHAR(255) NOT NULL,
  from_uri CHAR(255) NOT NULL,
  from_tag CHAR(64) NOT NULL,
  to_uri CHAR(255) NOT NULL,
  to_tag CHAR(64) NOT NULL,
  mangled_from_uri CHAR(255) DEFAULT NULL,
  mangled_to_uri CHAR(255) DEFAULT NULL,
  caller_cseq CHAR(11) NOT NULL,
  callee_cseq CHAR(11) NOT NULL,
  caller_ping_cseq INT(11) UNSIGNED NOT NULL,
  callee_ping_cseq INT(11) UNSIGNED NOT NULL,
  caller_route_set TEXT(512),
  callee_route_set TEXT(512),
  caller_contact CHAR(255),
  callee_contact CHAR(255),
  caller_sock CHAR(64) NOT NULL,
  callee_sock CHAR(64) NOT NULL,
  state INT(10) UNSIGNED NOT NULL,
  start_time INT(10) UNSIGNED NOT NULL,
  timeout INT(10) UNSIGNED NOT NULL,
  vars BLOB(4096) DEFAULT NULL,
  profiles TEXT(512) DEFAULT NULL,
  script_flags CHAR(255) DEFAULT NULL,
  module_flags INT(10) UNSIGNED DEFAULT 0 NOT NULL,
  flags INT(10) UNSIGNED DEFAULT 0 NOT NULL,
  rt_on_answer CHAR(64) DEFAULT NULL,
  rt_on_timeout CHAR(64) DEFAULT NULL,
  rt_on_hangup CHAR(64) DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dr_gateways (
  id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  gwid CHAR(64) NOT NULL,
  type INT(11) UNSIGNED DEFAULT 0 NOT NULL,
  address CHAR(128) NOT NULL,
  strip INT(11) UNSIGNED DEFAULT 0 NOT NULL,
  pri_prefix CHAR(16) DEFAULT NULL,
  attrs CHAR(255) DEFAULT NULL,
  probe_mode INT(11) UNSIGNED DEFAULT 0 NOT NULL,
  state INT(11) UNSIGNED DEFAULT 0 NOT NULL,
  socket CHAR(128) DEFAULT NULL,
  description CHAR(128) DEFAULT NULL,
  CONSTRAINT dr_gw_idx UNIQUE (gwid)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dr_rules (
  ruleid INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  groupid CHAR(255) NOT NULL,
  prefix CHAR(64) NOT NULL,
  timerec CHAR(255) DEFAULT NULL,
  priority INT(11) DEFAULT 0 NOT NULL,
  routeid CHAR(255) DEFAULT NULL,
  gwlist CHAR(255),
  sort_alg CHAR(1) DEFAULT 'N' NOT NULL,
  sort_profile INT(10) UNSIGNED DEFAULT NULL,
  attrs CHAR(255) DEFAULT NULL,
  description CHAR(128) DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dr_carriers (
  id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  carrierid CHAR(64) NOT NULL,
  gwlist CHAR(255) NOT NULL,
  flags INT(11) UNSIGNED DEFAULT 0 NOT NULL,
  sort_alg CHAR(1) DEFAULT 'N' NOT NULL,
  state INT(11) UNSIGNED DEFAULT 0 NOT NULL,
  attrs CHAR(255) DEFAULT NULL,
  description CHAR(128) DEFAULT NULL,
  CONSTRAINT dr_carrier_idx UNIQUE (carrierid)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dr_groups (
  id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  username CHAR(64) NOT NULL,
  domain CHAR(128) DEFAULT NULL,
  groupid INT(11) UNSIGNED DEFAULT 0 NOT NULL,
  description CHAR(128) DEFAULT NULL
) ENGINE=InnoDB;
SQL

echo "version table after seed:"
mysql --protocol=socket -uroot opensips -e \
  "SELECT table_name, table_version FROM version ORDER BY table_name;" || true

bash "$SRC/deploy/patch-minimal-cfg-add-drouting.sh"

# Final sanitize (safe regex only)
python3 - "$CFG" "$DB_URL" "$MPATH" <<'PY'
import os, re, sys
path, db_url, mpath = sys.argv[1:4]
text = open(path, encoding="utf-8", errors="replace").read()
text2 = re.sub(
    r"""^[ \t]*modparam\(\s*"drouting"\s*,\s*"gw_attrs_avp"\s*,\s*'[^'\n]*'\s*\)\s*\n?""",
    "",
    text,
    flags=re.M,
)
text2 = re.sub(r"""^[ \t]*'\)\s*\n""", "", text2, flags=re.M)
if 'loadmodule "dialog.so"' not in text2 and os.path.isfile(os.path.join(mpath, "dialog.so")):
    insert = (
        f'\nloadmodule "dialog.so"\n'
        f'modparam("dialog", "db_url", "{db_url}")\n'
        f'modparam("dialog", "db_mode", 2)\n'
        f'modparam("dialog", "default_timeout", 7200)\n'
    )
    m = re.search(r'loadmodule "registrar\.so".*\n', text2)
    text2 = (text2[: m.end()] + insert + text2[m.end() :]) if m else (text2 + insert)
if 'loadmodule "cgrates.so"' in text2 and 'loadmodule "dialog.so"' not in text2:
    text2 = re.sub(r'^[ \t]*loadmodule\s+"cgrates\.so"\s*\n?', "", text2, flags=re.M)
    text2 = re.sub(r'^[ \t]*modparam\(\s*"cgrates"[^\n]*\n?', "", text2, flags=re.M)
if text2 != text:
    open(path, "w", encoding="utf-8").write(text2)
    print("final sanitize applied")
PY

echo "======== AFTER ========"
grep -nE 'do_routing|User not registered|gw_attrs_avp|loadmodule "dialog|loadmodule "cgrates' "$CFG" | head -n 50 || true
grep -q 'gw_attrs_avp' "$CFG" && { echo "FATAL: gw_attrs_avp still present"; exit 1; }
grep -q 'do_routing' "$CFG" || { echo "FATAL: no do_routing"; exit 1; }
grep -q 'User not registered' "$CFG" && { echo "FATAL: dead-end still present"; exit 1; }

echo "======== opensips -c ========"
opensips -c -f "$CFG"

install -d /etc/systemd/system/opensips.service.d
cat >/etc/systemd/system/opensips.service.d/ownswitch.conf <<EOF
[Service]
Type=simple
ExecStart=
ExecStart=/usr/sbin/opensips -F -f ${CFG}
Restart=on-failure
RestartSec=2
EOF
systemctl daemon-reload
systemctl stop opensips || true
fuser -k 5060/udp 2>/dev/null || true
fuser -k 5060/tcp 2>/dev/null || true
sleep 1
systemctl start opensips
sleep 2
systemctl is-active opensips || { journalctl -u opensips -n 80 --no-pager; exit 1; }

echo "======== location ========"
mysql --protocol=socket -uroot opensips -e \
  "SELECT username, domain, LEFT(contact,50) c, received, expires, FROM_UNIXTIME(expires) exp FROM location LIMIT 20;" \
  2>/dev/null || true

echo "======== Sync trunks / SIP users ========"
CUR="$(readlink -f /opt/ownswitch/current)"
bash "$SRC/deploy/refresh-ui.sh" || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-trunks.php" || true

echo "======== drouting rows ========"
mysql --protocol=socket -uroot opensips -e \
  "SELECT COUNT(*) AS gateways FROM dr_gateways; SELECT COUNT(*) AS rules FROM dr_rules;
   SELECT id, gwid, address, strip, pri_prefix FROM dr_gateways LIMIT 10;
   SELECT ruleid, groupid, prefix, gwlist, priority FROM dr_rules LIMIT 10;" \
  2>/dev/null || echo "WARN: drouting tables missing"

GW=$(mysql --protocol=socket -uroot -N opensips -e "SELECT COUNT(*) FROM dr_gateways" 2>/dev/null || echo 0)

echo
echo "################################################################"
echo " Softphone: Unregister → Register again in Eyebeam, then dial."
echo " PSTN needs gateways>0 (currently: ${GW})."
if [[ "${GW}" == "0" ]]; then
  echo " ADD portal Trunks + Routes, then:"
  echo "   sudo -u ownswitch env OWNSWITCH_ENV_FILE=$ENV_FILE php $CUR/bin/sync-opensips-trunks.php"
fi
echo "################################################################"
