# Ownswitch — Architecture

Multi-tenant VoIP billing platform: native PHP portal + OpenSIPS + CGRateS + optional RTPengine/FreeSWITCH.

## 1. Logical components

```
┌─────────────────────────────────────────────────────────────────┐
│                        Nginx (TLS)                               │
│                     public/index.php                             │
└────────────────────────────┬────────────────────────────────────┘
                             │
┌────────────────────────────▼────────────────────────────────────┐
│ Native PHP application (Ownswitch)                               │
│ Controllers → Middleware (authz/CSRF) → Services → Repositories │
│ Integrations: OpenSIPS | CGRateS | RTPengine | FreeSWITCH | Fraud│
└───────┬───────────────────┬───────────────────┬─────────────────┘
        │                   │                   │
   MySQL ownswitch     JSON-RPC/MI          Files / notify
        │                   │                   │
        │         ┌─────────▼─────────┐   ┌─────▼──────┐
        │         │ OpenSIPS 3.6      │   │ RTPengine  │
        │         │ (+ cgrates mod)   │──▶│ (+ record) │
        │         └─────────┬─────────┘   └────────────┘
        │                   │ JSON-RPC
        │         ┌─────────▼─────────┐
        │         │ CGRateS v0.10     │
        │         │ DataDB: Redis     │
        │         │ StorDB: MySQL     │
        │         └───────────────────┘
        │
   MySQL opensips (SIP schemas)   MySQL cgrates (StorDB only)
```

## 2. Database ownership

| Database | Writer | Reader | Contents |
|---|---|---|---|
| `ownswitch` | Portal, workers | Portal, reports | Tenants, users, tariffs, ledger, unified CDRs, fraud, audit |
| `opensips` | OpenSIPS; portal provisioner (controlled) | OpenSIPS | subscriber, location, address, dialog helpers |
| `cgrates` | CGRateS only | CGRateS; portal read-only ops if needed | TP versioning, CGRateS CDRs |
| Redis | CGRateS | CGRateS | Runtime accounts/balances/cache |

**Source of truth for commercial data:** `ownswitch`.  
**OpenSIPS / CGRateS** receive projected provisioning; they are not edited by hand in production.

## 3. Call flows

### 3.1 Direct media (default when allowed)

1. Customer INVITE → OpenSIPS.
2. Authenticate (digest or IP allowlist).
3. Normalize destination; check destination policy / fraud signaling rules.
4. `cgrates_auth` / `SessionSv1.AuthorizeEvent` → max usage.
5. Select carrier (LCR / priority / failover) via Ownswitch routing data loaded into OpenSIPS (or REST lookup).
6. Relay signaling to carrier; **do not** engage RTPengine if policy=`direct`.
7. On BYE/response: accounting → CDR ingest → dual rate settlement → ledger.

Record on CDR: `media_policy_requested=direct`, `media_policy_actual=direct`.

### 3.2 Relayed / recorded media

Same through step 5, then:

6. Engage RTPengine (`rtpengine_offer`/`answer`) with flags for relay and/or recording.
7. Optional FreeSWITCH bridge when transcoding/announcements/analysis required.
8. Recording daemon notify → portal attaches recording metadata; verify media path before `recording_status=success`.
9. Fail-closed: if mandatory record/analyze cannot be established, reject or tear down per policy.

### 3.3 Carrier failover

- On retryable SIP responses only (configurable).
- Never re-authorize a second customer debit for the same answered dialog.
- Dialog / OriginID uniqueness prevents double settlement.

## 4. Application structure

```
public/                 # web root only
app/
  Controllers/
  Services/             # Billing, Routing, Fraud, Reports, Provisioning
  Repositories/
  Models/
  Middleware/
  Views/
  Integrations/
    OpenSIPS/
    CGRateS/
    RTPengine/
    FreeSWITCH/
    Fraud/
config/                 # non-secret defaults; secrets outside repo
database/migrations/
database/seeders/
bin/                    # CLI workers, migrate, health
tests/
deploy/                 # Ansible / install scripts
docs/
```

### Request path

`public/index.php` → Router → Middleware stack → Controller → Service → Repository (PDO) → View/JSON.

No user-controlled `include` paths. Central exception handler. CSRF on state-changing browser posts.

