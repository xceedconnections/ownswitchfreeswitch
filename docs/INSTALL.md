# Ownswitch — Installation (Phase 2)

Target: a **clean Ubuntu Server 24.04 LTS (noble), amd64**, single host, root/sudo access, outbound HTTPS.
Everything is installed by idempotent bash stages under `deploy/`. Re-running is always safe.

Stack installed (see `COMPATIBILITY.md`): MySQL 8.4 LTS · Redis · OpenSIPS 3.6 (+ `cgrates`, `mysql`, `drouting`… modules) ·
CGRateS v0.10.4 · RTPengine 11.5.x · PHP 8.3-FPM · nginx · optional FreeSWITCH 1.10.12.

## 1. Quick start

```bash
# on the Ubuntu 24.04 server
sudo apt-get update && sudo apt-get install -y git
git clone <your-ownswitch-repo> /opt/ownswitch-src && cd /opt/ownswitch-src

# Windows checkouts cannot store the exec bit; fix once (install.sh also works via `bash`)
find deploy -type f \( -name '*.sh' -o -path '*/bin/*' \) -exec chmod +x {} +

export OWNSWITCH_DOMAIN=voip.example.com          # DNS A record -> this server
export OWNSWITCH_ADMIN_EMAIL=ops@example.com
export LETSENCRYPT_EMAIL=ops@example.com          # optional; omit for self-signed
export OWN_SWITCH_ADMIN_PASS='a-long-random-passphrase'   # optional; else prompt / auto-generate

sudo -E ./deploy/install.sh            # asks for confirmation; add --yes to skip
```

The run ends with a **deployment report** (`/var/lib/ownswitch-install/reports/latest.txt`) and a non-zero exit
code if any health check failed.

Useful options:

```bash
sudo -E ./deploy/install.sh --list                 # show stages
sudo -E ./deploy/install.sh --only 50,60           # run selected stages
sudo -E ./deploy/install.sh --from 80              # resume after fixing a failure
sudo -E ./deploy/install.sh --skip 75              # skip a stage
sudo -E ./deploy/install.sh --create-admin         # only the first-admin hook
sudo -E ./deploy/install.sh --config my.conf --non-interactive --yes
```

Ansible alternative: `deploy/ansible/playbook.yml` (+ `inventory.example`) runs the same scripts remotely.

## 2. Stages

| Stage | Script | What it does |
|---|---|---|
| 00 | `00_validate_os.sh` | Ubuntu 24.04/noble, amd64, systemd PID 1, no conflicting MySQL 8.0/MariaDB |
| 10 | `10_system_checks.sh` | CPU ≥ 2, RAM ≥ 3.5 GB, disk ≥ 20 GB, DNS, NTP (chrony), UTC, sysctl, base packages, users/dirs, persists settings |
| 20 | `20_firewall.sh` | ufw default-deny; SSH, 80/443, SIP, RTP range only; explicit denies for internal ports; fail2ban(sshd) |
| 30 | `30_mysql.sh` | MySQL **8.4 LTS** via MySQL APT repo; DBs `ownswitch`, `opensips`, `cgrates`; per-DB users |
| 40 | `40_redis.sh` | Redis on loopback, `requirepass`, AOF, `noeviction` |
| 50 | `50_opensips.sh` | Does not install OpenSIPS. SIP is FreeSWITCH (stage 75). Existing hosts: `deploy/remove-opensips.sh` |
| 60 | `60_cgrates.sh` | CGRateS v0.10.4 (source build), Redis DataDB idx 10, MySQL StorDB `cgrates`, systemd unit |
| 70 | `70_rtpengine.sh` | RTPengine + recording daemon, kernel module if possible, loopback ng socket |
| 75 | `75_freeswitch.sh` | FreeSWITCH SIP edge on `SIP_PORT`, ESL 127.0.0.1:8021, xml_curl + xml_cdr. Skipped only when `FREESWITCH_ENABLED=0` |
| 80 | `80_php_nginx.sh` | PHP 8.3-FPM dedicated pool, nginx vhost, self-signed → Let's Encrypt |
| 90 | `90_app_deploy.sh` | release deploy, env file, migrations hook, first admin |
| 95 | `95_workers.sh` | systemd worker/scheduler/backup units, logrotate, `ownswitch-backup` |
| 99 | `99_health_report.sh` | health checks, exposure audit, report |

## 3. Configuration reference

