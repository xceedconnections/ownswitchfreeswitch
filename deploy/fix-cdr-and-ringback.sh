#!/usr/bin/env bash
# 1) OpenSIPS down: acc has no db_extra on 3.6 → use extra_fields + $acc_extra
# 2) No ringback: missing onreply SDP / media=direct → relay + handle 183
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-cdr-and-ringback.sh
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

echo "======== ACC columns ========"
mysql --protocol=socket -uroot opensips <<'SQL'
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

python3 - "$CFG" "$DB_URL" <<'PY'
import re, sys
path, db_url = sys.argv[1:3]
text = open(path, encoding="utf-8", errors="replace").read()

# --- Remove illegal OpenSIPS 3.6 params + leftovers from nested-paren strip ---
text = re.sub(r'^[ \t]*modparam\(\s*"acc"\s*,\s*"db_extra".*$', "", text, flags=re.M)
text = re.sub(r'^[ \t]*;dst_user=.*$', "", text, flags=re.M)
text = re.sub(r'^[ \t]*;src_user=.*$', "", text, flags=re.M)
text = re.sub(r'^[ \t]*modparam\(\s*"drouting"\s*,\s*"gw_attrs_avp".*$', "", text, flags=re.M)

def ensure_after_acc_load(src, block):
    if 'modparam("acc", "extra_fields"' in src:
        src = re.sub(
            r'modparam\(\s*"acc"\s*,\s*"extra_fields"\s*,\s*"[^"]*"\s*\)',
            'modparam("acc", "extra_fields", "db: src->src_user; dst->dst_user; ip->src_ip")',
            src,
            count=1,
        )
        return src
    m = re.search(r'loadmodule "acc\.so"\s*\n(?:modparam\("acc"[^\n]*\n)*)', src)
    if not m:
        # insert loadmodule + params after registrar
        insert = (
            f'\nloadmodule "acc.so"\n'
            f'modparam("acc", "db_url", "{db_url}")\n'
            f'modparam("acc", "early_media", 0)\n'
            f'modparam("acc", "report_cancels", 1)\n'
            f'modparam("acc", "extra_fields", "db: src->src_user; dst->dst_user; ip->src_ip")\n'
        )
        r = re.search(r'loadmodule "registrar\.so".*\n', src)
        return (src[: r.end()] + insert + src[r.end() :]) if r else src + insert
    return src[: m.end()] + block + src[m.end() :]

extra_block = (
    f'modparam("acc", "db_url", "{db_url}")\n'
    f'modparam("acc", "early_media", 0)\n'
    f'modparam("acc", "report_cancels", 1)\n'
    f'modparam("acc", "extra_fields", "db: src->src_user; dst->dst_user; ip->src_ip")\n'
)
# Drop duplicate acc db_url/early lines then ensure one clean set
# Keep existing loadmodule; strip old acc modparams then re-add
if 'loadmodule "acc.so"' in text:
    text = re.sub(r'^[ \t]*modparam\(\s*"acc"\s*,[^\n]*\n', "", text, flags=re.M)
    text = re.sub(
        r'(loadmodule "acc\.so"\s*\n)',
        r'\1' + extra_block,
        text,
        count=1,
    )
else:
    text = ensure_after_acc_load(text, extra_block)

# --- CDR: set $acc_extra after create_dialog ---
acc_set = (
    'create_dialog("B");\n'
    '\t\t$dlg_val(os_src) = $fU;\n'
    '\t\t$dlg_val(os_dst) = $rU;\n'
    '\t\t$acc_extra(src) = $fU;\n'
    '\t\t$acc_extra(dst) = $rU;\n'
    '\t\t$acc_extra(ip) = $si;'
)
if "$acc_extra(src)" not in text:
    # Replace plain create_dialog or already-stamped dlg_val blocks
    if "$dlg_val(os_dst) = $rU" in text:
        text = text.replace(
            'create_dialog("B");\n'
            '\t\t$dlg_val(os_src) = $fU;\n'
            '\t\t$dlg_val(os_dst) = $rU;',
            acc_set,
        )
        # also variant with more spaces
        text = re.sub(
            r'create_dialog\("B"\);\s*\$dlg_val\(os_src\)\s*=\s*\$fU;\s*\$dlg_val\(os_dst\)\s*=\s*\$rU;',
            acc_set.replace("\n\t\t", " "),
            text,
        )
    text = text.replace('create_dialog("B");', acc_set)
    # dedupe if double-replaced
    while text.count("$acc_extra(src) = $fU") > 2:
        # leave as-is if multiple invite paths — OK to set twice
        break
    print("set $acc_extra src/dst/ip")
else:
    print("$acc_extra already present")

