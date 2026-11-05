# Ownswitch — Troubleshooting

First look: `sudo cat /var/lib/ownswitch-install/reports/latest.txt` and `/var/log/ownswitch-install.log`.
Every stage can be re-run alone: `sudo -E ./deploy/install.sh --only <NN>`.

## Installer

| Symptom | Cause / fix |
|---|---|
| `Ownswitch requires Ubuntu Server 24.04` | wrong OS. Only noble/amd64 is validated (`OWNSWITCH_SKIP_OS_CHECK=1` = unsupported) |
| `Only N MB RAM` / disk / CPU | stage 10 minimums; use `FORCE=1` only for lab hosts |
| `Clock is not synchronised` | `chronyc tracking`; outbound UDP 123 blocked? |
| `DNS cannot resolve …` / `HTTPS to apt.opensips.org failed` | outbound DNS/proxy; set `http_proxy`/`https_proxy` for apt and curl |
| `bad interpreter: /usr/bin/env bash^M` | CRLF line endings from Windows: `find deploy -type f -exec sed -i 's/\r$//' {} +` (`deploy/.gitattributes` prevents this in git) |
| `unresolved placeholder: X` | a template uses `@@X@@` but the stage did not export `X`; stage never writes a half-rendered file |
| lock errors from apt | `unattended-upgrades` running; the installer waits up to 10 min (`DPkg::Lock::Timeout`) |
| Re-run overwrote my manual config | files managed by the installer are backed up in `/var/backups/ownswitch-config/` |

## MySQL (stage 30)

* **`MySQL … expected 8.4.x`**: Ubuntu's 8.0 is installed. Back up, remove `mysql-server-8.0`, re-run (installer never touches existing data).
* **candidate version not 8.4**: `apt-cache policy mysql-server`; check `/etc/apt/sources.list.d/mysql*.list`; run with `MYSQL_INSTALL_METHOD=direct`.
* **`Cannot connect as root via auth_socket`**: run `sudo mysql` (not `-p`). If a root password was set manually, restore socket auth:
  `ALTER USER 'root'@'localhost' IDENTIFIED WITH auth_socket;`
* **OpenSIPS `Access denied` / auth plugin error**: confirm `SELECT user,host,plugin FROM mysql.user WHERE user='opensips';` shows
  `mysql_native_password` and `mysql_native_password=ON` is in `/etc/mysql/mysql.conf.d/99-ownswitch.cnf`.
* Logs: `journalctl -u mysql`, `/var/log/mysql/error.log`.

## Redis (stage 40)

* `NOAUTH Authentication required` is expected without a password: `REDISCLI_AUTH=$(sudo cat /etc/ownswitch/secrets/redis_password) redis-cli ping`.
* CGRateS cannot connect: password mismatch after rotation → `--only 40,60`.

## OpenSIPS (stage 50)

```bash
sudo opensips -c -f /etc/opensips/opensips.cfg      # syntax + module load check
sudo journalctl -u opensips -n 100 --no-pager
sudo opensips-cli -x mi get_statistics dialog:      # if opensips-cli is installed
# MI endpoints: FIFO /run/opensips/opensips_fifo (and http://127.0.0.1:8888/mi when mi_http is installed)
```

| Symptom | Fix |
|---|---|
| `Generated OpenSIPS configuration failed syntax check` | the live config is untouched; read the printed error. Typical: module package missing (`opensips-cgrates-module`), module param renamed in 3.6.x — adjust `templates/opensips.cfg.tpl` |
| `cannot open shared object … cgrates.so` | `apt-get install opensips-cgrates-module`; verify series `apt policy opensips` shows `3.6` |
| `ERROR:db_mysql … connect` | MySQL user/password; test: `mysql -uopensips -p -h127.0.0.1 opensips` (password in `/etc/ownswitch/secrets/mysql_opensips_password`) |
| calls rejected `403 Billing Authorization Failed` | CGRateS unreachable/refusing: `cgrates` active? `ss -ltn sport = :2014`; check account/rating data; this is fail-closed by design |
| calls rejected `503 No Route` | no `dr_rules`/`dr_gateways` loaded: `opensips-cli -x mi dr_reload` after provisioning |
| IP-auth customer gets `407` | missing row in `address` (group 1): provision it, then `mi address_reload` |
| no audio / one-way | RTPengine: `systemctl status rtpengine-daemon`, RTP range open in ufw **and** in the cloud security group, `PUBLIC_IP`/`PRIVATE_IP` correct for NAT |
| stock config running after package upgrade | `--only 50` re-renders and validates ours |

## CGRateS (stage 60)

```bash
sudo journalctl -u cgrates -n 100 --no-pager
cgr-engine -version
curl -s -d '{"method":"CoreSv1.Ping","params":[{}],"id":1}' http://127.0.0.1:2080/jsonrpc
sudo -u cgrates cgr-engine -config_path=/etc/cgrates -check_config   # validates the JSON
```