## 5. API contracts (Phase 1 definitions)

### 5.1 Portal ↔ CGRateS (JSON-RPC, localhost)

| Operation | Method (documented) | Notes |
|---|---|---|
| Authorize call | `SessionSv1.AuthorizeEvent` / `AuthorizeEventWithDigest` | Via OpenSIPS module primarily |
| Get max usage | GetMaxUsage flag on authorize | Prepaid |
| Load / set TP rate | `ApierV1.SetTPRate` / TP loaders | Provisioning from portal |
| Account set / topup | AccountS / APIer account APIs | Mirrored to ledger |
| Process CDR | `CDRsV1.ProcessCDR` / Session accounting | Idempotent ingest also in portal |
| Get cost preview | RALs get-cost style APIs | Cross-check calculator |

Exact parameter shapes follow CGRateS v0.10 docs; wrappers live under `app/Integrations/CGRateS/`.

### 5.2 Portal ↔ OpenSIPS

| Operation | Interface | Notes |
|---|---|---|
| Reload address / subscribers | MI / JSON management (localhost) | After provisioning |
| Health | MI `ps` / statistics | Dashboard |
| Optional route query | `rest_client` to portal internal API | If not using DB-only routing |

Provisioning writes OpenSIPS DB tables that modules already understand (`subscriber`, `address`, etc.), then triggers safe reload.

### 5.3 Portal ↔ RTPengine

| Operation | Interface |
|---|---|
| Offer/answer/delete | Controlled by OpenSIPS `rtpengine` module |
| Recording finished | HTTP notify → portal endpoint (HMAC/auth) |
| Health | ng protocol / CLI localhost |

### 5.4 Portal ↔ FreeSWITCH (optional)

| Operation | Interface |
|---|---|
| Originate / park / record | ESL localhost |
| Events | ESL event subscriptions for media/fraud pipeline |

### 5.5 Internal portal HTTP API

Authenticated session or service token; never expose CGRateS/OpenSIPS credentials to browsers.

Examples: CDR search JSON, report aggregates, live calls, billing preview `POST /admin/billing/preview`.

## 6. Roles and tenancy

| Role | Scope |
|---|---|
| Super administrator | All tenants / system |
| Administrator | Platform ops within org |
| Reseller | Assigned customers only |
| Customer account owner | Own customer |
| Customer technical user | Own SIP/tech subset |
| Read-only reporting | Reports/CDRs without mutate |

Every repository query applies `tenant_scope` (reseller_id / customer_id) from the authenticated principal — not from client-supplied IDs alone.

## 7. Billing dual-path

1. **Control plane (real time):** OpenSIPS → CGRateS SessionS.
2. **Commercial plane (portal):** Idempotent CDR settlement via `RatingService` + ledger; CGRateS costs stored for reconciliation.

See `BILLING_RULES.md`.

## 8. CDR pipeline

```
OpenSIPS accounting / CGRateS CDR
        ↓
Ingest worker (unique external key)
        ↓
Normalize + attach routing/media/fraud fields
        ↓
Rate customer + carrier (snapshot versions)
        ↓
Ledger postings (idempotent)
        ↓
Aggregate tables for ASR/ACD (daily rollup jobs)
```

Failed calls and answered calls share `cdrs` with disposition; eligible-attempt rules for ASR are documented in reporting services.

## 9. Media policy enum

`direct` | `relay` | `record` | `audio_fraud` | `record_and_analyze` | `auto`

Resolution order: call override → route → trunk → customer → global. Mandatory record overrides `direct`.

## 10. Workers and schedules

| Job | Frequency | Purpose |
|---|---|---|
| `cdr:ingest` | continuous | Queue/file/RPC ingest |
| `cdr:settle` | continuous | Rating + ledger |
| `report:rollup` | hourly/daily | ASR/ACD/revenue aggregates |
| `fraud:evaluate` | continuous / cron | Signaling rules |
| `backup:mysql` | daily | Encrypted backups |
| `health:probe` | 1m | Service dashboard |
| `provision:sync` | on change + reconcile | OpenSIPS/CGRateS drift check |

## 11. Installation topology (default single node)

Phase 2 supports single-host Ubuntu 24.04. Split-horizon (SIP vs portal) is compatible later; interfaces remain localhost-first with firewall allowlists.
