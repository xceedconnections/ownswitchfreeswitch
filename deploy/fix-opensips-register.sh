#!/usr/bin/env bash
# Repair Eyebeam / softphone REGISTER after DNC / dst_format patches.
# Root cause we fix: auth_db load_credentials references subscriber columns that
# do not exist → www_authorize fails → softphone never registers.
# Also: bad redis:// URL (unescaped password) can stop OpenSIPS from starting.
#
# Usage: sudo bash /opt/ownswitch-src/deploy/fix-opensips-register.sh
set -Eeuo pipefail

CFG="${1:-/etc/opensips/opensips.cfg}"
[[ -f "$CFG" ]] || { echo "Missing $CFG"; exit 1; }
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

echo "== OpenSIPS REGISTER repair =="
echo "cfg=$CFG"

# --- 1) Ensure every column referenced by load_credentials exists ---
ensure_col() {
  local col="$1" def="$2"
  local n
  n="$(mysql -Nuroot opensips -e "SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='opensips' AND TABLE_NAME='subscriber' AND COLUMN_NAME='${col}'" 2>/dev/null || echo 0)"
  if [[ "${n:-0}" -eq 0 ]]; then
    echo "ADD subscriber.${col}"
    mysql -uroot opensips -e "ALTER TABLE subscriber ADD COLUMN ${def}" || {
      echo "FATAL: could not ADD COLUMN ${col}" >&2
      exit 1
    }
  else
    echo "OK subscriber.${col}"
  fi
}

ensure_col media_policy "media_policy VARCHAR(16) NOT NULL DEFAULT 'relay'"
ensure_col dtmf_pass "dtmf_pass VARCHAR(8) NOT NULL DEFAULT 'yes'"
ensure_col cid_mode "cid_mode VARCHAR(16) NOT NULL DEFAULT 'passthrough'"
ensure_col cid_min_len "cid_min_len INT UNSIGNED NOT NULL DEFAULT 0"
ensure_col cid_max_len "cid_max_len INT UNSIGNED NOT NULL DEFAULT 0"
ensure_col allow_calls "allow_calls TINYINT(1) NOT NULL DEFAULT 1"
ensure_col dst_format "dst_format VARCHAR(16) NOT NULL DEFAULT 'as_is'"
ensure_col dnc_group_id "dnc_group_id INT UNSIGNED NOT NULL DEFAULT 0"
# rpid is standard OpenSIPS — create empty if somehow missing
ensure_col rpid "rpid VARCHAR(64) NULL"
ensure_col password "password VARCHAR(64) NOT NULL DEFAULT ''"

# --- 2) Rebuild load_credentials to only map columns that exist ---
BAK="${CFG}.bak-reg-$(date +%Y%m%d%H%M%S)"
cp -a "$CFG" "$BAK"
echo "Backup: $BAK"

python3 - "$CFG" <<'PY'
import re, subprocess, sys
cfg = sys.argv[1]
text = open(cfg, encoding="utf-8", errors="replace").read()

def has_col(name: str) -> bool:
    q = (
        "SELECT COUNT(*) FROM information_schema.COLUMNS "
        "WHERE TABLE_SCHEMA='opensips' AND TABLE_NAME='subscriber' "
        f"AND COLUMN_NAME='{name}'"
    )
    out = subprocess.check_output(["mysql", "-Nuroot", "opensips", "-e", q], text=True).strip()
    return out == "1"

# Known Ownswitch credential maps (avp = column)
wanted = [
    ("$avp(os_media)", "media_policy"),
    ("$avp(os_dtmf)", "dtmf_pass"),
    ("$avp(os_cid_mode)", "cid_mode"),
    ("$avp(os_cid)", "rpid"),
    ("$avp(os_cid_min)", "cid_min_len"),
    ("$avp(os_cid_max)", "cid_max_len"),
    ("$avp(os_allow)", "allow_calls"),
    ("$avp(os_dstfmt)", "dst_format"),
    ("$avp(os_dncid)", "dnc_group_id"),
]
parts = []
for avp, col in wanted:
    if has_col(col):
        parts.append(f"{avp}={col}")
    else:
        print(f"SKIP load_credentials {col} (column missing)")

creds = ";".join(parts)
lc = re.search(r'modparam\("auth_db",\s*"load_credentials",\s*"[^"]*"\)', text)
if not lc:
    print("FATAL: load_credentials modparam not found", file=sys.stderr)
    sys.exit(1)
new = f'modparam("auth_db", "load_credentials", "{creds}")'
text = text[: lc.start()] + new + text[lc.end() :]
print("load_credentials →", creds)

