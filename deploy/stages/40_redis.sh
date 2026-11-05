#!/usr/bin/env bash
# Stage 40 - Redis (CGRateS DataDB). Loopback only, password protected, AOF persistence.
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
stage_init "40_redis"

apt_install redis-server redis-tools

export REDIS_PASSWORD
REDIS_PASSWORD="$(ensure_secret redis_password 48)"

render_template "${TEMPLATE_DIR}/redis-ownswitch.conf.tpl" /etc/redis/ownswitch.conf 0640 redis redis
conf_changed="${RENDER_CHANGED}"

before="$(md5sum /etc/redis/redis.conf | cut -d' ' -f1)"
ensure_line /etc/redis/redis.conf "include /etc/redis/ownswitch.conf"
after="$(md5sum /etc/redis/redis.conf | cut -d' ' -f1)"
[[ "$before" == "$after" ]] || conf_changed=1

# Ubuntu ships redis-server.service (alias redis.service)
svc_apply redis-server "${conf_changed}"

# The packaged unit's hardening may block writes depending on version; verify with a round trip.
export REDISCLI_AUTH="${REDIS_PASSWORD}"
[[ "$(redis-cli -h 127.0.0.1 ping 2>/dev/null || true)" == "PONG" ]] || die "Redis did not answer PING with the generated password"
redis-cli -h 127.0.0.1 config get appendonly | tail -n1 | grep -qx yes || log_warn "appendonly is not enabled"

# Unauthenticated access must fail
unset REDISCLI_AUTH
if [[ "$(redis-cli -h 127.0.0.1 ping 2>&1 || true)" == "PONG" ]]; then
  die "Redis answers without authentication!"
fi

# Verify loopback-only listener
if ss -H -ltn 'sport = :6379' | awk '{print $4}' | grep -vqE '^(127\.0\.0\.1|\[::1\]):6379$'; then
  die "Redis is listening on a non-loopback address!"
fi

log_ok "Redis ready (DataDB index for CGRateS: ${REDIS_CGR_DB})"
