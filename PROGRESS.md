# Ownswitch — Progress Log

## Status

**Current phase:** Phases 1–10 implemented in repository (local verification on Windows XAMPP PHP 8.2; Ubuntu 24.04 live install not yet executed on a clean server).

## Phase checklist

| Phase | Status | Notes |
|---|---|---|
| 1 Discovery | **Done** | Design docs |
| 2 Installation | **Done** | `deploy/` + `docs/INSTALL.md` etc. |
| 3 PHP foundation | **Done** | Router, auth, RBAC, Bootstrap UI |
| 4 Customers/tariffs | **Done** | CRUD + CSV import/export |
| 5 Billing | **Done** | RatingService, ledger, prepaid, preview, CGRateS client |
| 6 SIP routing | **Done** | OpenSIPS provisioner, route simulator, CDR ingest |
| 7 Reports | **Done** | CDR search/export, ASR/ACD, financial |
| 8 Media/fraud | **Done** | Media policy, recordings, signaling fraud |
| 9 Security/tests | **Done** | 57+ automated tests; security suite included |
| 10 Acceptance | **Docs done** | `docs/ACCEPTANCE.md` — live clean-server sign-off pending |

## Test results (executed)

```
php tests/run.php
→ 60 passed, 0 failed, 303 assertions (includes 6/6, 12/6, margin 0.001200, CSV validation, security)
```

## Known limitations

- Full Ubuntu 24.04 installer not executed in this Windows workspace (bash stages syntax-checked by Phase 2 agent; live package names need first-VM validation).
- Live OpenSIPS / CGRateS / RTPengine / FreeSWITCH integration not exercised with real SIP.
- PHP verified on 8.2.12 XAMPP; production target is 8.3 on Ubuntu.
- Optional FreeSWITCH is source-build gated (`FREESWITCH_ENABLED=1`).
- Audio fraud is a pluggable stub — not a trained production model.

## Next actions for operators

1. Provision a clean Ubuntu 24.04 VM.
2. Run `sudo -E ./deploy/install.sh --yes`.
3. Walk `docs/ACCEPTANCE.md`.
4. Wire real CGRateS TP load from portal rate plans (validate JSON-RPC method names on the installed version).