# --- 3) Fix cachedb_redis URL if password has reserved chars ---
# redis://:pass@host breaks when pass contains @ : / #
import urllib.parse
m = re.search(
    r'modparam\("cachedb_redis",\s*"cachedb_url",\s*"redis:dnc=>([^"]+)"\)',
    text,
)
if m:
    raw_url = m.group(1)
    # Parse redis://[:password@]host:port/db
    pm = re.match(r"redis://(?::([^@]*)@)?([^:/]+)(?::(\d+))?/(\d+)", raw_url)
    if pm:
        pw, host, port, db = pm.group(1) or "", pm.group(2), pm.group(3) or "6379", pm.group(4)
        # Re-read secret file if present (authoritative)
        try:
            with open("/etc/ownswitch/secrets/redis_password", encoding="utf-8") as f:
                pw = f.read().strip()
        except OSError:
            pass
        if pw:
            enc = urllib.parse.quote(pw, safe="")
            fixed = f"redis://:{enc}@{host}:{port}/{db}"
        else:
            fixed = f"redis://{host}:{port}/{db}"
        if fixed != raw_url:
            text = text[: m.start(1)] + fixed + text[m.end(1) :]
            print("fixed cachedb_redis URL (password url-encoded)")
        else:
            print("cachedb_redis URL OK")
    else:
        print("WARN: could not parse redis URL:", raw_url)

open(cfg, "w", encoding="utf-8").write(text)
print("Patched", cfg)
PY

# --- 4) Validate + restart ---
if ! opensips -c -f "$CFG"; then
  echo "ERROR: opensips -c failed — restoring $BAK" >&2
  cp -a "$BAK" "$CFG"
  opensips -c -f "$CFG" || true
  exit 1
fi

systemctl restart opensips
sleep 1
if ! systemctl is-active --quiet opensips; then
  echo "FATAL: opensips not active after restart" >&2
  journalctl -u opensips -n 40 --no-pager >&2 || true
  echo "Restoring $BAK" >&2
  cp -a "$BAK" "$CFG"
  systemctl restart opensips || true
  exit 1
fi

echo "opensips: $(systemctl is-active opensips)"

# --- 5) Unban SIP IPs — earlier auth failures often ban the softphone; then REGISTER never hits OpenSIPS (no logs) ---
if command -v fail2ban-client >/dev/null 2>&1; then
  echo "fail2ban SIP jails:"
  fail2ban-client status 2>/dev/null | sed -n 's/.*Jail list://p' || true
  for jail in opensips-auth ownswitch-sip sip-auth; do
    if fail2ban-client status "$jail" >/dev/null 2>&1; then
      echo "Unbanning all in jail=$jail"
      # Prefer unban --all when available; else flush banned IPs one by one
      if fail2ban-client unban --all >/dev/null 2>&1; then
        :
      else
        banned="$(fail2ban-client status "$jail" 2>/dev/null | sed -n 's/.*Banned IP list:\s*//p' || true)"
        for ip in $banned; do
          fail2ban-client set "$jail" unbanip "$ip" >/dev/null 2>&1 || true
          echo "  unban $ip"
        done
      fi
    fi
  done
fi

echo
echo "Listening sockets (expect 5060 udp/tcp):"
ss -ulnp 2>/dev/null | grep -E 'opensips|:5060' || echo "WARN: no UDP 5060"
ss -tlnp 2>/dev/null | grep -E 'opensips|:5060' || echo "(no TCP 5060 — OK if Eyebeam uses UDP)"
echo
echo "socket= lines in cfg:"
grep -E '^socket=' "$CFG" || true
echo
echo "subscriber rows:"
mysql -Nuroot opensips -e "SELECT username, domain, IF(password='' OR password IS NULL,'EMPTY','set'), LENGTH(password) FROM subscriber LIMIT 20" 2>/dev/null || true
echo
echo "Recent REGISTER lines:"
journalctl -u opensips -n 80 --no-pager 2>/dev/null | grep -E 'REGISTER|auth failed|www_authorize|load_credentials|ERROR' | tail -n 30 || true
echo
echo "Done. Next:"
echo "  1) On Eyebeam: Account → Domain = THIS server public IP or voip FQDN (not a wrong host)"
echo "  2) Run:  journalctl -u opensips -f"
echo "  3) Click Register in Eyebeam — you MUST see 'REGISTER from=...' within 1s"
echo "  4) If still nothing:  tcpdump -ni any udp port 5060 -c 20"
echo "     (no packets = wrong Domain/IP/firewall on client path)"
