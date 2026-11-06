#!/usr/bin/env bash
# Privileged fail2ban helper for Ownswitch portal (run via sudo as ownswitch).
# Usage:
#   ownswitch-fail2ban status-all
#   ownswitch-fail2ban status [jail]
#   ownswitch-fail2ban banned [jail]
#   ownswitch-fail2ban unban <ip> [jail]
#   ownswitch-fail2ban ban <ip> [jail]
#   ownswitch-fail2ban config [jail]
#   ownswitch-fail2ban set-config [jail=NAME] maxretry=5 findtime=10m bantime=1h
#   ownswitch-fail2ban reload
#   ownswitch-fail2ban ip-log <ip>
#   ownswitch-fail2ban geo <ip>
set -euo pipefail

SIP_JAIL=opensips-auth
PORTAL_JAIL=ownswitch-portal
DEFAULT_JAIL="${OWNSWITCH_F2B_JAIL:-$SIP_JAIL}"
SIP_JAIL_FILE=/etc/fail2ban/jail.d/ownswitch-sip.local
PORTAL_JAIL_FILE=/etc/fail2ban/jail.d/ownswitch-portal.local
SIP_FILTER=/etc/fail2ban/filter.d/opensips-auth.conf
PORTAL_FILTER=/etc/fail2ban/filter.d/ownswitch-portal.conf
SIP_PORT="${SIP_PORT:-5060}"
SIP_AUTH_LOG=/usr/local/freeswitch/log/auth.log

die() { echo "ERR:$1" >&2; exit 1; }

need_f2b() {
  command -v fail2ban-client >/dev/null 2>&1 || die "fail2ban-client missing"
  systemctl is-active --quiet fail2ban 2>/dev/null || die "fail2ban service not active"
}

jail_file() {
  case "$1" in
    opensips-auth) echo "$SIP_JAIL_FILE" ;;
    ownswitch-portal) echo "$PORTAL_JAIL_FILE" ;;
    *) echo "" ;;
  esac
}

jail_label() {
  case "$1" in
    opensips-auth) echo "SIP auth" ;;
    ownswitch-portal) echo "Portal login" ;;
    *) echo "$1" ;;
  esac
}

known_jails() {
  echo "$SIP_JAIL"
  echo "$PORTAL_JAIL"
}

read_kv() {
  local key="$1" file="$2" def="$3"
  if [[ -f "$file" ]]; then
    local v
    v="$(sed -n "s/^[[:space:]]*${key}[[:space:]]*=[[:space:]]*//p" "$file" | head -1 | tr -d '[:space:]')"
    [[ -n "$v" ]] && { echo "$v"; return; }
  fi
  echo "$def"
}

to_seconds() {
  local v="$1" n u
  if [[ "$v" =~ ^([0-9]+)([smhd])?$ ]]; then
    n="${BASH_REMATCH[1]}"
    u="${BASH_REMATCH[2]:-s}"
    case "$u" in
      s) echo "$n" ;;
      m) echo $((n * 60)) ;;
      h) echo $((n * 3600)) ;;
      d) echo $((n * 86400)) ;;
    esac
    return 0
  fi
  return 1
}

emit_jail_status() {
  local jail="$1"
  local raw currently currently_list total
  echo "jail=${jail}"
  echo "label=$(jail_label "$jail")"
  if ! fail2ban-client status "$jail" >/dev/null 2>&1; then
    echo "enabled=0"
    echo "currently_banned=0"
    echo "total_banned=0"
    echo "banned_list="
    echo "detail=jail not loaded"
    cmd_config_for "$jail"
    return 0
  fi
  raw="$(fail2ban-client status "$jail" 2>/dev/null || true)"
  currently="$(echo "$raw" | sed -n 's/.*Currently banned:\s*//p' | head -1 | tr -d '[:space:]')"
  total="$(echo "$raw" | sed -n 's/.*Total banned:\s*//p' | head -1 | tr -d '[:space:]')"
  currently_list="$(echo "$raw" | sed -n 's/.*Banned IP list:\s*//p' | head -1 | xargs || true)"
  echo "enabled=1"
  echo "currently_banned=${currently:-0}"
  echo "total_banned=${total:-0}"
  echo "banned_list=${currently_list}"
  echo "detail=ok"
  cmd_config_for "$jail"
}

