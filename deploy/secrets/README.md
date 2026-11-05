# deploy/secrets — intentionally empty

**No secret ever lives in this repository.** This directory only exists to document where secrets live
and (via `.gitignore`) to make sure nobody commits one by accident.

## Where secrets live on a server

| Path | Owner / mode | Content |
|---|---|---|
| `/etc/ownswitch/secrets/` | `root:root` `0700` | one file per generated secret (each `0600`) |
| `/etc/ownswitch/ownswitch.env` | `root:ownswitch` `0640` | runtime environment for the PHP app and workers (rendered from the secrets above) |
| `/etc/cgrates/cgrates.json` | `root:cgrates` `0640` | CGRateS config containing its DB/Redis passwords |
| `/etc/opensips/opensips.cfg` | `root:opensips` `0640` | contains the OpenSIPS DB URL |
| `/etc/ownswitch/install.conf` | `root:ownswitch` `0640` | **non-secret** installer settings (domain, IPs, CIDRs …) |

All of these are **outside any web root** (nginx serves only `/opt/ownswitch/current/public`).

## Generated secrets (`/etc/ownswitch/secrets/<name>`)

| Name | Used by |
|---|---|
| `mysql_ownswitch_password` | portal DB account `ownswitch` |
| `mysql_opensips_password` | OpenSIPS DB account `opensips` (also used by the portal provisioner) |
| `mysql_cgrates_password` | CGRateS StorDB account `cgrates` |
| `mysql_cgrates_ro_password` | portal read-only StorDB account `cgrates_ro` |
| `redis_password` | Redis `requirepass` (CGRateS DataDB, portal cache) |
| `app_key` | application encryption/signing key |
| `recording_notify_token` | shared token for RTPengine recording-finished callbacks |
| `freeswitch_esl_password`, `freeswitch_default_password` | only when `FREESWITCH_ENABLED=1` |
| `backup_passphrase` | AES-256 key for `ownswitch-backup` archives — **copy it offline** |

Properties:

* Generated with `openssl rand -hex` the first time a stage needs them, **never overwritten** on re-runs
  (idempotent); services are re-synced to the stored value on each run.
* Alphanumeric only, so they are safe in SQL, JSON, URLs and `KEY=VALUE` env files.
* No MySQL **root** password exists: `root@localhost` uses `auth_socket`.
* The first **admin** password is not stored by the installer. It comes from `OWN_SWITCH_ADMIN_PASS`,
  an interactive prompt, or (non-interactive fallback) is generated once into
  `/root/ownswitch-initial-admin.txt` (`0600`) with a forced password change on first login.

## Rotating a secret

```bash
sudo rm /etc/ownswitch/secrets/mysql_ownswitch_password
sudo deploy/install.sh --only 30,90      # regenerates, ALTER USERs MySQL, re-renders ownswitch.env, reloads PHP-FPM
```

Rotate `mysql_cgrates_password` / `redis_password` with `--only 30,40,60,90` (then restart `cgrates`).
Rotating `backup_passphrase` makes **old** backups undecryptable with the new value — keep the old one.

## Rules for contributors

* Never put real credentials in templates, scripts, Ansible vars or docs. Use `@@PLACEHOLDER@@` and generate.
* Files in this directory other than this README are ignored by git (see `.gitignore`).