if 'do_accounting("db"' not in text:
    text = re.sub(
        r'(\$acc_extra\(ip\)\s*=\s*\$si\s*;)',
        r'\1\n\t\tdo_accounting("db", "cdr|missed|failed");',
        text,
        count=1,
    )

# --- From sip: scheme (keep working calls) ---
text = text.replace(
    'append_hf("From: <$var(user)@$var(dom)>;tag=$ft\\r\\n");',
    'append_hf("From: <sip:$var(user)@$var(dom)>;tag=$ft\\r\\n");',
)
text = text.replace(
    'From: <$var(user)@$var(dom)>',
    'From: <sip:$var(user)@$var(dom)>',
)

# --- Ringback: onreply for 18x/200 SDP + prefer media relay ---
onreply = r'''
onreply_route[handle_reply] {
	if ($rs =~ "[12][0-9][0-9]" && has_body("application/sdp")) {
		rtpengine_manage();
	}
}
'''
if "onreply_route[handle_reply]" not in text:
    text = text.rstrip() + "\n" + onreply
    print("added onreply_route[handle_reply]")
else:
    # ensure rtpengine on replies
    if "rtpengine_manage()" not in text.split("onreply_route[handle_reply]")[1][:400]:
        print("WARN: existing handle_reply may lack rtpengine")

# Ensure t_on_reply before t_relay on outbound
if "t_on_reply(\"handle_reply\")" not in text and "t_on_reply('handle_reply')" not in text:
    text = re.sub(
        r'([ \t]*)(if\s*\(\s*!t_relay\s*\(\s*\)\s*\)|t_relay\s*\(\s*\)\s*;)',
        r'\1t_on_reply("handle_reply");\n\1\2',
        text,
        count=2,
    )
    print("added t_on_reply(handle_reply)")

# Force media relay for owner-style direct (no ringback / one-way risk)
# Override AVP after auth credentials load
if 'OWNSWITCH force media relay' not in text:
    text = re.sub(
        r'(consume_credentials\s*\(\s*\)\s*;)',
        r'\1\n'
        r'\t\t# Ownswitch: relay media so 183 ringback + NAT work (override subscriber direct)\n'
        r'\t\t$avp(os_media) = "relay";\n'
        r'\t\txlog("L_INFO", "OWNSWITCH force media relay\\n");',
        text,
        count=1,
    )
    print("force media=relay after consume_credentials")

# Ensure rtpengine loadmodule
if 'loadmodule "rtpengine.so"' not in text:
    text = re.sub(
        r'(loadmodule "dialog\.so".*\n(?:modparam\("dialog"[^\n]*\n)*)',
        r'\1loadmodule "rtpengine.so"\nmodparam("rtpengine", "rtpengine_sock", "udp:127.0.0.1:22222")\n',
        text,
        count=1,
    )

# Keep cgrates fail-open if present
text = re.sub(
    r'modparam\(\s*"acc"\s*,\s*"db_extra"[^)]*\)\s*\n?',
    "",
    text,
)

open(path, "w", encoding="utf-8").write(text)
print("wrote", path)
if 'db_extra' in text:
    raise SystemExit("FATAL: db_extra still in cfg")
print("OK: no db_extra; extra_fields present" if "extra_fields" in text else "WARN: no extra_fields")
PY

echo "======== cfg acc / reply / from ========"
grep -nE 'db_extra|extra_fields|acc_extra|onreply_route|t_on_reply|force media|From: <sip' "$CFG" | head -n 40

opensips -c -f "$CFG"

# Start rtpengine if present
systemctl start rtpengine 2>/dev/null || systemctl start rtpengine-daemon 2>/dev/null || \
  systemctl start ngcp-rtpengine-daemon 2>/dev/null || true

systemctl restart opensips
sleep 2
systemctl is-active opensips || { journalctl -u opensips -n 60 --no-pager; exit 1; }

echo "======== worker ========"
CUR="$(readlink -f /opt/ownswitch/current)"
bash "$SRC/deploy/refresh-ui.sh" || true
systemctl restart ownswitch-worker 2>/dev/null || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/worker_cdr.php" --limit=200 || true

echo
echo "################################################################"
echo " OpenSIPS should be active again."
echo " 1) Re-register Eyebeam"
echo " 2) Place a call — you should hear ringback before answer"
echo " 3) Hang up, then:"
echo "    mysql opensips -e \"SELECT id,src_user,dst_user,sip_code,duration,time FROM acc ORDER BY id DESC LIMIT 5;\""
echo "    sudo -u ownswitch env OWNSWITCH_ENV_FILE=$ENV_FILE php $CUR/bin/worker_cdr.php"
echo "################################################################"