cmd_status() {
  need_f2b
  local jail="${1:-$DEFAULT_JAIL}"
  emit_jail_status "$jail"
}

cmd_status_all() {
  need_f2b
  local j
  echo "jails=${SIP_JAIL},${PORTAL_JAIL}"
  for j in $(known_jails); do
    echo "---"
    emit_jail_status "$j"
  done
}

cmd_banned() {
  need_f2b
  local jail="${1:-}"
  local j
  if [[ -n "$jail" ]]; then
    fail2ban-client status "$jail" 2>/dev/null \
      | sed -n 's/.*Banned IP list:\s*//p' \
      | head -1 \
      | tr ' ' '\n' \
      | sed '/^$/d' || true
    return 0
  fi
  for j in $(known_jails); do
    fail2ban-client status "$j" 2>/dev/null \
      | sed -n 's/.*Banned IP list:\s*//p' \
      | head -1 \
      | tr ' ' '\n' \
      | sed '/^$/d' \
      | while read -r ip; do
          [[ -n "$ip" ]] && echo "${ip}|${j}"
        done || true
  done
}

cmd_unban() {
  local ip="${1:-}" jail="${2:-}"
  [[ -n "$ip" ]] || die "usage: unban <ip> [jail]"
  need_f2b
  local j ok=0
  if [[ -n "$jail" ]]; then
    fail2ban-client set "$jail" unbanip "$ip" >/dev/null 2>&1 && ok=1
  else
    for j in $(known_jails); do
      if fail2ban-client set "$j" unbanip "$ip" >/dev/null 2>&1; then
        ok=1
        echo "OK unbanned $ip from $j"
      fi
    done
    [[ "$ok" -eq 1 ]] && return 0
    die "IP not banned in known jails"
  fi
  [[ "$ok" -eq 1 ]] || die "unban failed"
  echo "OK unbanned $ip"
}

cmd_ban() {
  local ip="${1:-}" jail="${2:-$SIP_JAIL}"
  [[ -n "$ip" ]] || die "usage: ban <ip> [jail]"
  need_f2b
  fail2ban-client set "$jail" banip "$ip" >/dev/null
  echo "OK banned $ip in $jail"
}

cmd_config_for() {
  local jail="$1"
  local file maxretry findtime bantime enabled
  file="$(jail_file "$jail")"
  maxretry="$(read_kv maxretry "${file:-/dev/null}" 5)"
  findtime="$(read_kv findtime "${file:-/dev/null}" 10m)"
  bantime="$(read_kv bantime "${file:-/dev/null}" 1h)"
  enabled="$(read_kv enabled "${file:-/dev/null}" true)"
  echo "maxretry=${maxretry}"
  echo "findtime=${findtime}"
  echo "bantime=${bantime}"
  echo "jail_enabled=${enabled}"
  if [[ "$jail" == "$SIP_JAIL" ]]; then
    echo "sip_port=${SIP_PORT}"
    echo "filter_present=$([ -f "$SIP_FILTER" ] && echo 1 || echo 0)"
  else
    echo "filter_present=$([ -f "$PORTAL_FILTER" ] && echo 1 || echo 0)"
  fi
  echo "jail_file_present=$([ -n "$file" ] && [ -f "$file" ] && echo 1 || echo 0)"
}

cmd_config() {
  local jail="${1:-$DEFAULT_JAIL}"
  echo "jail=${jail}"
  cmd_config_for "$jail"
}

