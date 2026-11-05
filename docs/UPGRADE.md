# Ownswitch — Upgrade Guide

Principles: **back up first**, upgrade one layer at a time, re-run the installer (idempotent) so configs and units
converge, then read the health report. Never upgrade MySQL/OpenSIPS/CGRateS and the portal in the same maintenance window.

```bash
sudo ownswitch-backup                       # always first (see BACKUP.md)
sudo cat /var/lib/ownswitch-install/reports/latest.txt | head -n 30    # known-good baseline
```

## 1. Application (portal) releases

```bash
cd /opt/ownswitch-src && git pull --ff-only
sudo -E ./deploy/install.sh --only 90,95,99
```

Stage 90 builds `/opt/ownswitch/releases/<timestamp>-<contenthash>/`, runs migrations (`bin/migrate.php` or
`database/migrations/*.sql`) **before** switching, atomically flips `/opt/ownswitch/current`, reloads PHP-FPM
(opcache has `validate_timestamps=0`) and keeps the last `APP_RELEASES_KEEP` (5) releases.
Identical source ⇒ the existing release is reused (no-op).

**Rollback** (code only):

```bash
ls -1 /opt/ownswitch/releases
sudo ln -sfn /opt/ownswitch/releases/<previous> /opt/ownswitch/current.new && sudo mv -Tf /opt/ownswitch/current.new /opt/ownswitch/current
sudo systemctl reload php8.3-fpm && sudo systemctl restart ownswitch-worker
```

Migrations are forward-only: for a destructive migration restore the pre-upgrade `ownswitch` dump (BACKUP.md §Restore) instead.
Write migrations expand/contract so the previous release keeps working against the new schema.

## 2. OS packages (security updates)

```bash
sudo apt-get update && sudo apt-get upgrade
```

* MySQL/OpenSIPS/Redis/nginx/PHP restart themselves via package scripts; **schedule a window** — SIP calls on a restarting
  OpenSIPS drop. Prefer `apt-mark hold opensips opensips-*-module mysql-server` and upgrade deliberately.
* After kernel updates reboot, then verify RTPengine kernel module (`ls /sys/module/xt_RTPENGINE`); DKMS rebuilds it if
  `linux-headers-$(uname -r)` is installed. Otherwise run `sudo ./deploy/install.sh --only 70`.
* Afterwards: `sudo -E ./deploy/install.sh --only 99`.

## 3. MySQL 8.4 LTS

* **Minor/patch (8.4.x → 8.4.y)**: `sudo apt-get install --only-upgrade mysql-server mysql-client`. Back up; the server runs
  its own data-dictionary upgrade at start. Check `journalctl -u mysql`.
* **Do not** jump to another series (e.g. 9.x innovation) — Ownswitch is validated on the 8.4 LTS series only. Stage 00/30/99 fail on non-8.4.
* Re-run `sudo ./deploy/install.sh --only 30,99` afterwards to re-assert config/users.

## 4. OpenSIPS 3.6.x

```bash
sudo cp /etc/opensips/opensips.cfg /root/opensips.cfg.$(date +%F)
sudo apt-get update && sudo apt-get install --only-upgrade 'opensips*'
sudo opensips -c -f /etc/opensips/opensips.cfg     # must say: config file ok
sudo ./deploy/install.sh --only 50,99
```

Within 3.6.x, schema changes (rare) are described in the release notes; apply with `opensips-cli -x database migrate`
(installed as an optional package) **after** a backup. Moving to 4.0 is a separate project (compatibility review first).

## 5. CGRateS (v0.10.x)

CGRateS is built from a git tag and tracked in `/usr/share/cgrates/.installed-version`.

```bash
sudo mysqldump --databases cgrates > /root/cgrates-pre-upgrade.sql      # plus a Redis snapshot: ownswitch-backup
sudo sed -i 's/^CGRATES_VERSION=.*/CGRATES_VERSION=v0.10.5/' /etc/ownswitch/install.conf   # or export CGRATES_VERSION=...
sudo ./deploy/install.sh --only 60,99
```

If the new version requires a storage migration (engine logs *"Migration needed"*):

```bash
sudo systemctl stop cgrates
sudo cgr-migrator -config_path=/etc/cgrates -exec='*migrate' -migrate='*dataDB *stordb' # see `cgr-migrator -h` for your version
sudo systemctl start cgrates
```

Keep the previous binaries (`/usr/local/bin/cgr-*`) copied aside for rollback. A Redis DataDB downgrade is **not** supported —
restore the pre-upgrade Redis snapshot if you must roll back.

## 6. RTPengine, PHP, nginx, Redis

Regular `apt` updates. After a PHP minor update run `--only 80,99` (pool/extension verification). nginx: `nginx -t` runs on every stage 80.
Redis: re-run `--only 40,99`; Redis holds CGRateS balances — snapshot first.

## 7. Changing installer settings

Edit `/etc/ownswitch/install.conf` (or pass env vars), then re-run the affected stages:

| Change | Command |
|---|---|
| firewall CIDRs / ports | `--only 20` (and 50/70 if the SIP or RTP ports changed) |
| domain / TLS | `--only 80,90,99` |
| `PUBLIC_IP` / `PRIVATE_IP` (NAT) | `--only 50,70,90,99` |
| FreeSWITCH cutover (replaces OpenSIPS) | see `docs/FREESWITCH-CUTOVER.md` |

## 8. Upgrading the installer itself

`git pull`, then `sudo -E ./deploy/install.sh` (all stages). Config files that change are backed up to
`/var/backups/ownswitch-config/` (filename + run id) before being overwritten, and services only restart when their config changed.
