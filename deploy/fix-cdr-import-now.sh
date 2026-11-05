#!/usr/bin/env bash
# ACC has rows but src/dst NULL → importer skipped them and advanced last_id.
# Fix $acc_extra timing, reset import cursor, re-import into portal.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-cdr-import-now.sh
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

PW=""
[[ -f /etc/ownswitch/secrets/mysql_opensips_password ]] && PW="$(tr -d '\n' </etc/ownswitch/secrets/mysql_opensips_password)"
[[ -n "$PW" ]] || PW="opensips"
DB_URL="$(python3 -c "import urllib.parse,sys; print('mysql://opensips:%s@127.0.0.1/opensips'%urllib.parse.quote(sys.argv[1],safe=''))" "$PW")"

echo "======== Fix ACC extras: do_accounting THEN \$acc_extra ========"
python3 - "$CFG" "$DB_URL" <<'PY'
import re, sys
path, db_url = sys.argv[1:3]
text = open(path, encoding="utf-8", errors="replace").read()

# Remove leftover db_extra fragments
text = re.sub(r'^[ \t]*;dst_user=.*\n?', "", text, flags=re.M)
text = re.sub(r'^[ \t]*modparam\(\s*"acc"\s*,\s*"db_extra".*\n?', "", text, flags=re.M)

# Ensure extra_fields
if 'extra_fields' not in text:
    text = re.sub(
        r'(loadmodule "acc\.so"\s*\n)',
        r'\1'
        f'modparam("acc", "db_url", "{db_url}")\n'
        f'modparam("acc", "extra_fields", "db: src->src_user; dst->dst_user; ip->src_ip")\n',
        text,
        count=1,
    )
else:
    # normalize
    text = re.sub(
        r'modparam\(\s*"acc"\s*,\s*"extra_fields"\s*,\s*"[^"]*"\s*\)',
        'modparam("acc", "extra_fields", "db: src->src_user; dst->dst_user; ip->src_ip")',
        text,
    )

# Rebuild a reliable accounting prelude used on INVITE legs:
# create_dialog → do_accounting → set $acc_extra (order matters for some 3.6 builds)
block = (
    'create_dialog("B");\n'
    '\t\tdo_accounting("db", "cdr|missed|failed");\n'
    '\t\t$dlg_val(os_src) = $fU;\n'
    '\t\t$dlg_val(os_dst) = $rU;\n'
    '\t\t$acc_extra(src) = $fU;\n'
    '\t\t$acc_extra(dst) = $rU;\n'
    '\t\t$acc_extra(ip) = $si;'
)

# Replace any prior create_dialog + optional stamps / do_accounting cluster
text2 = re.sub(
    r'create_dialog\("B"\);\s*'
    r'(?:do_accounting\("db",\s*"cdr\|missed\|failed"\);\s*)?'
    r'(?:\$dlg_val\(os_src\)\s*=\s*\$fU;\s*)?'
    r'(?:\$dlg_val\(os_dst\)\s*=\s*\$rU;\s*)?'
    r'(?:\$acc_extra\(src\)\s*=\s*\$fU;\s*)?'
    r'(?:\$acc_extra\(dst\)\s*=\s*\$rU;\s*)?'
    r'(?:\$acc_extra\(ip\)\s*=\s*\$si;\s*)?'
    r'(?:do_accounting\("db",\s*"cdr\|missed\|failed"\);\s*)?',
    block + "\n\t\t",
    text,
)
text = text2

# Also set extras right before t_relay (covers late RURI / safety)
if "OWNSWITCH acc_extra before relay" not in text:
    text = re.sub(
        r'([ \t]*)(t_on_reply\("handle_reply"\)\s*;)',
        r'\1$acc_extra(src) = $fU;\n'
        r'\1$acc_extra(dst) = $dlg_val(os_dst);\n'
        r'\1$acc_extra(ip) = $si;\n'
        r'\1xlog("L_INFO", "OWNSWITCH acc_extra before relay src=$fU dst=$dlg_val(os_dst)\\n");\n'
        r'\1\2',
        text,
        count=2,
    )

open(path, "w", encoding="utf-8").write(text)
if "db_extra" in text or ";dst_user=" in text:
    raise SystemExit("FATAL: db_extra leftover")
print("OK cfg accounting block")
PY

opensips -c -f "$CFG"
systemctl restart opensips
sleep 2
systemctl is-active opensips

echo "======== Deploy importer fix + reset cursor ========"
bash "$SRC/deploy/refresh-ui.sh"
CUR="$(readlink -f /opt/ownswitch/current)"
PDB=$(grep -E '^DB_DATABASE=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)
PDB="${PDB:-ownswitch}"

mysql --protocol=socket -uroot "$PDB" -e \
  "CREATE TABLE IF NOT EXISTS opensips_acc_import_state (
     source_table VARCHAR(64) NOT NULL PRIMARY KEY,
     last_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
     updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
   );
   UPDATE opensips_acc_import_state SET last_id = 0;
   SELECT * FROM opensips_acc_import_state;"

# Backfill NULL src/dst on existing ACC rows from callid is impossible —
# at least ensure worker can import with placeholders (new mapRow).
systemctl restart ownswitch-worker 2>/dev/null || true

echo "======== Import now ========"
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/worker_cdr.php" --limit=500

echo "======== Results ========"
mysql --protocol=socket -uroot opensips -e \
  "SELECT id,src_user,dst_user,sip_code,duration,LEFT(callid,24) callid FROM acc ORDER BY id DESC LIMIT 10;
   SELECT id,src_user,dst_user,sip_code FROM missed_calls ORDER BY id DESC LIMIT 5;" || true
mysql --protocol=socket -uroot "$PDB" -e \
  "SELECT COUNT(*) cdrs FROM cdrs;
   SELECT status, COUNT(*) n FROM cdr_ingest_queue GROUP BY status;
   SELECT id, src, dst, duration, disposition, sip_response FROM cdrs ORDER BY id DESC LIMIT 15;" || true

echo
echo "################################################################"
echo " Portal CDRs should list imported legs (dst may be 0000000000 for"
echo " old ACC rows that had NULL src/dst)."
echo " New calls after this fix should store real src_user/dst_user."
echo " Place a new answered call + one failed call, wait 5s, re-run worker."
echo "################################################################"