| Symptom | Fix |
|---|---|
| Go build fails (module/Go version) | Ubuntu's `golang-go` too old/new for the tag: install the Go version from the tag's `go.mod`, or use `CGRATES_BIN_TARBALL_URL` with pre-built binaries |
| `Migration needed` / version errors at start | `sudo cgr-migrator -config_path=/etc/cgrates -exec='*set_versions'` (fresh DBs) or the `*migrate` flow for upgrades (UPGRADE.md §5) |
| `Table 'cgrates.cdrs' doesn't exist` | stage 60 schema step skipped: `mysql -uroot cgrates < /usr/share/cgrates/storage/mysql/create_cdrs_tables.sql`, same for `create_tariffplan_tables.sql` |
| Redis auth error | `db_password` in `/etc/cgrates/cgrates.json` ≠ `redis_password`: re-run `--only 60` |
| OpenSIPS cannot talk to CGRateS | `cgrates_engine` must be the `sessions.listen_bijson` address (127.0.0.1:2014); both must be loopback — firewall does not matter for loopback |
| config rejected | unknown key for your exact CGRateS version: compare with `/usr/share/cgrates/…/conf/samples` or `cgr-engine -print_config` |

## RTPengine (stage 70)

* `journalctl -u rtpengine-daemon -u rtpengine-recording`
* Userspace-only mode (kernel module missing): `Running RTPengine in USERSPACE mode`. Install headers: `apt install linux-headers-$(uname -r)`, then `--only 70`.
* `recording-method=proc requires the kernel module` → set `RTPENGINE_RECORDING_METHOD=pcap`.
* Package names differ (`rtpengine-*` vs `ngcp-rtpengine-*`); the stage detects them. Unit/config path mismatch: see the `units:` log line.
* Control socket test: `echo 'd7:command4:pinge' | nc -u -w1 127.0.0.1 22222` (ng bencode; expects `pong`).

## FreeSWITCH (stage 75, optional)

* Build errors: read the failing `make` output in `/var/log/ownswitch-install.log`; `mod_av` is disabled on purpose (ffmpeg 6 incompatibility).
* `fs_cli -p <password>`: password in `/etc/ownswitch/secrets/freeswitch_esl_password`; ESL listens on `127.0.0.1:8021` only.
* Port clash on 5060: FS profiles must be `5090/5091` (`vars.xml`).

## PHP / nginx / TLS (stage 80)

| Symptom | Fix |
|---|---|
| `502 Bad Gateway` | `systemctl status php8.3-fpm`; socket `/run/php/ownswitch.sock` owned `www-data`; `journalctl -u php8.3-fpm`; app errors in `/var/log/ownswitch/php-fpm-error.log` |
| `503 Ownswitch … not deployed` | placeholder release: put the app in `APP_SRC` and `--only 90` |
| blank page / `open_basedir` errors | the pool restricts to `/opt/ownswitch`, `/var/lib/ownswitch`, `/var/log/ownswitch`, `/usr/share/php`, `/tmp`; extend `templates/php-fpm-pool.conf` if the app needs more |
| code changes not visible | opcache has `validate_timestamps=0`: `systemctl reload php8.3-fpm` (stage 90 does it) |
| `nginx -t` fails | `sudo nginx -t`; vhost is `/etc/nginx/sites-available/ownswitch.conf` |
| Let's Encrypt skipped/failed | domain must resolve to `PUBLIC_IP`, port 80 reachable from the internet; then `LETSENCRYPT_EMAIL=… --only 80`. Rate limits: use `certbot --staging` manually to test |
| browser warning | self-signed bootstrap certificate (expected until LE succeeds or you provide `TLS_CERT_FILE`/`TLS_KEY_FILE`) |

## Firewall

```bash
sudo ufw status numbered
sudo ss -lntup
```

* Locked out of SSH: use the provider console, `ufw allow from <your-ip> to any port 22 proto tcp`. The installer always adds the current SSH client IP when `SSH_ALLOW_CIDRS` is set.
* Provider/cloud security group must open `80,443,SIP_PORT,RTP range`. ufw alone is not enough there.
* `exposure tcp/XXXX … non-loopback` FAIL in the report means a service was reconfigured to listen publicly — fix its bind address, re-run its stage.

## Application / workers (stages 90, 95)

* `ownswitch-worker` shows *condition failed*: `bin/worker.php` not deployed yet (intended).
* Migration failure: `Migration X failed` leaves the **previous** release live; fix the SQL, re-run `--only 90`. Checksums in `schema_migrations` prevent silent edits of applied files.
* `bin/create-admin.php … failed (exit N)`: run it manually as the app user to see the error:
  `printf '%s' "$PASS" | sudo -u ownswitch OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env php /opt/ownswitch/current/bin/create-admin.php --email=… --password-stdin`
* Forgot generated admin password: `/root/ownswitch-initial-admin.txt` (if still present) or re-run `--create-admin` after resetting the user with the app tooling.
* Scheduler/worker logs: `/var/log/ownswitch/{worker,scheduler,backup}.log`, `journalctl -u ownswitch-worker`.

## Secrets

* Regenerate one: delete `/etc/ownswitch/secrets/<name>` and re-run the owning stages (secrets README).
* `secrets permissions` FAIL in the report: `sudo chmod 600 /etc/ownswitch/secrets/*; sudo chmod 700 /etc/ownswitch/secrets`.
* `secret leakage` FAIL: a DB password string exists inside the deployed application tree — remove it from the repo and rotate the secret.

## Collecting a support bundle

```bash
sudo sh -c 'journalctl -u opensips -u cgrates -u mysql -u nginx -u php8.3-fpm -n 500 --no-pager > /var/log/ownswitch/journal-snapshot.log'
sudo tar czf /root/ownswitch-support-$(date +%F).tgz /var/lib/ownswitch-install/reports /var/log/ownswitch-install.log /var/log/ownswitch
```

The report and logs contain **no secret values**; do not add `/etc/ownswitch` or `/etc/cgrates` to a bundle.
