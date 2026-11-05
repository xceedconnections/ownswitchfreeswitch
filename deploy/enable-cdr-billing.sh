#!/usr/bin/env bash
# Calls work but portal CDRs empty → fix OpenSIPS acc + worker import + optional CGRateS.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/enable-cdr-billing.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
ENV_FILE=/etc/ownswitch/ownswitch.env
OWN_ETC=/etc/ownswitch
cd "$SRC"
git fetch origin main
git reset --hard origin/main
chattr -i "$CFG" 2>/dev/null || true

PW=""
[[ -f "$OWN_ETC/secrets/mysql_opensips_password" ]] && PW="$(tr -d '\n' <"$OWN_ETC/secrets/mysql_opensips_password")"
[[ -n "$PW" ]] || PW="opensips"
DB_URL="$(python3 -c "import urllib.parse,sys; print('mysql://opensips:%s@127.0.0.1/opensips'%urllib.parse.quote(sys.argv[1],safe=''))" "$PW")"

echo "======== 1) ACC / missed_calls schema ========"
# Official schema + portal db_extra columns
f=$(find /usr/share/opensips /usr/share/opensips-mysql-module /usr/share/doc \
  -type f -name 'acc-create.sql' 2>/dev/null | head -1 || true)
if [[ -n "${f:-}" ]]; then
  echo "Applying $f"
  mysql --protocol=socket -uroot opensips <"$f" 2>/dev/null || true
fi

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
  created DATETIME DEFAULT NULL
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
  created DATETIME DEFAULT NULL
) ENGINE=InnoDB;

DELETE FROM version WHERE table_name IN ('acc','missed_calls');
INSERT INTO version (table_name, table_version) VALUES ('acc', 7), ('missed_calls', 5);
SQL

for tbl in acc missed_calls; do
  mysql --protocol=socket -uroot opensips -e \
    "ALTER TABLE ${tbl} ADD COLUMN src_user VARCHAR(64) NULL;
     ALTER TABLE ${tbl} ADD COLUMN dst_user VARCHAR(64) NULL;
     ALTER TABLE ${tbl} ADD COLUMN src_ip VARCHAR(45) NULL;" 2>/dev/null || true
done

# Longer callid (Eyebeam IDs often > 64)
mysql --protocol=socket -uroot opensips -e \
  "ALTER TABLE acc MODIFY callid CHAR(128) DEFAULT '' NOT NULL;
   ALTER TABLE missed_calls MODIFY callid CHAR(128) DEFAULT '' NOT NULL;" 2>/dev/null || true

mysql --protocol=socket -uroot opensips -e "SHOW COLUMNS FROM acc; SELECT table_name,table_version FROM version WHERE table_name IN ('acc','missed_calls');"

echo "======== 2) Patch opensips.cfg for ACC + dlg_val src/dst ========"
python3 - "$CFG" "$DB_URL" <<'PY'
import os, re, sys
path, db_url = sys.argv[1:3]
text = open(path, encoding="utf-8", errors="replace").read()
orig = text

def ensure_mod(src, name, block=""):
    if f'loadmodule "{name}"' in src:
        return src
    m = re.search(r'loadmodule "registrar\.so".*\n', src)
    insert = f'\nloadmodule "{name}"\n{block}'
    if m:
        return src[: m.end()] + insert + src[m.end() :]
    return src + insert

# OpenSIPS 3.6: db_extra removed → extra_fields + $acc_extra
text = re.sub(r'^[ \t]*modparam\(\s*"acc"\s*,\s*"db_extra"[^)]*\)\s*\n?', "", text, flags=re.M)
text = ensure_mod(
    text,
    "acc.so",
    f'modparam("acc", "db_url", "{db_url}")\n'
    f'modparam("acc", "early_media", 0)\n'
    f'modparam("acc", "report_cancels", 1)\n'
    f'modparam("acc", "extra_fields", "db: src->src_user; dst->dst_user; ip->src_ip")\n',
)
if 'modparam("acc", "extra_fields"' not in text:
    text = re.sub(
        r'(loadmodule "acc\.so"\s*\n)',
        r'\1modparam("acc", "db_url", "' + db_url + '")\n'
        r'modparam("acc", "extra_fields", "db: src->src_user; dst->dst_user; ip->src_ip")\n',
        text,
        count=1,
    )

