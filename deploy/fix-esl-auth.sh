#!/usr/bin/env bash
# =============================================================================
# Sync FreeSWITCH ESL password across secret → event_socket.xml → ownswitch.env
# Fixes portal "ESL authentication failed" on Calls Online / System Status.
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-esl-auth.sh \
#     | sudo bash
# =============================================================================
set -Eeuo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_ETC="${OWNSWITCH_ETC:-/etc/ownswitch}"
SECRET="${OWN_ETC}/secrets/freeswitch_esl_password"
ENVF="${OWN_ETC}/ownswitch.env"
FS_CONF="${FREESWITCH_CONF:-/usr/local/freeswitch/conf}"
ESL_XML="${FS_CONF}/autoload_configs/event_socket.conf.xml"

log() { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }
die() { printf 'FATAL: %s\n' "$*" >&2; exit 1; }

[[ -f "$ESL_XML" ]] || die "Missing ${ESL_XML} — is FreeSWITCH installed?"
[[ -f "$ENVF" ]] || die "Missing ${ENVF}"

# Prefer existing secret; else parse what FreeSWITCH is currently using; else generate.
PASS=""
if [[ -s "$SECRET" ]]; then
  PASS="$(tr -d '\r\n' <"$SECRET")"
  log "Using secret file ${SECRET}"
fi
if [[ -z "$PASS" ]]; then
  PASS="$(sed -n 's/.*name="password"[[:space:]]*value="\([^"]*\)".*/\1/p' "$ESL_XML" | head -1)"
  log "Parsed password from event_socket.xml"
fi
if [[ -z "$PASS" ]]; then
  PASS="$(openssl rand -hex 20)"
  log "Generated new ESL password"
fi
(( ${#PASS} >= 8 )) || die "ESL password too short"

install -d -m 0700 "${OWN_ETC}/secrets"
printf '%s' "$PASS" >"$SECRET"
chmod 0600 "$SECRET"

# Write event_socket.xml (hex-safe; no XML entity issues)
cat >"$ESL_XML" <<EOF
<configuration name="event_socket.conf" description="Socket Client">
  <!-- Synced by deploy/fix-esl-auth.sh -->
  <settings>
    <param name="nat-map" value="false"/>
    <param name="listen-ip" value="127.0.0.1"/>
    <param name="listen-port" value="8021"/>
    <param name="password" value="${PASS}"/>
    <param name="apply-inbound-acl" value="loopback.auto"/>
  </settings>
</configuration>
EOF
chown freeswitch:freeswitch "$ESL_XML" 2>/dev/null || chown root:root "$ESL_XML"
chmod 0640 "$ESL_XML"

# Upsert ownswitch.env keys the portal reads
upsert() {
  local key=$1 val=$2 tmp
  tmp="$(mktemp)"
  if grep -q "^${key}=" "$ENVF"; then
    awk -v k="$key" -v v="$val" 'BEGIN{FS=OFS="="} $1==k {$0=k "=" v} {print}' "$ENVF" >"$tmp"
  else
    cp "$ENVF" "$tmp"
    printf '%s=%s\n' "$key" "$val" >>"$tmp"
  fi
  cat "$tmp" >"$ENVF"
  rm -f "$tmp"
}
upsert FREESWITCH_ENABLED 1
upsert FREESWITCH_ESL_HOST 127.0.0.1
upsert FREESWITCH_ESL_PORT 8021
upsert FREESWITCH_ESL_PASSWORD "$PASS"
upsert FREESWITCH_HOST 127.0.0.1
upsert FREESWITCH_PORT 8021
upsert FREESWITCH_PASSWORD "$PASS"
upsert FREESWITCH_CONF "$FS_CONF"
chmod 0640 "$ENVF"
chown root:ownswitch "$ENVF" 2>/dev/null || true

log "Restarting FreeSWITCH to load ESL password…"
systemctl restart freeswitch
for i in $(seq 1 30); do
  if ss -H -ltn 'sport = :8021' | grep -q 127.0.0.1; then break; fi
  sleep 1
done
ss -H -ltn 'sport = :8021' | grep -q 127.0.0.1 || die "ESL port 8021 not listening"

# Prefer fs_cli if present
if [[ -x /usr/local/freeswitch/bin/fs_cli ]]; then
  if /usr/local/freeswitch/bin/fs_cli -H 127.0.0.1 -P 8021 -p "$PASS" -x status >/tmp/fs-esl-test.txt 2>&1; then
    log "fs_cli auth OK"
  else
    log "fs_cli failed:"; cat /tmp/fs-esl-test.txt >&2 || true
    die "ESL still rejecting password after sync"
  fi
fi

# PHP ESL smoke test (same path as portal)
php <<'PHP' || die "PHP ESL smoke test failed"
<?php
$env = '/etc/ownswitch/ownswitch.env';
$vars = [];
foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $vars[trim($k)] = trim($v);
}
$host = $vars['FREESWITCH_ESL_HOST'] ?? '127.0.0.1';
$port = (int) ($vars['FREESWITCH_ESL_PORT'] ?? 8021);
$pass = $vars['FREESWITCH_ESL_PASSWORD'] ?? ($vars['FREESWITCH_PASSWORD'] ?? '');
$sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $err, 3);
if (!$sock) { fwrite(STDERR, "connect failed: $err\n"); exit(1); }
stream_set_timeout($sock, 3);
$read = static function () use ($sock) {
    $h = [];
    while (($line = fgets($sock)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '') { if ($h) break; continue; }
        [$k, $v] = array_pad(explode(':', $line, 2), 2, '');
        $h[trim($k)] = trim($v);
    }
    return $h;
};
$hello = $read();
fwrite($sock, "auth {$pass}\n\n");
$reply = $read();
$ok = str_starts_with(trim($reply['Reply-Text'] ?? ''), '+OK');
fwrite(STDERR, $ok ? "PHP ESL auth OK\n" : ("PHP ESL auth FAIL: " . ($reply['Reply-Text'] ?? json_encode($reply)) . "\n"));
exit($ok ? 0 : 1);
PHP

systemctl reload php8.3-fpm || systemctl restart php8.3-fpm
log "PHP-FPM reloaded"

echo
echo "OK — ESL password synced. Reload Calls Online in the portal."
echo "  Secret:  ${SECRET}"
echo "  Env:     FREESWITCH_ESL_PASSWORD in ${ENVF}"
echo "  FS XML:  ${ESL_XML}"
