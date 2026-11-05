# Ownswitch — Billing Rules

Canonical formulas for customer charges and carrier costs. Implemented once in `app/Services/Billing/RatingService.php`. All previews, settlement, and tests must call this service (or a thin CGRateS adapter that is fixture-validated against it).

## 1. Billable duration

Given answered connected duration `D` (integer seconds), initial duration `I` (> 0), subsequent increment `S` (> 0):

```
if D == 0:
    billable = 0   # unless a separate zero-duration fee policy is enabled
else if D <= I:
    billable = I
else:
    billable = I + ceil((D - I) / S) * S
```

Apply independently for:

- **Customer** (`I_c`, `S_c`) → `billable_customer`
- **Carrier** (`I_k`, `S_k`) → `billable_carrier`

### Worked example (spec)

| Side | Rate / min | Increment | D | Billable | Amount |
|---|---|---|---|---|---|
| Customer | 0.0100 | 12/6 | 16 | 18 | 0.0100 × 18/60 = **0.003000** |
| Carrier | 0.0060 | 6/6 | 16 | 18 | 0.0060 × 18/60 = **0.001800** |
| Margin | — | — | — | — | **0.001200** |

### Additional fixtures (must pass in tests)

| D | I/S | Billable |
|---|---|---|
| 0 | 6/6 | 0 |
| 1 | 6/6 | 6 |
| 6 | 6/6 | 6 |
| 7 | 6/6 | 12 |
| 1 | 12/6 | 12 |
| 12 | 12/6 | 12 |
| 13 | 12/6 | 18 |
| 16 | 12/6 | 18 |
| 18 | 12/6 | 18 |
| 19 | 12/6 | 24 |
| 61 | 60/60 | 120 |
| 30 | 30/6 | 30 |
| 31 | 30/6 | 36 |

## 2. Charge formula

Rates are stored as **per-minute** DECIMAL values.

```
usage_charge = rate_per_minute * billable_seconds / 60
total = usage_charge + connection_fee   # connection_fee policy below
```

Use `bcmath` (or integer minor units) — **never** IEEE floating point for settlement.

### Connection fee policy (default)

- Applied **once** when `D > 0` (answered with positive connected duration).
- Not applied when `D == 0` unless `charge_connect_on_zero` is explicitly enabled on the tariff.
- Stored separately on the CDR (`customer_connect_fee`, `carrier_connect_fee`) and included in totals.

### Minimum billable duration

If configured `min_billable_seconds > billable` after the increment formula, raise billable to `min_billable_seconds` (still subject to answered/`D > 0` rules).

### Rounding

- Intermediate money: scale of rate × duration math uses high precision (`bcdiv` scale ≥ 8).
- Stored charges: `DECIMAL(18,6)` for per-call amounts; ledger aggregates `DECIMAL(18,4)` or currency-specific scale from settings.
- Display rounding is separate from stored settlement values.

## 3. Increment configuration precedence

### Customer side (highest wins)

1. Route-specific override (only if explicitly enabled on the route)
2. Destination-prefix tariff row (customer tariff table)
3. Customer-specific rate override
4. Customer rate-plan default increment / rate
5. Global default increment

### Carrier side

1. Route-specific carrier override (if enabled)
2. Destination-prefix on carrier tariff
3. Carrier trunk default increment / rate
4. Global carrier default

The resolved rate, `I`, `S`, connect fee, currency, and **rate version IDs** are snapshotted onto the CDR at settlement time.

## 4. Prepaid

1. Before routing: authorize destination + estimate max usage from balance / rate.
2. Atomically reserve credit (ledger `reservation` + CGRateS session max usage).
3. Concurrent calls must not overspend the same unreserved balance (row locks / CGRateS account locks).
4. Periodic reauth or hard max duration from authorization.
5. On hangup: final rate → debit → release unused reservation.
6. Insufficient credit → reject before carrier INVITE (or cancel per policy).

## 5. Postpaid

1. Authorize against credit limit − outstanding − active reservations.
2. Rate CDRs into ledger `call_charge` entries.
3. Billing period → invoice generation from posted ledger (not from editable CDR edits).
4. Payments → ledger `payment`; suspension when past due / over limit per policy.

## 6. Ledger immutability

- Posted entries are never updated or deleted.
- Corrections use **reversing** entries referencing the original `entry_id`.
- Settlement is **idempotent** on `(source_system, external_cdr_id, run_type)` unique key.

## 7. CGRateS mapping

### Single increment (e.g. 6/6, 60/60)

One rate slot:

| Field | Value |
|---|---|
| ConnectFee | portal connection fee |
| Rate | per-minute rate |
| RateUnit | `60s` |
| RateIncrement | `{I}s` (equals S) |
| GroupIntervalStart | `0s` |

### Split increment (e.g. 12/6, 18/6, 30/6)

Two rate slots, same Rate Id:

| Slot | RateIncrement | GroupIntervalStart | ConnectFee |
|---|---|---|---|
| 1 | `{I}s` | `0s` | portal fee (slot 1 only) |
| 2 | `{S}s` | `{I}s` | `0` |

Rate and RateUnit remain the per-minute definition on both slots unless a destination policy says otherwise.

### Dual rating (customer + carrier)

Use ChargerS / derived charging:

| Run | Request type | Purpose |
|---|---|---|
| `*default` / customer | `*prepaid` or `*postpaid` | Customer charge + balance |
| `supplier` / carrier | `*rated` | Carrier cost only (no customer balance debit) |

Portal CDR stores both runs’ costs, increments, and rate version references.

### Adapter / reconciliation

1. Provision tariffs to CGRateS via documented APIer/TP APIs.
2. OpenSIPS `cgrates` module handles real-time auth/accounting.
3. CDR ingestion writes Ownswitch CDR + compares CGRateS costs to `RatingService`.
4. If mismatch beyond tolerance: flag `rating_discrepancy`, prefer Ownswitch formula for customer ledger after admin-configurable policy (`prefer_portal_rating` default **true** for invoice truth; CGRateS remains real-time control plane).

**Never** silently drop `I`/`S` if the engine cannot represent them — fail provisioning validation instead.

## 8. Historical accuracy

When a rate plan changes:

- Create a new `rate_plan_version` (immutable snapshot of plan defaults + tariff hash).
- Existing CDRs keep old version FKs.
- Live provisioning pushes the new TP activation time to CGRateS.

## 9. Billing preview calculator

Admin UI inputs: rate, I, S, D, connection fee.  
Outputs: billable seconds, charge, step breakdown, side-by-side comparison for selected presets (6/6, 12/6, custom).  
**Must** call `RatingService` — no duplicate JS-only formula for settlement numbers (JS may mirror for UX but server recalculates).