stamp = (
    'create_dialog("B");\n'
    '\t\t$dlg_val(os_src) = $fU;\n'
    '\t\t$dlg_val(os_dst) = $rU;\n'
    '\t\t$acc_extra(src) = $fU;\n'
    '\t\t$acc_extra(dst) = $rU;\n'
    '\t\t$acc_extra(ip) = $si;'
)
if "$acc_extra(src)" not in text:
    text = text.replace('create_dialog("B");', stamp)
    print("stamped acc_extra after create_dialog")
else:
    print("acc_extra already present")

# Ensure do_accounting on INVITE outbound path
if 'do_accounting("db"' not in text and "do_accounting('db'" not in text:
    # insert after first create_dialog block on invite
    text2, n = re.subn(
        r'(\$dlg_val\(os_dst\)\s*=\s*\$rU\s*;)',
        r'\1\n\t\tdo_accounting("db", "cdr|missed|failed");',
        text,
        count=1,
    )
    if n:
        text = text2
        print("inserted do_accounting")
    else:
        print("WARN: could not insert do_accounting")
else:
    print("do_accounting already present")

# Restore cgrates_acc (session CDR) but keep auth fail-open — do not 403
if 'cgrates_acc(' not in text and 'loadmodule "cgrates.so"' in text:
    text = text.replace(
        'xlog("L_INFO", "OWNSWITCH: cgrates bypassed for $fU -> $rU\\n");',
        'if (!cgrates_auth()) {\n'
        '\t\t\txlog("L_WARN", "cgrates_auth failed (allowing call)\\n");\n'
        '\t\t} else {\n'
        '\t\t\tcgrates_acc("cdr|missed");\n'
        '\t\t}',
    )
    print("restored cgrates_acc (auth fail-open)")

# Tone down debug logging
text = re.sub(r'^log_level\s*=\s*\d+', 'log_level=3', text, count=1, flags=re.M)

open(path, "w", encoding="utf-8").write(text)
print("cfg", "changed" if text != orig else "unchanged")
PY

opensips -c -f "$CFG"
systemctl restart opensips
sleep 2
systemctl is-active opensips || { journalctl -u opensips -n 50 --no-pager; exit 1; }

echo "======== 3) CDR worker ========"
CUR="$(readlink -f /opt/ownswitch/current)"
bash "$SRC/deploy/refresh-ui.sh" || true

# Ensure worker unit points at current release
if [[ -f /etc/systemd/system/ownswitch-worker.service ]] || systemctl list-unit-files | grep -q ownswitch-worker; then
  systemctl enable ownswitch-worker 2>/dev/null || true
  systemctl restart ownswitch-worker 2>/dev/null || true
  sleep 1
  systemctl is-active ownswitch-worker || systemctl status ownswitch-worker --no-pager | head -n 30 || true
else
  echo "WARN: ownswitch-worker unit missing — running one-shot import"
fi

echo "======== 4) One-shot import ========"
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/worker_cdr.php" --limit=500 || \
  env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/worker_cdr.php" --limit=500 || true

echo "======== 5) Counts ========"
mysql --protocol=socket -uroot opensips -e \
  "SELECT COUNT(*) AS acc_rows FROM acc; SELECT COUNT(*) AS missed FROM missed_calls;
   SELECT id, method, src_user, dst_user, sip_code, duration, time FROM acc ORDER BY id DESC LIMIT 10;
   SELECT id, method, src_user, dst_user, sip_code, time FROM missed_calls ORDER BY id DESC LIMIT 5;" 2>/dev/null || true

# Portal DB name from env
PDB=$(grep -E '^DB_DATABASE=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)
PDB="${PDB:-ownswitch}"
mysql --protocol=socket -uroot "$PDB" -e \
  "SELECT COUNT(*) AS cdrs FROM cdrs;
   SELECT COUNT(*) AS queue_pending FROM cdr_ingest_queue WHERE status='pending';
   SELECT COUNT(*) AS queue_failed FROM cdr_ingest_queue WHERE status='failed';
   SELECT id, src, dst, duration, disposition, created_at FROM cdrs ORDER BY id DESC LIMIT 10;" 2>/dev/null || \
mysql --protocol=socket -uroot -e "SHOW DATABASES;" | head

echo
echo "################################################################"
echo " Place a short test call (answer + hang up), wait 5s, then:"
echo "   sudo -u ownswitch env OWNSWITCH_ENV_FILE=$ENV_FILE php $CUR/bin/worker_cdr.php"
echo "   mysql opensips -e 'SELECT id,src_user,dst_user,sip_code,duration FROM acc ORDER BY id DESC LIMIT 5;'"
echo " Portal CDRs should populate from opensips.acc via the worker."
echo " Rated billing needs rate plans + CGRateS accounts (next if acc rows appear)."
echo "################################################################"
