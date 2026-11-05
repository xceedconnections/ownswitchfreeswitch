# Ownswitch — Backup & Restore

## What is protected

| Data | Where | Why it matters | In `ownswitch-backup`? |
|---|---|---|---|
| `ownswitch` DB | MySQL | tenants, ledger, tariffs, unified CDRs, audit | ✅ `mysqldump --single-transaction` |
| `opensips` DB | MySQL | subscribers, address (IP auth), dialogs, drouting | ✅ |
| `cgrates` DB | MySQL | CGRateS StorDB (TP versioning, CDRs) | ✅ |
| Redis DB 10 | Redis | **CGRateS balances/accounts** (loss = money) | ✅ `redis-cli --rdb` snapshot |
| `/etc/ownswitch` | files | secrets, env file, install settings | ✅ (archive is encrypted) |
| `/etc/cgrates`, `/etc/opensips`, `/etc/rtpengine`, nginx vhost, MySQL/Redis drop-ins, TLS, ufw rules, units | files | rebuild the host exactly | ✅ |
| Call recordings | `/var/lib/ownswitch/recordings` | large; policy-dependent | ❌ default, ✅ with `BACKUP_INCLUDE_RECORDINGS=1` |
| Application releases | `/opt/ownswitch/releases` | reproducible from git | ❌ |
| Binary log (PITR) | `/var/lib/mysql/binlog.*` | point-in-time recovery, 7-day retention | ❌ copy off-host separately if you need PITR |

## How it runs

Installed by stage 95: `/usr/local/sbin/ownswitch-backup`, scheduled by `ownswitch-backup.timer`
(daily ≈ 02:30 + random delay, `Persistent=true`). Logs: `/var/log/ownswitch/backup.log`.

```bash
systemctl list-timers ownswitch-backup.timer
sudo ownswitch-backup                  # run now
sudo journalctl -u ownswitch-backup -n 50 --no-pager
```

Output: `/var/backups/ownswitch/ownswitch-<host>-<UTC timestamp>.tar.gz.enc` + `.sha256`
(AES-256-CBC, PBKDF2 600 000 iterations, passphrase file `/etc/ownswitch/secrets/backup_passphrase`).
The script checks free space first, verifies that the archive decrypts and lists correctly, prunes older than
`RETENTION_DAYS` (14), and optionally `rsync`s off-site.

Settings: `/etc/ownswitch/backup.conf` (`BACKUP_DIR`, `RETENTION_DAYS`, `BACKUP_RSYNC_TARGET`, `BACKUP_INCLUDE_RECORDINGS`).

### Rules

1. **Copy `backup_passphrase` offline** (password manager/safe) the day you install. A backup you cannot decrypt is not a backup.
   The passphrase also exists *inside* old archives' `/etc/ownswitch/secrets` only after decryption — it cannot unlock itself.
2. **Off-site copy** is mandatory for production: set `BACKUP_RSYNC_TARGET` (SSH key for root, `BatchMode`) or ship `/var/backups/ownswitch` with your own tooling.
3. **Test restores quarterly** (below). Record the date.
4. Rotating the passphrase does not re-encrypt old archives — keep old passphrases with the matching archives.

## Restore (full host)

1. Build a clean Ubuntu 24.04 host and run the installer **with the same domain/IP settings** to get the software stack:
   `sudo -E ./deploy/install.sh --yes --skip 75` (add 75 if FreeSWITCH was enabled). Don't create live traffic yet.
2. Copy the archive and **the passphrase** to the host, then decrypt:

```bash
install -d -m 0700 /root/restore && cd /root/restore
printf '%s' 'THE-OFFLINE-PASSPHRASE' > pass && chmod 600 pass
sha256sum -c ownswitch-*.tar.gz.enc.sha256
openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -pass file:pass -in ownswitch-HOST-TS.tar.gz.enc | tar -xz
cd ownswitch-HOST-TS && ls
```

3. Stop consumers, restore databases (the dumps contain `CREATE DATABASE` + `DROP/CREATE TABLE`):

```bash
sudo systemctl stop ownswitch-worker ownswitch-scheduler.timer opensips cgrates php8.3-fpm
for db in ownswitch opensips cgrates; do sudo mysql -uroot < mysql/$db.sql; done
```

4. Restore Redis (CGRateS DataDB):

```bash
sudo systemctl stop redis-server cgrates
sudo cp redis/dump.rdb /var/lib/redis/dump.rdb && sudo chown redis:redis /var/lib/redis/dump.rdb
# AOF takes precedence over the RDB at start-up: remove it so the snapshot is loaded
sudo rm -rf /var/lib/redis/appendonlydir /var/lib/redis/appendonly.aof
sudo systemctl start redis-server
```

   Redis will recreate an AOF from the loaded dataset (`appendonly yes`).

5. Restore secrets/config **only if the new host has different generated secrets** (it will, unless you restore them):
   the DB users on the new host were created with *new* passwords, so either
   (a) restore `/etc/ownswitch/secrets` + configs from `files/config.tar` and re-run `sudo ./deploy/install.sh --only 30,40,60,50,90,99`
   (the stages ALTER USER / re-render configs to match the restored secrets), or
   (b) keep the new secrets (do nothing) — the restored data contains no application passwords except the OpenSIPS HA1 hashes and portal users.

```bash
sudo tar --absolute-names -xpf files/config.tar -C / etc/ownswitch/secrets     # option (a): secrets only
```

6. Start and verify:

```bash
sudo systemctl start cgrates opensips php8.3-fpm ownswitch-worker ownswitch-scheduler.timer
sudo ./deploy/install.sh --only 99
```

7. Reconcile: compare the newest CDR timestamp in `ownswitch.cdrs` vs `cgrates.cdrs`; replay/reprocess the gap with the portal’s
   CDR-ingest tooling (idempotent ingest keys) before re-enabling customer traffic.

## Point-in-time recovery (optional)

Binary logs are enabled (`binlog_expire_logs_seconds=604800`). After restoring the last dump:

```bash
sudo mysqlbinlog --start-datetime="2026-01-01 00:00:00" --stop-datetime="2026-01-01 12:34:00" /path/to/binlog.00000N | sudo mysql -uroot
```

Ship binlogs off-host (e.g. `rsync /var/lib/mysql/binlog.* …`) if your RPO is tighter than 24 h.
Redis AOF (`everysec`) bounds balance loss to ~1 s on a crash, but only a snapshot protects against host loss.

## Quick manual backups

```bash
sudo mysqldump --single-transaction --routines --events --triggers --databases ownswitch | gzip > /root/ownswitch-$(date +%F).sql.gz
sudo REDISCLI_AUTH="$(sudo cat /etc/ownswitch/secrets/redis_password)" redis-cli --rdb /root/redis-$(date +%F).rdb
```

Treat any file produced this way as secret (they bypass the encryption step).
