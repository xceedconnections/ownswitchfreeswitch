# Ownswitch — Database Design

MySQL **8.4 LTS**, `utf8mb4`, InnoDB. Event timestamps stored in **UTC** (`DATETIME(3)` or `TIMESTAMP(3)`); display timezone is a user/org setting.

Portal schema database name: **`ownswitch`**.  
Separate databases: **`opensips`**, **`cgrates`** (see ownership in `ARCHITECTURE.md`).

## 1. Conventions

- Primary keys: `BIGINT UNSIGNED` auto-increment (or UUID binary where externally shared).
- Money: `DECIMAL(18,6)` per-call; `DECIMAL(18,4)` balances/invoices (configurable scale later).
- Rates: `DECIMAL(18,8)` per minute.
- Soft status enums as `VARCHAR` or MySQL `ENUM` sparingly; prefer lookup tables for extensibility where needed.
- Every tenant-owned row includes `reseller_id` and/or `customer_id` as appropriate for isolation indexes.
- Migrations in `database/migrations/` numbered and idempotent-safe.

## 2. Core entity groups

### 2.1 Identity and tenancy

| Table | Purpose |
|---|---|
| `roles` | super_admin, admin, reseller, customer_owner, customer_tech, reporter |
| `permissions` | fine-grained permission codes |
| `role_permissions` | mapping |
| `users` | login identity, password_hash, mfa_secret, status, lockout fields |
| `user_roles` | user ↔ role |
| `sessions_audit` | optional server-side session registry for revoke |
| `resellers` | reseller org profile, permission flags, rate visibility |
| `customers` | customer org, billing mode prepaid/postpaid, timezone, status |
| `reseller_customers` | assignment |

### 2.2 SIP authentication

| Table | Purpose |
|---|---|
| `sip_accounts` | customer SIP users; password stored as hash/HA1; never plaintext after create |
| `sip_account_ips` | optional source IP restrictions |
| `ip_endpoints` | IP/CIDR trunks for customer or carrier side |
| `ip_acl_rules` | allow/deny rules |
| `sip_credential_events` | reset/revoke audit |
| `registration_snapshots` | optional tracking mirrored from usrloc |

OpenSIPS `subscriber` / `address` tables are **projections**; Ownswitch tables remain authoritative.

### 2.3 Rate plans and tariffs

| Table | Purpose |
|---|---|
| `currencies` | code, decimal places |
| `billing_increment_defaults` | global I/S defaults (customer & carrier) |
| `rate_plans` | name, code, currency, status, plan-level I/S, connect fee, min billable, rounding |
| `rate_plan_versions` | immutable snapshot metadata + content hash |
| `rate_plan_tariffs` | prefix, name, country/group, rate, I/S overrides, fees, effective_from/to, version_id |
| `customer_rate_plans` | assignment + effective dates |
| `customer_rate_overrides` | per-customer prefix overrides |
| `carrier_tariffs` | carrier purchase rates with independent I/S |
| `carrier_tariff_versions` | immutable versions |
| `tariff_import_batches` | CSV import audit |
| `tariff_import_rows` | preview/validation results |

### 2.4 Carriers and routing

| Table | Purpose |
|---|---|
| `carriers` | provider identity |
| `carrier_trunks` | host, port, transport, auth, codecs, limits, media policy, status |
| `routing_plans` | named plans |
| `routing_rules` | prefix, priority, strategy (LCR/priority), carrier_trunk_id, schedule |
| `customer_route_groups` | customer ↔ routing plan |
| `destination_policies` | allow/block lists |
| `route_overrides` | optional billing/media overrides |

### 2.5 Financial

| Table | Purpose |
|---|---|
| `balances` | current balance cache (derived from ledger; updated transactionally) |
| `credit_limits` | postpaid limits |
| `balance_reservations` | open reservations with expiry / call_id |
| `ledger_entries` | append-only: type, amount, currency, refs, idempotency_key |
| `invoices` | period invoices |
| `invoice_lines` | line items |
| `payments` | payment records linked to ledger |

