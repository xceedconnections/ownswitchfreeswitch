# Ownswitch — Operations

## Daily checks

```bash
sudo -u ownswitch php /opt/ownswitch/current/bin/health_check.php
systemctl status opensips cgrates rtpengine php8.3-fpm nginx mysql redis-server ownswitch-worker
tail -n 100 /var/log/ownswitch/*.log
```

Confirm deployment report exposure audit still clean: no public bind on MySQL/Redis/CGRateS/ESL/MI.

## Common commands

| Task | Command |
|---|---|
| Migrate | `sudo -u ownswitch php /opt/ownswitch/current/bin/migrate.php` |
| Create admin | `sudo -E ./deploy/install.sh --create-admin` |
| CDR worker once | `php bin/worker_cdr.php` |
| Stats rollup | `php bin/rollup_stats.php` |
| Backup now | `sudo systemctl start ownswitch-backup.service` |
| OpenSIPS config test | `opensips -c -f /etc/opensips/opensips.cfg` |
| Reload OpenSIPS | backup cfg first, then `systemctl reload opensips` |
| CGRateS console | `cgr-console` (localhost only) |

## Billing reconciliation

1. Pick a UTC day window.
2. Sum `customer_charge` / `carrier_cost` from `cdrs` where `settlement_status=settled`.
3. Sum matching `ledger_entries` `call_charge` amounts.
4. Investigate `rating_discrepancy` / unsettled CDRs.
5. Do not edit ledger rows — post reversals.

## Failover notes

- Carrier failover must not debit answered calls twice (`external_cdr_key` uniqueness).
- Prepaid reject when CGRateS unreachable (default fail-closed).

## Media

- Direct media: no platform recording/audio fraud.
- Relay/record: verify RTPengine path before marking recording success.
- Recordings live outside web root; playback is authenticated.

## Rollback

See `docs/UPGRADE.md`. App releases under `/opt/ownswitch/releases` with `current` symlink.