Precedence: **environment** > `--config FILE` > `/etc/ownswitch/install.conf` (persisted after the first run) > defaults.
Only non-secret values go in config files. Edit `/etc/ownswitch/install.conf` to change persisted settings.

| Variable | Default | Meaning |
|---|---|---|
| `OWNSWITCH_DOMAIN` | `hostname -f` | portal FQDN (TLS, nginx `server_name`, SIP realm) |
| `OWNSWITCH_ADMIN_EMAIL` | `admin@$DOMAIN` | first admin login |
| `OWN_SWITCH_ADMIN_PASS` | — | first admin password (also accepted: `OWNSWITCH_ADMIN_PASS`) |
| `LETSENCRYPT_EMAIL` | — | enables certbot when the domain resolves to `PUBLIC_IP` |
| `TLS_CERT_FILE` / `TLS_KEY_FILE` | — | use your own certificate instead |
| `PUBLIC_IP` / `PRIVATE_IP` | detected | NAT-aware SIP/RTP advertisement (`private!public`) |
| `SIP_PORT` | `5060` | OpenSIPS UDP+TCP |
| `RTP_PORT_MIN` / `RTP_PORT_MAX` | `30000` / `40000` | RTPengine media range (opened in ufw) |
| `SIP_ALLOW_CIDRS` | *(any)* | comma list; restrict SIP to known customers/carriers |
| `SSH_ALLOW_CIDRS` | *(any, rate-limited)* | comma list; your current SSH client is auto-added |
| `FREESWITCH_ENABLED` | `0` | `1` builds FreeSWITCH (20–40 min) |
| `CGRATES_VERSION` | `v0.10.4` | git tag built by stage 60 |
| `CGRATES_BIN_TARBALL_URL` / `_SHA256` | — | install pre-built binaries instead of building |
| `CGRATES_TENANT` | `ownswitch.local` | CGRateS default tenant |
| `MYSQL_INSTALL_METHOD` | `apt-config` | `apt-config` or `direct` (see below) |
| `MYSQL_OPENSIPS_AUTH_PLUGIN` | `mysql_native_password` | auth plugin for the `opensips` DB user |
| `RTPENGINE_KERNEL` | `1` | try DKMS kernel module (falls back to userspace) |
| `RTPENGINE_RECORDING_METHOD` | `auto` | `proc` (needs kernel module) / `pcap` |
| `APP_SRC` | repo root | directory containing the PHP app (needs `public/index.php`) |
| `APP_RELEASES_KEEP` | `5` | releases retained for rollback |
| `FORCE` | `0` | `1` downgrades failed resource checks to warnings |
| `HEALTH_FAIL_EXIT` | `1` | exit non-zero if a health check fails |
| `OWNSWITCH_SKIP_OS_CHECK` | `0` | unsupported-OS escape hatch (not recommended) |

## 4. MySQL 8.4 LTS install path (`mysql-apt-config`)

Ubuntu 24.04 ships MySQL **8.0**; Ownswitch needs **8.4 LTS**, so stage 30 uses Oracle's repository:

1. Download `mysql-apt-config_<ver>_all.deb` from `https://dev.mysql.com/get/` (`MYSQL_APT_CONFIG_VERSION`,
   optional `MYSQL_APT_CONFIG_SHA256` pin).
2. Preseed debconf (`mysql-apt-config/select-server = mysql-8.4-lts`) and `dpkg -i` it — the non-interactive
   equivalent of the documented dialog.
3. `apt-get install mysql-server mysql-client`; the installer verifies the candidate version is `8.4.x` and aborts otherwise.
4. Fallback (`MYSQL_INSTALL_METHOD=direct`, also used automatically): write `/etc/apt/sources.list.d/mysql.list`
   with `repo.mysql.com … noble mysql-8.4-lts` and the MySQL signing key.