Ledger entry types: `initial_credit`, `topup`, `adjustment`, `reservation`, `reservation_release`, `call_charge`, `refund`, `invoice_payment`, `reversal`.

### 2.6 CDRs and media

| Table | Purpose |
|---|---|
| `cdrs` | unified CDR (see columns below) |
| `cdr_rating_details` | optional 1:1 extended rating breakdown |
| `recordings` | path, hash, duration, status, retention |
| `recording_access_logs` | play/download audit |

#### `cdrs` essential columns

- ids: `id`, `uuid`, `sip_call_id`, `external_cdr_key` (unique)
- tenancy: `reseller_id`, `customer_id`, `sip_account_id`, `ip_endpoint_id`
- numbers: `src`, `dst_raw`, `dst_normalized`, `dst_prefix`
- routing: `carrier_id`, `carrier_trunk_id`, `routing_rule_id`
- times: `setup_at`, `answer_at`, `end_at` (UTC)
- durations: `duration_actual`, `duration_billable_customer`, `duration_billable_carrier`
- rates: `customer_rate`, `carrier_rate`, `customer_initial`, `customer_subsequent`, `carrier_initial`, `carrier_subsequent`
- money: `customer_charge`, `carrier_cost`, `customer_connect_fee`, `carrier_connect_fee`, `gross_margin`
- versions: `customer_rate_plan_version_id`, `carrier_tariff_version_id`
- signaling: `sip_response`, `disposition`, `failure_reason`
- media: `media_policy_requested`, `media_policy_actual`, `recording_status`
- fraud: `fraud_status`, `fraud_event_id`
- settlement: `settlement_status`, `rating_source`, `rated_at`

Indexes (minimum):

- `(customer_id, setup_at)`
- `(reseller_id, setup_at)`
- `(carrier_id, setup_at)`
- `(dst_normalized, setup_at)`
- `(sip_call_id)`
- `UNIQUE(external_cdr_key)`
- `(disposition, setup_at)` for ASR jobs

### 2.7 Fraud, audit, ops

| Table | Purpose |
|---|---|
| `fraud_rules` | thresholds and actions |
| `fraud_events` | detections |
| `audio_analysis_results` | model version, confidence, reasons |
| `audit_logs` | actor, action, entity, before/after JSON, ip |
| `system_settings` | keyed settings |
| `background_jobs` | queue/status |
| `health_checks` | latest probe results |
| `media_policies` | global/customer/trunk defaults |

### 2.8 Reporting aggregates

| Table | Purpose |
|---|---|
| `stats_hourly` | attempts, answered, duration, revenue, cost |
| `stats_daily` | daily rollup dimensions (customer/carrier/dest/reseller) |

Weekly/monthly ASR/ACD computed from **sums of attempts/answered/duration**, never averages of daily ASR percentages.

## 3. OpenSIPS database (separate)

Managed by OpenSIPS schema tools. Portal provisioner writes only supported tables, e.g.:

- `subscriber` — SIP digest users
- `address` / `trusted` — IP auth
- `dispatcher` or custom `ownswitch_routes` (if used)
- `version` — schema version

No Ownswitch ledger tables here.

## 4. CGRateS StorDB (separate)

Created by CGRateS scripts (`setup_cgr_db.sh`, `cgr-migrator`). Portal must not create conflicting tables inside `cgrates`. Mapping of Ownswitch rate plans → TP CSVs/API is an integration concern.

## 5. Retention

| Data | Default retention (configurable) |
|---|---|
| CDRs | 24 months online; archive thereafter |
| Recordings | per customer policy (e.g. 90 days) |
| Audit logs | 24 months |
| Fraud events | 24 months |
| Ledger | indefinite (financial) |

## 6. Backup

- Per-database `mysqldump` or XtraBackup; Redis RDB/AOF as applicable.
- Secrets and Nginx/OpenSIPS/CGRateS configs included in system backup set.
- Restore procedure documented and tested in Phase 9/10.

## 7. Migration plan

Phase 3 introduces migration runner (`bin/migrate.php`) and initial migrations for identity + settings.  
Phases 4–8 add tariff, billing, CDR, media, fraud tables incrementally without breaking prior runnable states.
