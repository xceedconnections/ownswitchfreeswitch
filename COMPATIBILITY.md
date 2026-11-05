# Ownswitch — Component Compatibility

Target OS: **Ubuntu Server 24.04 LTS (noble)**  
Verified against official documentation and package indexes (Phase 1). Runtime install verification is deferred to Phase 2 / Phase 10.

## Recommended stack

| Component | Version / channel | Install source | Role |
|---|---|---|---|
| OS | Ubuntu 24.04 LTS | Canonical | Host |
| PHP | **8.3** | Ubuntu `noble` (`php8.3`, `php8.3-fpm`) | Portal |
| PHP extensions | mysql, pdo, mbstring, xml, curl, zip, bcmath, redis, intl, opcache | Ubuntu | Portal |
| Nginx | Latest noble | Ubuntu | HTTPS front door |
| MySQL | **8.4 LTS** | [MySQL APT Repository](https://dev.mysql.com/doc/refman/8.4/en/linux-installation-apt-repo.html) (`mysql-8.4-lts`) | Portal + OpenSIPS + CGRateS StorDB |
| Redis | Latest noble (6.x/7.x as shipped) | Ubuntu | CGRateS DataDB |
| OpenSIPS | **3.6 LTS** | [apt.opensips.org](https://apt.opensips.org/) noble `3.6-releases` | SIP signaling |
| OpenSIPS modules | mysql, auth, usrloc, dialog, permissions, rtpengine, **cgrates**, acc, rest_client (as needed) | `opensips-*-module` packages | SIP features |
| CGRateS | **v0.10.4** | Official release binaries / packages from [cgrates/cgrates](https://github.com/cgrates/cgrates/releases) | Real-time rating & credit control |
| RTPengine | **11.5.x** (e.g. `11.5.1.18`) | Ubuntu `noble` universe (`rtpengine`, `rtpengine-recording-daemon`) | Media relay / recording |
| FreeSWITCH | **1.10.12** (optional) | Prefer **source build**; SignalWire APT is best-effort on Ubuntu | Media processing |
| Composer | 2.x | getcomposer.org | Standalone PHP libraries only |

## Database backends — ownership rules

| Store | Engine | Owner | Notes |
|---|---|---|---|
| `ownswitch` | MySQL 8.4 | Portal | Authoritative business data, ledger, reporting CDRs |
| `opensips` | MySQL 8.4 | OpenSIPS | OpenSIPS schemas only (`opensips-cli` / `opensipsdbctl`) |
| `cgrates` | MySQL 8.4 | CGRateS StorDB | Created via CGRateS `setup_cgr_db.sh` / migrator — **not** portal tables |
| Redis DB index (e.g. `10`) | Redis | CGRateS DataDB | Accounts, balances, rating cache — **not** shared with portal sessions |

**Hard rule:** Do not point CGRateS DataDB or StorDB at Ownswitch portal tables. Provision tariff/account data through CGRateS JSON-RPC APIs and keep Ownswitch as the management source of truth.

## OpenSIPS

### Confirmed

- Official APT packages for Ubuntu noble include OpenSIPS **3.6** releases and module packages.
- `opensips-cgrates-module` is packaged for noble 3.6 (e.g. `opensips-cgrates-module_3.6.x`).
- `db_mysql` module is available (`opensips-mysql-module`).
- CGRateS module docs (3.6): JSON-RPC to CGRateS; `cgrates_auth()`, `cgrates_cmd("SessionSv1.AuthorizeEvent")`, accounting helpers; requires **dialog** loaded before **cgrates**.
- Database deployment via OpenSIPS CLI / schema tools against a dedicated MySQL database.

### Selected modules (planned)

| Module | Purpose |
|---|---|
| `db_mysql` | Persistence |
| `auth` / `auth_db` | Digest authentication |
| `usrloc` | Registrations |
| `permissions` / address tables | IP authentication allowlists |
| `dialog` | Dialog tracking for billing sessions |
| `cgrates` | Auth, debit, CDR events to CGRateS |
| `rtpengine` | Offer/answer media anchoring |
| `acc` / flatstore or DB | Signaling CDR feed (backup / reconciliation) |
| `dispatcher` or custom routing tables | Carrier selection assist |

### Risks

- OpenSIPS 4.0 exists but 3.6 LTS is preferred for production stability on this project.
- Configuration changes must be validated (`opensips -c`) and reloaded safely with backups.

## CGRateS

### Confirmed (v0.10 docs)

- **DataDB:** `*redis` or `*mongo` — use Redis.
- **StorDB:** `*mysql` (also postgres/mongo/internal) — use MySQL schema owned by CGRateS.
- JSON-RPC APIs for SessionS (`AuthorizeEvent`, debit/refund), RALs, AccountS, CDRS, APIer tariff-plan loaders.
- Rates support `ConnectFee`, `Rate`, `RateUnit`, `RateIncrement`, `GroupIntervalStart` — sufficient to express `I/S` increments (see `BILLING_RULES.md`).
- Overview explicitly lists rate increments, connect fees, session reservation/refunds, and derived charging for customer/supplier parallel calculations.
- ChargerS / DerivedCharging: fork one call into customer run (`*prepaid` / `*postpaid`) and supplier run (`*rated`) with separate categories/subjects.

### Mapping strategy

| Ownswitch concept | CGRateS mechanism |
|---|---|
| Customer prepaid balance | Account monetary balance + SessionS max usage |
| Postpaid credit limit | Account / threshold controls + portal ledger enforcement |
| Customer selling rate + increment | RatingPlan / Rates with RateUnit=`60s`, RateIncrement / GroupIntervalStart |
| Carrier purchase rate + increment | Separate category/subject via ChargerS derived run (`*rated`) |
| Rate-plan version | TP load IDs + Ownswitch `rate_plan_versions` snapshot on CDR |
| Portal calculator | Ownswitch `RatingService` (same formula); optional CGRateS get-cost cross-check |

### Risks / adapters

- When `initial_duration ≠ subsequent_increment`, use **two rate slots** (`GroupIntervalStart=0` and `GroupIntervalStart=I`). Validate with fixtures; if any edge case diverges, Ownswitch settlement reconciles using `RatingService` and records `rating_source`.
- CGRateS monetary math uses configurable rounding; Ownswitch stores `DECIMAL` and reconciles to portal ledger as source of customer invoices.
- Do not invent unsupported RPCs — only documented SessionS / APIer / CDRS methods.

## RTPengine

### Confirmed

- Ubuntu noble ships RTPengine **11.5.1.x** metapackage and `rtpengine-recording-daemon`.
- Recording via `recording-method=proc` + recording daemon (or pcap). Spool dir ≠ output dir.
- HTTP notify on finished recordings (`notify-uri`) for portal ingestion.
- Kernel DKMS module available; userspace-only mode possible if DKMS blocked (lower performance).

### Risks

- DKMS/kernel headers must match running kernel.
- Direct-media calls bypass RTPengine → no platform recording/audio fraud for those calls (by design).

## FreeSWITCH (optional)

### Confirmed

- FreeSWITCH **1.10.12** is the current 1.10 line.
- ESL (Event Socket) on `127.0.0.1:8021` with generated password — never public.
- Ubuntu 24.04: SignalWire package repos are **best-effort**; prefer **source build** or documented image builds for production.

### Scope

Enabled only when install flag `freeswitch_enabled=true`. Used for transcoding, announcements, optional recording/analysis paths — not required for basic billing/routing.

## PHP / web stack

| Item | Decision |
|---|---|
| Framework | **None** (native modular PHP) |
| Money math | `bcmath` / string DECIMAL — never float |
| Sessions | PHP sessions + secure cookies |
| UI | Bootstrap 5, fetch/AJAX |
| Composer | Allowed for libraries (e.g. PHPUnit, CSV, CSRF helpers) — not full-stack frameworks |

## Network / port exposure (default)

| Service | Bind | Public? |
|---|---|---|
| Nginx 443/80 | Public | Yes (portal) |
| OpenSIPS SIP 5060 UDP/TCP (+ TLS if enabled) | Public / restricted | Controlled by firewall |
| RTP ports (RTPengine range) | Public UDP | Yes when media relay used |
| MySQL 3306 | localhost / private | **No** |
| Redis 6379 | localhost | **No** |
| CGRateS JSON-RPC | localhost | **No** |
| FreeSWITCH ESL 8021 | localhost | **No** |
| OpenSIPS MI/JSON | localhost | **No** |

## Compatibility verdict

**Proceed.** All mandatory components have documented Ubuntu 24.04 or official install paths and APIs sufficient for the architecture in `ARCHITECTURE.md`. Remaining uncertainty is limited to FreeSWITCH packaging convenience and CGRateS edge-case validation for custom `I/S` pairs — both have explicit mitigation paths.