Notes: `root@localhost` uses `auth_socket` — no root password is created or stored. MySQL listens on `127.0.0.1` only;
X protocol is off. `mysql_native_password` is re-enabled **only** for the `opensips` account (OpenSIPS' client library);
all other accounts use `caching_sha2_password`.

## 5. Databases and accounts

| Database | Account | Purpose |
|---|---|---|
| `ownswitch` | `ownswitch` | portal data, ledger, CDRs |
| `opensips` | `opensips` | OpenSIPS schemas (`standard`, `auth_db`, `usrloc`, `dialog`, `permissions`, `drouting`, …) |
| `cgrates` | `cgrates` (RW), `cgrates_ro` (SELECT) | CGRateS StorDB — never mixed with portal tables |
| Redis idx `10` | — | CGRateS DataDB (balances) |
| Redis idx `1` | — | portal cache (never shared with CGRateS) |

## 6. Network exposure

Public: `80/tcp`, `443/tcp`, `SIP_PORT udp+tcp`, `RTP_PORT_MIN:MAX/udp`, SSH.
Loopback only (bound **and** explicitly denied in ufw): MySQL 3306, Redis 6379, CGRateS 2012/2013/2014/2080,
RTPengine ng 22222/udp, FreeSWITCH ESL 8021, OpenSIPS MI-HTTP 8888, portal internal vhost 8081.
`99_health_report.sh` audits `ss -lntu` and **fails** if any of these is bound to a non-loopback address.

Cloud firewalls/security groups must additionally allow the public ports above.

## 7. Application deployment contract

Stage 90 deploys `APP_SRC` as an immutable release in `/opt/ownswitch/releases/<ts>-<hash>/` and atomically
points `/opt/ownswitch/current` at it. Code is `root:ownswitch` read-only; writable data is
`/var/lib/ownswitch/{storage,sessions,tmp}` and logs `/var/log/ownswitch`.
nginx serves **only** `current/public` via the front controller `public/index.php`.

Configuration reaches the app through `OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env` (outside the web root,
`root:ownswitch 0640`; keys such as `DB_*`, `OPENSIPS_DB_*`, `CGRATES_*`, `REDIS_*`, `RTPENGINE_*`).

Optional app entry points (the installer degrades gracefully if missing; without `public/index.php` a
**placeholder** release is deployed and reported as a WARN):

| File | Purpose |
|---|---|
| `bin/migrate.php` | migration runner (preferred hook). Fallback: `database/migrations/*.sql` applied by the installer with checksums in `schema_migrations` |
| `bin/create-admin.php --email=E --password-stdin [--force-password-change]` | first admin; exit `0` created, `10` already exists |
| `bin/worker.php` | long-running worker → `ownswitch-worker.service` |
| `bin/console schedule:run` | scheduler tick every minute → `ownswitch-scheduler.timer` |
| `POST /internal/recordings/notify` | RTPengine callback (only reachable on `127.0.0.1:8081`, token in env) |

### First admin

Order of precedence: `OWN_SWITCH_ADMIN_PASS` env → interactive prompt (hidden, min 12 chars, confirmed) →
non-interactive fallback: random password written once to `/root/ownswitch-initial-admin.txt` (`0600`) with
`--force-password-change`. The password is piped over stdin (never on a command line). Re-runnable:
`sudo -E ./deploy/install.sh --create-admin`.

## 8. TLS

Default is a self-signed certificate (`/etc/ssl/ownswitch/`). With `LETSENCRYPT_EMAIL` set **and** the domain resolving
to `PUBLIC_IP`, certbot (webroot, `/var/www/letsencrypt`) obtains a certificate and nginx is re-rendered with HSTS enabled;
renewals reload nginx automatically. Re-run `--only 80` after DNS propagates.
SIP-TLS is not part of Phase 2.

## 9. Verify

```bash
sudo cat /var/lib/ownswitch-install/reports/latest.txt
systemctl status mysql redis-server opensips cgrates rtpengine-daemon nginx php8.3-fpm --no-pager
sudo ss -lntup | sort -k5
sudo opensips -c -f /etc/opensips/opensips.cfg
curl -k https://127.0.0.1/ -H "Host: $OWNSWITCH_DOMAIN" -I
```

## 10. Notes and known limits

* The shipped `opensips.cfg` is a **baseline** (IP/digest auth → dialog → `cgrates_auth` → RTPengine → `drouting`).
  It passes `opensips -c` at install time; module parameter details should be re-verified against the 3.6 docs when the
  portal provisioner takes ownership of routing data. Any config that fails `opensips -c` is never activated.
* `cgrates_engine` points at CGRateS `sessions.listen_bijson` (`127.0.0.1:2014`).
* RTPengine package/unit names differ between Debian/Ubuntu generations; stage 70 discovers them.
* FreeSWITCH is built from source (no supported apt path on Ubuntu 24.04); SIP profiles move to 5090/5091 and RTP to
  16384–29999 so they never collide with OpenSIPS/RTPengine.
* Runtime verification of a *complete* install on real hardware is a Phase 10 activity; this phase delivers the tooling.
