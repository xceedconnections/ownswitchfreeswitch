# Ownswitch — Implementation Plan

Production-oriented multi-tenant VoIP billing platform (native PHP + MySQL), workflow inspired by MagnusBilling, with OpenSIPS, CGRateS, RTPengine, and optional FreeSWITCH.

## Guiding rules

- No Laravel/Symfony/CodeIgniter or other full-stack PHP frameworks.
- One phase at a time; keep the tree runnable after each phase.
- Verify telephony APIs against official docs — do not invent unsupported features.
- Never silently omit billing increments; never use float for money.
- Never claim tests passed unless executed.
- No destructive DB/config ops without explicit approval.
- Keep `PROGRESS.md` current.

## Phase overview

| Phase | Name | Outcome |
|---|---|---|
| **1** | Discovery and design | Design docs + compatibility — **DONE** |
| **2** | Installation | Idempotent Ubuntu 24.04 installer, secrets, firewall, health |
| **3** | PHP foundation | Router, PDO, auth, sessions, RBAC, tenant isolation, Bootstrap UI shell |
| **4** | Customers and tariffs | Resellers, SIP/IP auth, rate plans, carrier tariffs, I/S config, CSV import |
| **5** | Billing | CGRateS integration, prepaid reservations, postpaid limits, ledger, calculator |
| **6** | SIP routing | OpenSIPS provisioning, trunks, LCR/failover, CDR ingest |
| **7** | Reports | CDR search/export, ASR/ACD, revenue/cost/margin, period reports |
| **8** | Media and fraud | Direct/relay/record, RTPengine, optional FS, fraud pipelines |
| **9** | Security and performance | OWASP tests, concurrency, backup/restore, query tuning |
| **10** | Production acceptance | Clean-server install, full suite, reconciliation, ops docs |

## Phase 1 exit criteria (met)

- [x] Workspace inspected (empty greenfield)
- [x] Compatibility matrix documented
- [x] Architecture + DB ownership + API contracts defined
- [x] Billing increment formula + CGRateS mapping defined
- [x] Security controls documented
- [x] `PROGRESS.md` updated

## Phase 2 scope (next)

1. `deploy/` Ansible playbooks and/or shell installers (idempotent).
2. OS validation (Ubuntu 24.04, CPU/RAM/disk/DNS/time).
3. Firewall template; MySQL 8.4 + Redis install/secure.
4. OpenSIPS 3.6 + required modules; schema DB `opensips`.
5. CGRateS v0.10.4 + Redis DataDB + MySQL StorDB `cgrates`.
6. RTPengine (+ recording daemon); FreeSWITCH gated by flag.
7. Nginx/PHP-FPM scaffolding; deploy empty app skeleton + migrate hook.
8. Secret generation; first-admin placeholder hook.
9. Health-check script + deployment report.
10. Docs: `docs/INSTALL.md` draft.

**Out of scope for Phase 2:** full portal features, live chargeable calls.

## Phase 3–10 acceptance highlights

- **3:** Login + RBAC + tenant-scoped empty dashboards.
- **4:** CRUD plans with independent customer/carrier I/S; CSV validate.
- **5:** Fixture-perfect 6/6, 12/6, custom; prepaid concurrency; idempotent ledger.
- **6:** Register/IP auth; route simulator; CDR ingest without double charge on failover.
- **7:** Paginated CDR search; weekly/monthly ASR from aggregates not averaged dailies.
- **8:** Media policy on CDR; secure recording playback; signaling fraud alerts.
- **9:** Automated security suite green in CI/test env.
- **10:** Clean Ubuntu install meets Definition of Completion in master spec §25.

## Risk register

| Risk | Mitigation |
|---|---|
| CGRateS I≠S edge cases | Dual rate slots + `RatingService` reconciliation (`BILLING_RULES.md`) |
| FreeSWITCH on Ubuntu 24.04 packages | Optional; source build path |
| Single-node performance | Document horizontal split later; index CDRs early |
| Accidental public management APIs | Installer binds localhost + firewall tests |
| Float money bugs | bcmath only; dedicated billing unit tests |

## Documentation set

| File | Status |
|---|---|
| `PLAN.md` | This file |
| `ARCHITECTURE.md` | Phase 1 |
| `COMPATIBILITY.md` | Phase 1 |
| `SECURITY.md` | Phase 1 |
| `DATABASE_DESIGN.md` | Phase 1 |
| `BILLING_RULES.md` | Phase 1 |
| `PROGRESS.md` | Living |
| `docs/*` | From Phase 2 onward |
