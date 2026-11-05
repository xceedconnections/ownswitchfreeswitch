# Ownswitch

Multi-tenant VoIP billing platform — **native PHP** (no Laravel/Symfony) + MySQL 8.4, OpenSIPS 3.6, CGRateS v0.10.4, RTPengine, optional FreeSWITCH.

Install target: **Ubuntu Server 24.04 LTS (amd64)** — e.g. Hetzner Cloud.

## Production install (Hetzner / any clean Ubuntu 24.04)

**Recommended server:** Hetzner **CX42 / CPX42** (8 vCPU, 16 GB RAM) — see [docs/HETZNER.md](docs/HETZNER.md). Lab minimum: **CX32** (4 vCPU / 8 GB). Use Ubuntu **24.04**, not ARM.

```bash
# On the new server (as root). Point DNS A → this IP first when possible.
curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/install-server.sh \
  | sudo bash -s -- \
      --domain voip.yourdomain.com \
      --email ops@yourdomain.com \
      --password 'ChooseALongPassword1!'
```

That single script clones this repo and installs the full stack (MySQL, Redis, CGRateS, RTPengine, FreeSWITCH, Nginx, PHP, portal, firewall, workers). Credentials are printed and saved to `/root/ownswitch-credentials.txt`.

Skip FreeSWITCH (faster): add `--no-freeswitch`.

### If install failed (MySQL GPG / incomplete stack)

One command repairs MySQL APT keys, pulls latest code, and resumes from stage 30 automatically:

```bash
curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/repair-and-continue.sh \
  | sudo bash
```

## Quick start (dev / XAMPP)

```powershell
cd D:\xampp\htdocs\Ownswitch
$php = "D:\xampp\php\php.exe"
Copy-Item config\.env.example config\.env   # set DB_* and APP_KEY
& $php bin\migrate.php --create-db --seed
& $php bin\create_admin.php --email=admin@example.com --password="Str0ng-Pass-123!"
& $php tests\run.php
& $php -S localhost:8080 -t public public\index.php
```

Portal: http://localhost:8080

## Production install (Ubuntu 24.04)

See [docs/INSTALL.md](docs/INSTALL.md):

```bash
sudo -E ./deploy/install.sh --yes
```

## Documentation

| Doc | Purpose |
|---|---|
| [PLAN.md](PLAN.md) | Phased plan |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Components & API contracts |
| [COMPATIBILITY.md](COMPATIBILITY.md) | Version matrix |
| [BILLING_RULES.md](BILLING_RULES.md) | Increment formula |
| [SECURITY.md](SECURITY.md) | Security design |
| [DATABASE_DESIGN.md](DATABASE_DESIGN.md) | Schema |
| [PROGRESS.md](PROGRESS.md) | Status |
| [docs/INSTALL.md](docs/INSTALL.md) | Installer |
| [docs/OPERATIONS.md](docs/OPERATIONS.md) | Ops commands |
| [docs/ACCEPTANCE.md](docs/ACCEPTANCE.md) | Go-live checklist |
| [docs/BACKUP.md](docs/BACKUP.md) | Backup/restore |
| [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | Troubleshooting |

## Layout

- `public/` — web root only
- `app/` — controllers, services, repositories, integrations, views
- `database/migrations/` — SQL migrations
- `deploy/` — Ubuntu installer
- `bin/` — CLI tools
- `tests/` — automated suite (`php tests/run.php`)