env_get() {
  local key="$1" file="/etc/ownswitch/ownswitch.env"
  [[ -r "$file" ]] || return 0
  sed -n "s/^${key}=//p" "$file" | head -1 | tr -d '\r' | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

# This host, plus carrier trunk addresses, so a trunk reply is never banned.
sip_ignore_ips() {
  local ips="127.0.0.1/8 ::1" ip host db_user db_pass db_name db_host cnf
  for ip in $(hostname -I 2>/dev/null); do
    ips="$ips $ip"
  done
  ip="$(sed -n 's/.*name="ext-sip-ip" value="\([^"]*\)".*/\1/p' /usr/local/freeswitch/conf/sip_profiles/external.xml 2>/dev/null | head -1)"
  case "$ip" in
    ""|\$\$*|@@*) ;;
    *) ips="$ips $ip" ;;
  esac
  db_user="$(env_get DB_USERNAME)"
  [[ -n "$db_user" ]] || db_user="$(env_get DB_USER)"
  db_pass="$(env_get DB_PASSWORD)"
  db_name="$(env_get DB_DATABASE)"
  [[ -n "$db_name" ]] || db_name="$(env_get DB_NAME)"
  db_host="$(env_get DB_HOST)"
  [[ -n "$db_host" ]] || db_host="127.0.0.1"
  if [[ -n "$db_user" && -n "$db_name" ]]; then
    cnf="$(mktemp)"
    chmod 600 "$cnf"
    printf '[client]\nuser=%s\npassword=%s\nhost=%s\n' "$db_user" "$db_pass" "$db_host" >"$cnf"
    while read -r host; do
      [[ -n "$host" ]] || continue
      if [[ "$host" =~ ^[0-9a-fA-F:.]+$ ]]; then
        ips="$ips $host"
      else
        while read -r ip; do
          [[ -n "$ip" ]] && ips="$ips $ip"
        done < <(getent ahostsv4 "$host" 2>/dev/null | awk '{print $1}' | sort -u)
      fi
    done < <(mysql --defaults-extra-file="$cnf" -N -e "SELECT DISTINCT host FROM carrier_trunks WHERE status='active' AND host<>''" "$db_name" 2>/dev/null || true)
    rm -f "$cnf"
  fi
  echo "$ips" | tr ' ' '\n' | awk 'NF && !seen[$0]++' | sort | tr '\n' ' ' | sed 's/[[:space:]]*$//'
}

write_sip_jail() {
  local maxretry="$1" findtime="$2" bantime="$3" enabled="$4"
  local dest="${5:-$SIP_JAIL_FILE}"
  local ignore
  ignore="$(sip_ignore_ips)"
  mkdir -p "$(dirname "$SIP_AUTH_LOG")"
  touch "$SIP_AUTH_LOG"
  chown freeswitch:freeswitch "$SIP_AUTH_LOG" 2>/dev/null || true
  chmod 0640 "$SIP_AUTH_LOG" 2>/dev/null || true
  cat >"$dest" <<EOF
# Managed by Ownswitch (portal Firewall / deploy)
# FreeSWITCH auth warnings + ownswitch-sip syslog. OpenSIPS is not the SIP edge.
[opensips-auth]
enabled  = ${enabled}
filter   = opensips-auth
port     = ${SIP_PORT}
protocol = udp,tcp
backend  = auto
logpath  = ${SIP_AUTH_LOG}
           /var/log/auth.log
maxretry = ${maxretry}
findtime = ${findtime}
bantime  = ${bantime}
ignoreip = ${ignore}
usedns   = no
action   = %(action_)s
EOF
  if [[ "$dest" == "$SIP_JAIL_FILE" ]]; then
    chmod 0644 "$SIP_JAIL_FILE"
  fi
}

cmd_ensure_sip() {
  systemctl enable fail2ban >/dev/null 2>&1 || true
  systemctl start fail2ban >/dev/null 2>&1 || true
  need_f2b
  local maxretry findtime bantime enabled tmp
  maxretry="$(read_kv maxretry "$SIP_JAIL_FILE" 5)"
  findtime="$(read_kv findtime "$SIP_JAIL_FILE" 10m)"
  bantime="$(read_kv bantime "$SIP_JAIL_FILE" 24h)"
  enabled="$(read_kv enabled "$SIP_JAIL_FILE" true)"
  # Stock installer used a 1 hour ban and watched OpenSIPS, which is not running.
  if [[ -f "$SIP_JAIL_FILE" ]] && grep -q 'opensips.service' "$SIP_JAIL_FILE" && [[ "$bantime" == "1h" ]]; then
    bantime="24h"
  fi
  tmp="$(mktemp)"
  write_sip_jail "$maxretry" "$findtime" "$bantime" "$enabled" "$tmp"
  if [[ -f "$SIP_JAIL_FILE" ]] && cmp -s "$tmp" "$SIP_JAIL_FILE"; then
    rm -f "$tmp"
    echo "OK sip jail unchanged"
    return 0
  fi
  install -m 0644 "$tmp" "$SIP_JAIL_FILE"
  rm -f "$tmp"
  fail2ban-client reload opensips-auth >/dev/null 2>&1 || fail2ban-client reload >/dev/null 2>&1 || systemctl reload fail2ban
  echo "OK sip jail updated bantime=${bantime}"
}

write_portal_jail() {
  local maxretry="$1" findtime="$2" bantime="$3" enabled="$4"
  cat >"$PORTAL_JAIL_FILE" <<EOF
# Managed by Ownswitch (portal Firewall / deploy)
[ownswitch-portal]
enabled  = ${enabled}
filter   = ownswitch-portal
port     = http,https
protocol = tcp
backend  = systemd
journalmatch = SYSLOG_IDENTIFIER=ownswitch-portal
maxretry = ${maxretry}
findtime = ${findtime}
bantime  = ${bantime}
action   = %(action_)s
EOF
  chmod 0644 "$PORTAL_JAIL_FILE"
}

apply_live_jail() {
  local jail="$1" maxretry="$2" findtime="$3" bantime="$4" refresh_bans="${5:-0}"
  need_f2b
  local ft_sec bt_sec
  ft_sec="$(to_seconds "$findtime")" || return 0
  bt_sec="$(to_seconds "$bantime")" || return 0
  (( bt_sec >= 60 )) || bt_sec=60
  if fail2ban-client status "$jail" >/dev/null 2>&1; then
    fail2ban-client set "$jail" maxretry "$maxretry" >/dev/null 2>&1 || true
    fail2ban-client set "$jail" findtime "$ft_sec" >/dev/null 2>&1 || true
    fail2ban-client set "$jail" bantime "$bt_sec" >/dev/null 2>&1 || true
    if [[ "$refresh_bans" == "1" ]]; then
      local ip
      while read -r ip; do
        [[ -n "$ip" ]] || continue
        fail2ban-client set "$jail" unbanip "$ip" >/dev/null 2>&1 || true
        fail2ban-client set "$jail" banip "$ip" >/dev/null 2>&1 || true
      done < <(cmd_banned "$jail" || true)
    fi
  fi
}

cmd_set_config() {
  local jail="$DEFAULT_JAIL"
  local maxretry findtime bantime enabled old_bantime file
  local arg
  # optional jail= first
  for arg in "$@"; do
    case "$arg" in
      jail=*) jail="${arg#jail=}" ;;
    esac
  done
  case "$jail" in
    opensips-auth|ownswitch-portal) ;;
    *) die "jail must be opensips-auth or ownswitch-portal" ;;
  esac
  file="$(jail_file "$jail")"
  maxretry="$(read_kv maxretry "$file" 5)"
  findtime="$(read_kv findtime "$file" 10m)"
  bantime="$(read_kv bantime "$file" 1h)"
  old_bantime="$bantime"
  enabled="$(read_kv enabled "$file" true)"
  for arg in "$@"; do
    case "$arg" in
      jail=*) ;;
      maxretry=*) maxretry="${arg#maxretry=}" ;;
      findtime=*) findtime="${arg#findtime=}" ;;
      bantime=*)  bantime="${arg#bantime=}" ;;
      enabled=*)  enabled="${arg#enabled=}" ;;
      *) die "unknown setting: $arg" ;;
    esac
  done
  [[ "$maxretry" =~ ^[0-9]+$ ]] && (( maxretry >= 1 && maxretry <= 100 )) || die "maxretry must be 1-100"
  [[ "$findtime" =~ ^[0-9]+[smhd]?$ ]] || die "invalid findtime"
  [[ "$bantime" =~ ^[0-9]+[smhd]?$ ]] || die "invalid bantime (use e.g. 30m, 1h, 24h — not permanent)"
  local bt_sec
  bt_sec="$(to_seconds "$bantime")" || die "invalid bantime"
  (( bt_sec >= 60 && bt_sec <= 2592000 )) || die "bantime must be between 60s and 30d"
  case "$enabled" in true|false|1|0|yes|no) ;; *) die "enabled must be true/false" ;; esac
  if [[ "$jail" == "$SIP_JAIL" ]]; then
    [[ -f "$SIP_FILTER" ]] || die "filter missing: $SIP_FILTER"
    write_sip_jail "$maxretry" "$findtime" "$bantime" "$enabled"
  else
    [[ -f "$PORTAL_FILTER" ]] || die "filter missing: $PORTAL_FILTER (run deploy/fix-portal-firewall.sh)"
    write_portal_jail "$maxretry" "$findtime" "$bantime" "$enabled"
  fi
  fail2ban-client reload >/dev/null 2>&1 || systemctl reload fail2ban >/dev/null 2>&1 || systemctl restart fail2ban
  sleep 1
  local refresh=0
  [[ "$bantime" != "$old_bantime" ]] && refresh=1
  apply_live_jail "$jail" "$maxretry" "$findtime" "$bantime" "$refresh"
  echo "OK config saved jail=${jail} (bantime=${bantime}, auto-unban enabled)"
  cmd_config "$jail"
}

