#!/usr/bin/env bash
# Manage per-SIP-user firewall allow rules for IP-based peers (firewalld or ufw).
# Usage:
#   ownswitch-sip-peer-fw sync <username> <ip[,ip2,...]> <peer_port>
#   ownswitch-sip-peer-fw remove <username>
#   ownswitch-sip-peer-fw list
set -euo pipefail

STATE_DIR=/var/lib/ownswitch/sip-peer-fw
SIP_LISTEN="${SIP_PORT:-5060}"

die() { echo "ERR:$1" >&2; exit 1; }

safe_user() {
  local u="${1:-}"
  [[ "$u" =~ ^[A-Za-z0-9._-]{1,64}$ ]] || die "invalid username"
  echo "$u"
}

safe_ip() {
  local ip="${1:-}"
  [[ "$ip" =~ ^[0-9a-fA-F:.]+$ ]] || die "invalid ip"
  echo "$ip"
}

# Comma-separated IPs → validated list (space-separated for loops)
safe_ips() {
  local raw="${1:-}" out=() one
  IFS=',' read -r -a parts <<< "$raw"
  for one in "${parts[@]}"; do
    one="$(echo "$one" | tr -d '[:space:]')"
    [[ -n "$one" ]] || continue
    out+=("$(safe_ip "$one")")
  done
  ((${#out[@]} > 0)) || die "invalid ip list"
  echo "${out[*]}"
}

safe_port() {
  local p="${1:-0}"
  [[ "$p" =~ ^[0-9]+$ ]] || die "invalid port"
  (( p >= 0 && p <= 65535 )) || die "invalid port"
  echo "$p"
}

backend() {
  if command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active --quiet firewalld 2>/dev/null; then
    echo firewalld
  elif command -v ufw >/dev/null 2>&1; then
    echo ufw
  else
    echo none
  fi
}

mkdir -p "$STATE_DIR"
chmod 0750 "$STATE_DIR" 2>/dev/null || true

state_file() { echo "$STATE_DIR/$(safe_user "$1").env"; }

read_state() {
  local f
  f="$(state_file "$1")"
  IP=""; IPS=""; PORTS=""
  [[ -f "$f" ]] || return 1
  # shellcheck disable=SC1090
  source "$f"
  return 0
}

write_state() {
  local user="$1" ips="$2" ports="$3"
  local f
  f="$(state_file "$user")"
  # IPS= comma list; IP= first (legacy)
  local first="${ips%%,*}"
  cat >"$f" <<EOF
IP=${first}
IPS=${ips}
PORTS=${ports}
EOF
  chmod 0640 "$f" 2>/dev/null || true
}

clear_state() {
  rm -f "$(state_file "$1")" 2>/dev/null || true
}

# --- firewalld ---
fw_remove_rule() {
  local ip="$1" port="$2" proto="$3"
  local rule
  rule="rule family=\"ipv4\" source address=\"${ip}\" port port=\"${port}\" protocol=\"${proto}\" accept"
  firewall-cmd --permanent --remove-rich-rule="${rule}" >/dev/null 2>&1 || true
  if [[ "$ip" == *:* ]]; then
    rule="rule family=\"ipv6\" source address=\"${ip}\" port port=\"${port}\" protocol=\"${proto}\" accept"
    firewall-cmd --permanent --remove-rich-rule="${rule}" >/dev/null 2>&1 || true
  fi
}

fw_add_rule() {
  local ip="$1" port="$2" proto="$3"
  local rule fam
  if [[ "$ip" == *:* ]]; then
    fam=ipv6
  else
    fam=ipv4
  fi
  rule="rule family=\"${fam}\" source address=\"${ip}\" port port=\"${port}\" protocol=\"${proto}\" accept"
  firewall-cmd --permanent --add-rich-rule="${rule}" >/dev/null 2>&1 || true
}

fw_remove_user() {
  local user="$1"
  local ip p ips
  read_state "$user" || return 0
  ips="${IPS:-$IP}"
  for ip in ${ips//,/ }; do
    [[ -n "$ip" ]] || continue
    for p in ${PORTS//,/ }; do
      [[ -n "$p" ]] || continue
      fw_remove_rule "$ip" "$p" udp
      fw_remove_rule "$ip" "$p" tcp
    done
  done
  clear_state "$user"
}

fw_sync_user() {
  local user="$1" ip_list="$2" peer_port="$3"
  local ports=() p ip ips_csv
  fw_remove_user "$user"
  ports+=("$SIP_LISTEN")
  if [[ "$peer_port" != "0" && "$peer_port" != "$SIP_LISTEN" ]]; then
    ports+=("$peer_port")
  fi
  for ip in $ip_list; do
    for p in "${ports[@]}"; do
      fw_add_rule "$ip" "$p" udp
      fw_add_rule "$ip" "$p" tcp
    done
  done
  firewall-cmd --reload >/dev/null 2>&1 || true
  local joined
  joined=$(IFS=,; echo "${ports[*]}")
  ips_csv=$(echo "$ip_list" | tr ' ' ',')
  write_state "$user" "$ips_csv" "$joined"
  echo "OK firewalld sync user=${user} ips=${ips_csv} ports=${joined}"
}

# --- ufw ---
ufw_delete_comment() {
  local comment="$1"
  local nums line n
  # Delete highest rule numbers first
  mapfile -t nums < <(ufw status numbered 2>/dev/null | grep -F "$comment" | sed -n 's/^\[\s*\([0-9]\+\)\].*/\1/p' | sort -rn || true)
  for n in "${nums[@]:-}"; do
    [[ -n "$n" ]] || continue
    yes | ufw delete "$n" >/dev/null 2>&1 || true
  done
}

ufw_remove_user() {
  local user="$1"
  local comment="ownswitch-peer:${user}"
  ufw_delete_comment "$comment"
  clear_state "$user"
}

ufw_sync_user() {
  local user="$1" ip_list="$2" peer_port="$3"
  local comment="ownswitch-peer:${user}"
  local ports=() p ip ips_csv
  ufw_remove_user "$user"
  ports+=("$SIP_LISTEN")
  if [[ "$peer_port" != "0" && "$peer_port" != "$SIP_LISTEN" ]]; then
    ports+=("$peer_port")
  fi
  for ip in $ip_list; do
    for p in "${ports[@]}"; do
      ufw allow from "$ip" to any port "$p" proto udp comment "$comment" >/dev/null 2>&1 || true
      ufw allow from "$ip" to any port "$p" proto tcp comment "$comment" >/dev/null 2>&1 || true
    done
  done
  local joined
  joined=$(IFS=,; echo "${ports[*]}")
  ips_csv=$(echo "$ip_list" | tr ' ' ',')
  write_state "$user" "$ips_csv" "$joined"
  echo "OK ufw sync user=${user} ips=${ips_csv} ports=${joined}"
}

cmd_sync() {
  local user ip_list peer_port be
  user="$(safe_user "${1:-}")"
  ip_list="$(safe_ips "${2:-}")"
  peer_port="$(safe_port "${3:-0}")"
  be="$(backend)"
  case "$be" in
    firewalld) fw_sync_user "$user" "$ip_list" "$peer_port" ;;
    ufw)       ufw_sync_user "$user" "$ip_list" "$peer_port" ;;
    *) die "no firewalld/ufw backend" ;;
  esac
}

cmd_remove() {
  local user be
  user="$(safe_user "${1:-}")"
  be="$(backend)"
  case "$be" in
    firewalld) fw_remove_user "$user"; firewall-cmd --reload >/dev/null 2>&1 || true; echo "OK firewalld removed ${user}" ;;
    ufw)       ufw_remove_user "$user"; echo "OK ufw removed ${user}" ;;
    *) clear_state "$user"; echo "OK state cleared ${user} (no firewall backend)" ;;
  esac
}

cmd_list() {
  local f base
  echo "backend=$(backend)"
  echo "sip_listen=${SIP_LISTEN}"
  for f in "$STATE_DIR"/*.env; do
    [[ -f "$f" ]] || continue
    base="$(basename "$f" .env)"
    # shellcheck disable=SC1090
    source "$f"
    echo "user=${base} ips=${IPS:-${IP:-}} ports=${PORTS:-}"
  done
}

case "${1:-}" in
  sync)   shift; cmd_sync "$@" ;;
  remove) shift; cmd_remove "$@" ;;
  list)   shift; cmd_list "$@" ;;
  *)
    echo "usage: $0 {sync|remove|list}" >&2
    exit 2
    ;;
esac
