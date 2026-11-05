#!/usr/bin/env bash
# Emergency: leftover db_extra fragment breaks opensips.cfg parse.
# Clean ACC (3.6 extra_fields), keep calling/From/sip:, ringback, CDR worker.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/repair-opensips-cdr-now.sh
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
cp -a "$CFG" "/var/backups/opensips.cfg.$(date -u +%Y%m%dT%H%M%SZ)" 2>/dev/null || true

PW=""
[[ -f /etc/ownswitch/secrets/mysql_opensips_password ]] && PW="$(tr -d '\n' </etc/ownswitch/secrets/mysql_opensips_password)"
[[ -n "$PW" ]] || PW="opensips"
DB_URL="$(python3 -c "import urllib.parse,sys; print('mysql://opensips:%s@127.0.0.1/opensips'%urllib.parse.quote(sys.argv[1],safe=''))" "$PW")"

echo "======== Schema acc/missed_calls ========"
mysql --protocol=socket -uroot opensips <<'SQL'
CREATE TABLE IF NOT EXISTS version (
  table_name CHAR(32) NOT NULL,
  table_version INT UNSIGNED DEFAULT 0 NOT NULL,
  CONSTRAINT t_name_idx UNIQUE (table_name)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS acc (
  id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  method CHAR(16) DEFAULT '' NOT NULL,
  from_tag CHAR(64) DEFAULT '' NOT NULL,
  to_tag CHAR(64) DEFAULT '' NOT NULL,
  callid CHAR(128) DEFAULT '' NOT NULL,
  sip_code CHAR(3) DEFAULT '' NOT NULL,
  sip_reason CHAR(32) DEFAULT '' NOT NULL,
  time DATETIME NOT NULL,
  duration INT(11) UNSIGNED DEFAULT 0 NOT NULL,
  ms_duration INT(11) UNSIGNED DEFAULT 0 NOT NULL,
  setuptime INT(11) UNSIGNED DEFAULT 0 NOT NULL,
  created DATETIME DEFAULT NULL,
  src_user VARCHAR(64) NULL,
  dst_user VARCHAR(64) NULL,
  src_ip VARCHAR(45) NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS missed_calls (
  id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  method CHAR(16) DEFAULT '' NOT NULL,
  from_tag CHAR(64) DEFAULT '' NOT NULL,
  to_tag CHAR(64) DEFAULT '' NOT NULL,
  callid CHAR(128) DEFAULT '' NOT NULL,
  sip_code CHAR(3) DEFAULT '' NOT NULL,
  sip_reason CHAR(32) DEFAULT '' NOT NULL,
  time DATETIME NOT NULL,
  setuptime INT(11) UNSIGNED DEFAULT 0 NOT NULL,
  created DATETIME DEFAULT NULL,
  src_user VARCHAR(64) NULL,
  dst_user VARCHAR(64) NULL,
  src_ip VARCHAR(45) NULL
) ENGINE=InnoDB;
DELETE FROM version WHERE table_name IN ('acc','missed_calls');
INSERT INTO version (table_name, table_version) VALUES ('acc', 7), ('missed_calls', 5);
SQL
for tbl in acc missed_calls; do
  mysql --protocol=socket -uroot opensips -e \
    "ALTER TABLE ${tbl} ADD COLUMN src_user VARCHAR(64) NULL;
     ALTER TABLE ${tbl} ADD COLUMN dst_user VARCHAR(64) NULL;
     ALTER TABLE ${tbl} ADD COLUMN src_ip VARCHAR(45) NULL;
     ALTER TABLE ${tbl} MODIFY callid CHAR(128) DEFAULT '' NOT NULL;" 2>/dev/null || true
done

python3 - "$CFG" "$DB_URL" <<'PY'
import re, sys
path, db_url = sys.argv[1:3]
lines = open(path, encoding="utf-8", errors="replace").read().splitlines(True)

# Drop garbage / illegal lines
clean = []
for ln in lines:
    s = ln.strip()
    # leftover from broken db_extra strip (nested parens in $dlg_val(...))
    if s.startswith(";dst_user=") or s.startswith(";src_user=") or 'db_extra' in s:
        print("DROP:", s[:100])
        continue
    if 'gw_attrs_avp' in s:
        print("DROP:", s[:100])
        continue
    # strip ALL acc modparams — we rebuild one clean block
    if re.match(r'modparam\(\s*"acc"', s):
        print("DROP acc modparam:", s[:100])
        continue
    clean.append(ln)

text = "".join(clean)

acc_block = (
    f'modparam("acc", "db_url", "{db_url}")\n'
    f'modparam("acc", "early_media", 0)\n'
    f'modparam("acc", "report_cancels", 1)\n'
    f'modparam("acc", "extra_fields", "db: src->src_user; dst->dst_user; ip->src_ip")\n'
)

if 'loadmodule "acc.so"' not in text:
    text = re.sub(
        r'(loadmodule "registrar\.so".*\n)',
        r'\1loadmodule "acc.so"\n' + acc_block,
        text,
        count=1,
    )
else:
    text = re.sub(
        r'(loadmodule "acc\.so"\s*\n)',
        r'\1' + acc_block,
        text,
        count=1,
    )

# Ensure dialog before cgrates
if 'loadmodule "dialog.so"' not in text:
    text = re.sub(
        r'(loadmodule "registrar\.so".*\n)',
        r'\1loadmodule "dialog.so"\n'
        f'modparam("dialog", "db_url", "{db_url}")\n'
        f'modparam("dialog", "db_mode", 2)\n'
        f'modparam("dialog", "default_timeout", 7200)\n',
        text,
        count=1,
    )

# Ensure rtpengine
if 'loadmodule "rtpengine.so"' not in text:
    text = re.sub(
        r'(loadmodule "dialog\.so".*\n(?:modparam\("dialog"[^\n]*\n)*)',
        r'\1loadmodule "rtpengine.so"\nmodparam("rtpengine", "rtpengine_sock", "udp:127.0.0.1:22222")\n',
        text,
        count=1,
    )

# CDR stamps: after every create_dialog("B");
stamp = (
    'create_dialog("B");\n'
    '\t\t$dlg_val(os_src) = $fU;\n'
    '\t\t$dlg_val(os_dst) = $rU;\n'
    '\t\t$acc_extra(src) = $fU;\n'
    '\t\t$acc_extra(dst) = $rU;\n'
    '\t\t$acc_extra(ip) = $si;'
)
# Normalize prior partial stamps back to create_dialog then re-stamp once per occurrence
text = re.sub(
    r'create_dialog\("B"\);\s*'
    r'(?:\$dlg_val\(os_src\)\s*=\s*\$fU;\s*)?'
    r'(?:\$dlg_val\(os_dst\)\s*=\s*\$rU;\s*)?'
    r'(?:\$acc_extra\(src\)\s*=\s*\$fU;\s*)?'
    r'(?:\$acc_extra\(dst\)\s*=\s*\$rU;\s*)?'
    r'(?:\$acc_extra\(ip\)\s*=\s*\$si;\s*)?',
    stamp + "\n\t\t",
    text,
)

if 'do_accounting("db"' not in text:
    text = text.replace(
        "$acc_extra(ip) = $si;",
        '$acc_extra(ip) = $si;\n\t\tdo_accounting("db", "cdr|missed|failed");',
        1,
    )

# From must use sip:
text = text.replace("From: <$var(user)@$var(dom)>", "From: <sip:$var(user)@$var(dom)>")

# Force media relay + onreply for ringback
if "OWNSWITCH force media relay" not in text:
    text = re.sub(
        r'(consume_credentials\s*\(\s*\)\s*;)',
        r'\1\n'
        r'\t\t$avp(os_media) = "relay";\n'
        r'\t\txlog("L_INFO", "OWNSWITCH force media relay\\n");',
        text,
        count=1,
    )

if "onreply_route[handle_reply]" not in text:
    text += '''
onreply_route[handle_reply] {
	if ($rs =~ "[12][0-9][0-9]" && has_body("application/sdp")) {
		rtpengine_manage();
	}
}
'''

if 't_on_reply("handle_reply")' not in text:
    # before t_relay on invite paths
    text = re.sub(
        r'([ \t]*)((?:if\s*\(\s*!)?t_relay\s*\()',
        r'\1t_on_reply("handle_reply");\n\1\2',
        text,
        count=3,
    )

# cgrates: fail-open + acc if module loaded
if 'loadmodule "cgrates.so"' in text and "cgrates_acc(" not in text:
    if "OWNSWITCH: cgrates bypassed" in text:
        text = text.replace(
            'xlog("L_INFO", "OWNSWITCH: cgrates bypassed for $fU -> $rU\\n");',
            'if (!cgrates_auth()) {\n'
            '\t\t\txlog("L_WARN", "cgrates_auth failed (allowing)\\n");\n'
            '\t\t} else {\n'
            '\t\t\tcgrates_acc("cdr|missed");\n'
            '\t\t}',
        )

open(path, "w", encoding="utf-8").write(text)

# Final sanity
bad = [ln for ln in text.splitlines() if "db_extra" in ln or ln.strip().startswith(";dst_user")]
if bad:
    print("FATAL leftovers:", bad)
    sys.exit(1)
print("OK cleaned cfg")
for i, ln in enumerate(text.splitlines(), 1):
    if "extra_fields" in ln or "acc_extra" in ln or "db_extra" in ln or ln.strip().startswith(";"):
        if "modparam" in ln or "acc_extra" in ln or ln.strip().startswith(";"):
            print(f"L{i}: {ln.strip()[:120]}")
PY

echo "======== opensips -c ========"
opensips -c -f "$CFG"

systemctl start rtpengine 2>/dev/null || systemctl start rtpengine-daemon 2>/dev/null || true
systemctl restart opensips
sleep 2
systemctl is-active opensips || { journalctl -u opensips -n 40 --no-pager; exit 1; }

echo "======== UI + worker ========"
CUR="$(readlink -f /opt/ownswitch/current)"
bash "$SRC/deploy/refresh-ui.sh" || true
systemctl enable ownswitch-worker 2>/dev/null || true
systemctl restart ownswitch-worker 2>/dev/null || true
sleep 1
systemctl is-active ownswitch-worker 2>/dev/null || echo "WARN: worker not active — one-shot import still runs"

runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/worker_cdr.php" --limit=500 || true

PDB=$(grep -E '^DB_DATABASE=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)
PDB="${PDB:-ownswitch}"

echo "======== counts ========"
mysql --protocol=socket -uroot opensips -e \
  "SELECT COUNT(*) acc FROM acc; SELECT COUNT(*) missed FROM missed_calls;" || true
mysql --protocol=socket -uroot "$PDB" -e \
  "SELECT COUNT(*) cdrs FROM cdrs;
   SELECT status, COUNT(*) n FROM cdr_ingest_queue GROUP BY status;
   SELECT id,src,dst,duration,disposition FROM cdrs ORDER BY id DESC LIMIT 8;" 2>/dev/null || true

echo
echo "################################################################"
echo " OpenSIPS ACTIVE. Re-register Eyebeam."
echo " Answered + failed CDRs: opensips.acc / missed_calls → worker → portal cdrs."
echo " ASR/ACD/dashboard read portal cdrs — appear after worker import."
echo " Test: short call + one cancel/busy, then:"
echo "   mysql opensips -e 'SELECT id,src_user,dst_user,sip_code,duration FROM acc ORDER BY id DESC LIMIT 5;'"
echo "   mysql opensips -e 'SELECT id,src_user,dst_user,sip_code FROM missed_calls ORDER BY id DESC LIMIT 5;'"
echo "   sudo -u ownswitch env OWNSWITCH_ENV_FILE=$ENV_FILE php $CUR/bin/worker_cdr.php"
echo "################################################################"
