# Managed by Ownswitch installer (deploy/stages/30_mysql.sh) - do not edit by hand.
# Rendered from deploy/templates/mysql-ownswitch.cnf.tpl
[mysqld]
# --- network: loopback only. MySQL must never be reachable from outside. ---
bind-address            = 127.0.0.1
mysqlx                  = OFF
local_infile            = OFF

# --- charset / time ---
character-set-server    = utf8mb4
default-time-zone       = '+00:00'

# --- authentication ---
# MySQL 8.4 disables mysql_native_password by default. It is re-enabled ONLY so the
# `opensips` service account (libmariadb client inside OpenSIPS) can authenticate.
# All other accounts use caching_sha2_password. Set MYSQL_OPENSIPS_AUTH_PLUGIN=caching_sha2_password
# and re-run stage 30 to drop this requirement if your OpenSIPS build supports it.
mysql_native_password   = @@MYSQL_NATIVE_PASSWORD_SWITCH@@

# --- capacity ---
# 2000-3000 live calls. Writes arrive at hangup rate, not one connection per call.
# 500 covers the PHP pool plus admin sessions. The 8 GB buffer pool stays ~25% of a
# 32 GB host so FreeSWITCH keeps the rest for RTP. A 1 GB redo log stops checkpoint
# stalls when many CDRs commit together. Durability stays on (flush every commit).
max_connections         = 500
innodb_buffer_pool_size = @@MYSQL_BUFFER_POOL_MB@@M
innodb_redo_log_capacity = 1G
innodb_flush_log_at_trx_commit = 1
sync_binlog             = 1
innodb_flush_method     = O_DIRECT
innodb_io_capacity      = 10000
innodb_io_capacity_max  = 20000
table_open_cache        = 8000
tmp_table_size          = 64M
max_heap_table_size     = 64M

# --- binary log: kept 7 days for point-in-time recovery (see docs/BACKUP.md) ---
binlog_expire_logs_seconds = 604800
max_binlog_size         = 256M