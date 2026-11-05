# Ownswitch — Production Acceptance (Phase 10)

Install on a **clean Ubuntu 24.04 LTS** host with `deploy/install.sh`, then verify each item. Do **not** place real chargeable calls in unattended CI.

## A. Install

- [ ] OS validation passes (Ubuntu 24.04)
- [ ] MySQL 8.4 LTS; DBs `ownswitch`, `opensips`, `cgrates` exist with least-privilege users
- [ ] Redis bound to localhost with requirepass
- [ ] OpenSIPS 3.6 + cgrates/mysql modules; `opensips -c` OK
- [ ] CGRateS v0.10.4; DataDB Redis; StorDB `cgrates`
- [ ] RTPengine (+ recording daemon when enabled)
- [ ] FreeSWITCH only if `FREESWITCH_ENABLED=1`; ESL on 127.0.0.1
- [ ] Nginx HTTPS + PHP 8.3-FPM
- [ ] Secrets under `/etc/ownswitch/` (mode 0600); not in git
- [ ] Health report green; management ports not public
- [ ] First admin created; can log in

## B. Multi-tenant portal

- [ ] Super admin / admin / reseller / customer roles enforce server-side isolation
- [ ] Customer cannot open other customers’ CDRs, invoices, recordings
- [ ] CSRF required on POSTs; SQLi/XSS smoke tests pass (`php tests/run.php`)

## C. SIP & routing

- [ ] SIP digest registration works for a test account
- [ ] IP authentication allowlist works; revocation takes effect
- [ ] Multiple carriers; route simulator shows prefix, customer rate, carrier cost
- [ ] Failover on retryable responses without double charge

## D. Billing increments

- [ ] Admin billing preview: 16s @ 12/6 customer $0.0100 → billable 18, charge 0.003000
- [ ] Carrier side independent 6/6 @ $0.0060 → cost 0.001800; margin 0.001200
- [ ] Custom I/S; zero-duration; connection fee policy
- [ ] `php tests/run.php Billing` green
- [ ] Historical CDR retains rate-plan version after plan edit

## E. Prepaid / postpaid

- [ ] Prepaid insufficient credit rejects before carrier
- [ ] Concurrent reservations cannot overspend
- [ ] Postpaid credit limit enforced
- [ ] Duplicate CDR delivery does not double-debit ledger
- [ ] Ledger append-only (UPDATE rejected / reversal used)

## F. Reports & CDR

- [ ] Paginated CDR search + CSV export with same filters/permissions
- [ ] Daily / weekly / monthly ASR & ACD from aggregate counts (not averaged daily %)
- [ ] Revenue, cost, margin reports match settled CDRs for a test window

## G. Media & fraud

- [ ] Direct media ON path recorded on CDR
- [ ] Relay/record path via RTPengine; authenticated playback
- [ ] Signaling fraud alert on threshold breach
- [ ] Audio fraud only when media available; no sole auto-kill on unvalidated score

## H. Ops

- [ ] Backup + restore drill (`docs/BACKUP.md`)
- [ ] Config backup before OpenSIPS reload; rollback works
- [ ] Monitoring dashboard / `bin/health_check.php` reflects service state

## Sign-off

| Role | Name | Date | Result |
|---|---|---|---|
| Installer | | | |
| Billing QA | | | |
| Security QA | | | |