cmd_reload() {
  need_f2b
  fail2ban-client reload >/dev/null 2>&1 || systemctl reload fail2ban
  echo "OK reloaded"
}

cmd_ip_log() {
  local ip="${1:-}"
  [[ -n "$ip" ]] || die "usage: ip-log <ip>"
  {
    grep -F "$ip" "$SIP_AUTH_LOG" 2>/dev/null \
      | grep -iE 'auth failure|find user|auth failed' \
      | tail -n 20 || true
    grep -F "ownswitch-sip" /var/log/auth.log 2>/dev/null \
      | grep -F "$ip" \
      | tail -n 20 || true
    journalctl -u opensips --no-pager -n 400 2>/dev/null \
      | grep -F "$ip" \
      | grep -iE 'auth failed|REGISTER|INVITE|Forbidden|Unauthorized' \
      | tail -n 10 || true
    journalctl -t ownswitch-portal --no-pager -n 400 2>/dev/null \
      | grep -F "$ip" \
      | tail -n 20 || true
    journalctl --identifier=ownswitch-portal --no-pager -n 400 2>/dev/null \
      | grep -F "$ip" \
      | tail -n 20 || true
  } | awk 'NF' | tail -n 40 || true
}

cmd_geo() {
  local ip="${1:-}"
  [[ -n "$ip" ]] || die "usage: geo <ip>"
  local raw country code city isp
  country=""; code=""; city=""; isp=""
  raw="$(curl -fsSL --max-time 3 "http://ip-api.com/json/${ip}?fields=status,country,countryCode,city,isp" 2>/dev/null || true)"
  if [[ -n "$raw" ]] && echo "$raw" | grep -q '"status":"success"'; then
    country="$(echo "$raw" | sed -n 's/.*"country":"\([^"]*\)".*/\1/p' | head -1)"
    code="$(echo "$raw" | sed -n 's/.*"countryCode":"\([^"]*\)".*/\1/p' | head -1)"
    city="$(echo "$raw" | sed -n 's/.*"city":"\([^"]*\)".*/\1/p' | head -1)"
    isp="$(echo "$raw" | sed -n 's/.*"isp":"\([^"]*\)".*/\1/p' | head -1)"
  fi
  if [[ -z "$country" ]]; then
    raw="$(curl -fsSL --max-time 3 "https://ipwho.is/${ip}" 2>/dev/null || true)"
    if [[ -n "$raw" ]] && echo "$raw" | grep -q '"success":true'; then
      country="$(echo "$raw" | sed -n 's/.*"country":"\([^"]*\)".*/\1/p' | head -1)"
      code="$(echo "$raw" | sed -n 's/.*"country_code":"\([^"]*\)".*/\1/p' | head -1)"
      city="$(echo "$raw" | sed -n 's/.*"city":"\([^"]*\)".*/\1/p' | head -1)"
    fi
  fi
  echo "ip=${ip}"
  echo "country=${country:-Unknown}"
  echo "countryCode=${code:-}"
  echo "city=${city:-}"
  echo "isp=${isp:-}"
}

case "${1:-}" in
  status-all) shift; cmd_status_all "$@" ;;
  status)     shift; cmd_status "$@" ;;
  banned)     shift; cmd_banned "$@" ;;
  unban)      shift; cmd_unban "$@" ;;
  ban)        shift; cmd_ban "$@" ;;
  config)     shift; cmd_config "$@" ;;
  set-config) shift; cmd_set_config "$@" ;;
  reload)     shift; cmd_reload "$@" ;;
  ensure-sip) shift; cmd_ensure_sip "$@" ;;
  ip-log)     shift; cmd_ip_log "$@" ;;
  geo)        shift; cmd_geo "$@" ;;
  *)
    echo "usage: $0 {status-all|status|banned|unban|ban|config|set-config|reload|ensure-sip|ip-log|geo}" >&2
    exit 2
    ;;
esac
