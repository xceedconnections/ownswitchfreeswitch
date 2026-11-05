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
max_connections         = 300
innodb_buffer_pool_size = @@MYSQL_BUFFER_POOL_MB@@M
innodb_flush_log_at_trx_commit = 1
sync_binlog             = 1

# --- binary log: kept 7 days for point-in-time recovery (see docs/BACKUP.md) ---
binlog_expire_logs_seconds = 604800
max_binlog_size         = 256M