# Managed by Ownswitch installer (deploy/stages/40_redis.sh) - do not edit by hand.
# Included at the END of /etc/redis/redis.conf so these values win.
#
# Redis here is CGRateS DataDB (accounts, balances, rating cache). Losing it loses
# balances, so: persistence ON, no eviction, loopback only, password required.

bind 127.0.0.1 -::1
port 6379
protected-mode yes
requirepass @@REDIS_PASSWORD@@

# Durability
appendonly yes
appendfsync everysec

# Never silently drop balance data
maxmemory-policy noeviction

tcp-keepalive 60
timeout 0
databases 16
