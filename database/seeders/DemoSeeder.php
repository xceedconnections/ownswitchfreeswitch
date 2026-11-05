<?php

declare(strict_types=1);

namespace Ownswitch\Seeders;

use Ownswitch\Controllers\SipUserController;
use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;
use Ownswitch\Repositories\CdrRepository;
use Ownswitch\Repositories\RatePlanRepository;
use Ownswitch\Services\Billing\LedgerService;
use Ownswitch\Services\Billing\PrepaidService;
use Ownswitch\Services\Billing\RateResolver;
use Ownswitch\Services\Billing\RatingService;
use Ownswitch\Services\Cdr\CdrIngestService;
use Ownswitch\Services\Settings\SettingsService;
use PDO;

/**
 * DEMO DATA ONLY. Every row is named/flagged DEMO (is_demo = 1) so it is never mistaken for production.
 * Idempotent: does nothing when DEMO-CUST-PRE already exists. Passwords are random and printed once.
 */
final class DemoSeeder
{
    public function __construct(private PDO $pdo)
    {
    }

    public function run(callable $log): void
    {
        $pdo = $this->pdo;
        if ($pdo->query("SELECT COUNT(*) FROM customers WHERE code = 'DEMO-CUST-PRE'")->fetchColumn() > 0) {
            $log('Demo data already present; skipping.');
            return;
        }
        $pdo->beginTransaction();
        try {
            $pdo->exec("INSERT INTO resellers (name, code, contact_email, is_demo) VALUES ('DEMO Reseller', 'DEMO-RES', 'reseller@demo.example.test', 1)");
            $resId = (int) $pdo->lastInsertId();

            $pdo->exec("INSERT INTO rate_plans (code, name, currency, initial_increment, subsequent_increment, connect_fee, is_demo) VALUES ('DEMO-12-6', 'DEMO plan 12/6', 'USD', 12, 6, 0, 1)");
            $planId = (int) $pdo->lastInsertId();
            $tar = $pdo->prepare('INSERT INTO rate_plan_tariffs (rate_plan_id, prefix, destination, rate) VALUES (?,?,?,?)');
            foreach ([['1', 'DEMO USA/Canada', '0.0100'], ['44', 'DEMO United Kingdom', '0.0150'], ['92', 'DEMO Pakistan', '0.0300'], ['971', 'DEMO UAE', '0.0400']] as [$p, $d, $r]) {
                $tar->execute([$planId, $p, $d, $r]);
            }
            (new RatePlanRepository($pdo))->createVersion($planId, null);

            $pdo->exec("INSERT INTO carriers (code, name, is_demo) VALUES ('DEMO-CARRIER', 'DEMO Carrier', 1)");
            $carrierId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO carrier_trunks (carrier_id, name, host, port, initial_increment, subsequent_increment) VALUES (?, 'DEMO Trunk A', '192.0.2.10', 5060, 6, 6)")->execute([$carrierId]);
            $trunkId = (int) $pdo->lastInsertId();
            $ct = $pdo->prepare('INSERT INTO carrier_tariffs (carrier_id, prefix, destination, rate) VALUES (?,?,?,?)');
            foreach ([['1', 'DEMO USA/Canada', '0.0060'], ['44', 'DEMO United Kingdom', '0.0090'], ['92', 'DEMO Pakistan', '0.0200'], ['971', 'DEMO UAE', '0.0280']] as [$p, $d, $r]) {
                $ct->execute([$carrierId, $p, $d, $r]);
            }

            $pdo->exec("INSERT INTO routing_plans (code, name, strategy, is_demo) VALUES ('DEMO-ROUTES', 'DEMO routing', 'priority', 1)");
            $routeId = (int) $pdo->lastInsertId();
            $rr = $pdo->prepare('INSERT INTO routing_rules (routing_plan_id, prefix, carrier_trunk_id, priority) VALUES (?,?,?,100)');
            foreach (['1', '44', '92', '971'] as $p) {
                $rr->execute([$routeId, $p, $trunkId]);
            }

            $cust = $pdo->prepare('INSERT INTO customers (reseller_id, name, code, contact_email, billing_mode, credit_limit, rate_plan_id, routing_plan_id, is_demo) VALUES (?,?,?,?,?,?,?,?,1)');
            $cust->execute([null, 'DEMO Customer (prepaid)', 'DEMO-CUST-PRE', 'prepaid@demo.example.test', 'prepaid', '0', $planId, $routeId]);
            $preId = (int) $pdo->lastInsertId();
            $cust->execute([$resId, 'DEMO Customer (postpaid)', 'DEMO-CUST-POST', 'postpaid@demo.example.test', 'postpaid', '500', $planId, $routeId]);
            $postId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $log('Created DEMO reseller, rate plan, carrier, routing and 2 customers.');

        $ledger = new LedgerService($pdo);
        $ledger->post(['customer_id' => $preId, 'entry_type' => 'initial_credit', 'amount' => '100.000000', 'currency' => 'USD', 'idempotency_key' => 'demo:initial:' . $preId, 'description' => 'DEMO initial credit']);

        // SIP account (HA1 only is stored; password shown once)
        $domain = (string) \Ownswitch\App::config('app.integrations.opensips.sip_domain', 'sip.example.test');
        $sipPass = SipUserController::generatePassword();
        [$ha1, $ha1b] = OpensipsProvisioner::ha1('demo1001', $domain, $sipPass);
        $pdo->prepare('INSERT INTO sip_accounts (reseller_id, customer_id, username, domain, ha1, ha1b, caller_id, max_channels, notes) VALUES (NULL,?,?,?,?,?,?,2,?)')
            ->execute([$preId, 'demo1001', $domain, $ha1, $ha1b, '15550001001', 'DEMO']);
        $log("DEMO SIP user: demo1001@{$domain} password: {$sipPass}");

        // Portal logins
        $roles = $pdo->query('SELECT code, id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
        $mk = function (string $email, string $name, string $role, ?int $res, ?int $cust) use ($pdo, $roles, $log): void {
            $pw = bin2hex(random_bytes(6)) . 'Aa1';
            $pdo->prepare('INSERT INTO users (email, name, password_hash, role_id, reseller_id, customer_id) VALUES (?,?,?,?,?,?)')
                ->execute([$email, $name, password_hash($pw, PASSWORD_DEFAULT), $roles[$role], $res, $cust]);
            $log("DEMO login: {$email} / {$pw}  ({$role})");
        };
        $mk('demo.customer@example.test', 'DEMO Customer Owner', 'customer_owner', null, $preId);
        $mk('demo.reseller@example.test', 'DEMO Reseller User', 'reseller', $resId, null);

        // Demo CDRs rated by the real pipeline (RatingService) so numbers are internally consistent.
        $settings = new SettingsService($pdo);
        $ingest = new CdrIngestService($pdo, new RatingService(), new RateResolver($pdo, $settings), $ledger, new PrepaidService($pdo, $ledger, new RatingService()), $settings, new CdrRepository($pdo));
        mt_srand(42);
        $dsts = ['14155550101', '442071234567', '923001234567', '971501234567'];
        $n = 0;
        for ($i = 0; $i < 60; $i++) {
            $answered = mt_rand(1, 100) <= 62;
            $cid = $i % 3 === 0 ? $postId : $preId;
            $setup = gmdate('Y-m-d H:i:s', time() - mt_rand(600, 3 * 86400));
            $dur = $answered ? mt_rand(3, 240) : 0;
            $res = $ingest->ingest([
                'external_cdr_key' => 'DEMO-' . $i, 'sip_call_id' => 'demo-call-' . $i . '@demo', 'src' => '1555000' . mt_rand(1000, 1999),
                'dst' => $dsts[$i % 4], 'setup_at' => $setup, 'duration' => $dur, 'customer_id' => $cid, 'carrier_trunk_id' => $trunkId,
                'disposition' => $answered ? 'ANSWERED' : ['NO_ANSWER', 'BUSY', 'FAILED'][$i % 3], 'sip_response' => $answered ? 200 : [480, 486, 503][$i % 3],
            ], 'seed');
            $n += $res['status'] === 'ingested' ? 1 : 0;
        }
        $pdo->exec("UPDATE cdrs SET is_demo = 1 WHERE source_system = 'seed'");
        $log("Ingested {$n} DEMO CDRs (flagged is_demo=1). Run: php bin/rollup_stats.php");
    }
}
