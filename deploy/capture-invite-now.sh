#!/usr/bin/env bash
# No OWNSWITCH/INVITE lines in journalctl -u → logs are on syslog LOCAL0, or INVITE never hit.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/capture-invite-now.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
cd "$SRC"
git fetch origin main
git reset --hard origin/main
chattr -i "$CFG" 2>/dev/null || true

echo "======== 1) Logging: send xlog to stderr (journal) + syslog ========"
python3 - "$CFG" <<'PY'
import re, sys
path = sys.argv[1]
text = open(path, encoding="utf-8", errors="replace").read()
orig = text
def set_param(src, key, val):
    if re.search(rf'^{re.escape(key)}\s*=', src, re.M):
        return re.sub(rf'^{re.escape(key)}\s*=.*$', f'{key}={val}', src, count=1, flags=re.M)
    # after log_level block
    return re.sub(r'(log_level\s*=\s*\d+\s*\n)', rf'\1{key}={val}\n', src, count=1)
text = set_param(text, "log_level", "4")
text = set_param(text, "xlog_level", "3")
text = set_param(text, "stderror_enabled", "yes")
text = set_param(text, "syslog_enabled", "yes")
# Log every INVITE auth attempt
if "OWNSWITCH INVITE-IN" not in text:
    text2, n = re.subn(
        r'([ \t]*)(if\s*\(\s*!proxy_authorize\s*\()',
        r'\1xlog("L_WARN", "OWNSWITCH INVITE-IN $fU -> $rU from $si ruri=$ru\\n");\n\1\2',
        text,
        count=1,
    )
    if n:
        text = text2
        print("inserted INVITE-IN xlog")
    else:
        print("WARN: could not insert INVITE-IN xlog")
open(path, "w", encoding="utf-8").write(text)
print("logging params updated" if text != orig else "logging already ok")
PY

opensips -c -f "$CFG"
systemctl restart opensips
sleep 2
systemctl is-active opensips

echo "======== 2) Cfg must show INVITE-IN / empty realm / do_routing ========"
grep -nE 'OWNSWITCH|proxy_authorize|do_routing|stderror_enabled|log_level' "$CFG" | head -n 40

echo "======== 3) Registered contacts ========"
mysql --protocol=socket -uroot opensips -e \
  "SELECT username, domain, LEFT(contact,55) c, received, FROM_UNIXTIME(expires) exp FROM location LIMIT 20;" 2>/dev/null || true

echo "======== 4) Trunks ========"
mysql --protocol=socket -uroot opensips -e \
  "SELECT COUNT(*) gw FROM dr_gateways; SELECT COUNT(*) rules FROM dr_rules;" 2>/dev/null || true

echo "======== 5) Capture 25s of SIP on :5060 — CALL NOW from Eyebeam ========"
PCAP=/tmp/ownswitch-invite-$$.pcap
timeout 25 tcpdump -ni any -s 0 -w "$PCAP" "udp port 5060 or tcp port 5060" 2>/dev/null || \
  timeout 25 tcpdump -ni eth0 -s 0 -w "$PCAP" "port 5060" 2>/dev/null || true

echo "---- SIP methods seen in pcap ----"
if [[ -f "$PCAP" ]]; then
  tcpdump -nn -r "$PCAP" -A 2>/dev/null | grep -E '^(INVITE|ACK|BYE|CANCEL|REGISTER|SIP/2.0)' | head -n 60 || true
  echo "pcap: $PCAP ($(wc -c <"$PCAP") bytes)"
else
  echo "WARN: no pcap (tcpdump missing?)"
fi

echo "---- opensips xlog (journal stderr) ----"
journalctl -u opensips -n 100 --no-pager | grep -iE 'OWNSWITCH|INVITE|403|503|auth fail|no route|drouting' || true

echo "---- syslog / messages (LOCAL0) ----"
for f in /var/log/syslog /var/log/messages /var/log/opensips.log; do
  [[ -f "$f" ]] || continue
  echo "## $f"
  grep -iE 'opensips.*(OWNSWITCH|INVITE|403|503|auth)' "$f" | tail -n 40 || true
done
journalctl -t opensips -n 80 --no-pager 2>/dev/null | grep -iE 'OWNSWITCH|INVITE|403|503' || true

echo
echo "################################################################"
echo " If pcap has NO INVITE: Eyebeam is not sending to this server"
echo "   (wrong Domain/IP, or still registered elsewhere)."
echo " If pcap has INVITE but no OWNSWITCH log: paste opensips -c and"
echo "   sed -n '130,200p' /etc/opensips/opensips.cfg"
echo " If OWNSWITCH no drouting: add Trunks+Routes and sync."
echo "################################################################"
