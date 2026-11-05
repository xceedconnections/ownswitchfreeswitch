<?php

declare(strict_types=1);

namespace Ownswitch\Tests\Security;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Middleware\RoleMiddleware;
use Ownswitch\Middleware\TenantMiddleware;
use Ownswitch\Repositories\CustomerRepository;
use Ownswitch\Repositories\SipAccountRepository;
use Ownswitch\Services\Cdr\CdrSearchService;
use Ownswitch\Support\TenantScope;
use Ownswitch\Tests\TestCase;

final class TenantIsolationTest extends TestCase
{
    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            return;
        }
        $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, reseller_id INTEGER NULL, name TEXT, code TEXT, contact_email TEXT, billing_mode TEXT,
            currency TEXT, timezone TEXT, status TEXT, credit_limit TEXT, max_concurrent_calls INT DEFAULT 10,
            rate_plan_id INT NULL, routing_plan_id INT NULL, media_policy TEXT NULL, recording_enabled INT DEFAULT 0, is_demo INT DEFAULT 0, created_at TEXT)');
        $this->pdo->exec('CREATE TABLE sip_accounts (id INTEGER PRIMARY KEY, reseller_id INTEGER NULL, customer_id INTEGER, username TEXT, domain TEXT, ha1 TEXT, ha1b TEXT,
            caller_id TEXT, max_channels INT DEFAULT 2, status TEXT, notes TEXT, password_changed_at TEXT, created_at TEXT)');
        // A (reseller 1), B (reseller 2), C (reseller 1), D (direct customer, no reseller)
        $this->pdo->exec("INSERT INTO customers (id, reseller_id, name, code) VALUES (1, 1, 'A', 'A'), (2, 2, 'B', 'B'), (3, 1, 'C', 'C'), (4, NULL, 'D', 'D')");
        $this->pdo->exec("INSERT INTO sip_accounts (id, reseller_id, customer_id, username, domain, ha1, ha1b) VALUES
            (10, 1, 1, 'a1', 'd', 'x', 'y'), (11, 2, 2, 'b1', 'd', 'x', 'y'), (12, 1, 3, 'c1', 'd', 'x', 'y'), (13, NULL, 4, 'd1', 'd', 'x', 'y')");
    }

    private function needDb(): \PDO
    {
        if ($this->pdo === null) {
            $this->skip('pdo_sqlite not loaded');
        }
        return $this->pdo;
    }

    // ---- SQL scope generation (no DB needed) ----

    public function testStaffAndUnboundReporterAreGlobal(): void
    {
        foreach ([new TenantScope('super_admin'), new TenantScope('admin'), new TenantScope('reporter')] as $s) {
            $this->assertTrue($s->isGlobal());
            $this->assertSame(['1 = 1', []], $s->where('owned'));
        }
    }

    public function testResellerAndCustomerScopesBindTheirOwnIds(): void
    {
        $res = new TenantScope('reseller', 5, 7, null);
        $this->assertSame(['reseller_id = ?', [7]], $res->where('owned'));
        $this->assertSame(['reseller_id = ?', [7]], $res->where('customer'));
        $this->assertSame(['id = ?', [7]], $res->where('reseller'));
        $this->assertSame(['c.reseller_id = ?', [7]], $res->where('owned', 'c'));

        $cust = new TenantScope('customer_owner', 6, 7, 42);
        $this->assertSame(['customer_id = ?', [42]], $cust->where('owned'));   // customer_id wins over reseller
        $this->assertSame(['id = ?', [42]], $cust->where('customer'));
    }

    public function testScopesFailClosed(): void
    {
        $deny = ['1 = 0', []];
        $this->assertSame($deny, (new TenantScope('reseller', 1, null, null))->where('owned'), 'reseller without reseller_id');
        $this->assertSame($deny, (new TenantScope('customer_owner', 1, 3, null))->where('owned'), 'customer without customer_id');
        $this->assertSame($deny, (new TenantScope('customer_owner', 1, null, 4))->where('none'), 'admin-only tables');
        $this->assertSame($deny, (new TenantScope('reseller', 1, 3, null))->where('none'));
        $this->assertSame($deny, (new TenantScope('customer_owner', 1, null, 4))->where('reseller'), 'customers cannot see resellers');
        $this->assertSame($deny, (new TenantScope('hacker', 1, 1, 1))->where('owned'), 'unknown role');
        $this->assertSame($deny, (new TenantScope('', null))->where('customer'));
    }

    public function testCanAccessCustomerMatrix(): void
    {
        $res1 = new TenantScope('reseller', 1, 1, null);
        $this->assertTrue($res1->canAccessCustomer(10, 1));
        $this->assertFalse($res1->canAccessCustomer(11, 2));
        $this->assertFalse($res1->canAccessCustomer(12, null), 'direct customers are not a reseller\'s');
        $cust = new TenantScope('customer_tech', 1, 1, 10);
        $this->assertTrue($cust->canAccessCustomer(10, 1));
        $this->assertFalse($cust->canAccessCustomer(11, 1), 'same reseller, other customer');
        $this->assertTrue((new TenantScope('admin'))->canAccessCustomer(99, 5));
    }

    // ---- middleware ----

    public function testRoleMiddlewareBlocksOtherRolesAndMissingUser(): void
    {
        $mw = new RoleMiddleware(['super_admin', 'admin']);
        $next = static fn (Request $r): Response => new Response('OK');

        $anon = new Request('GET', '/system/status');
        $this->assertSame(403, $mw->handle($anon, $next)->status);

        foreach (['reseller', 'customer_owner', 'customer_tech', 'reporter'] as $role) {
            $r = new Request('GET', '/system/status');
            $r->setAttr('user', ['id' => 1, 'role' => $role]);
            $this->assertSame(403, $mw->handle($r, $next)->status, "role {$role}");
        }
        $ok = new Request('GET', '/system/status');
        $ok->setAttr('user', ['id' => 1, 'role' => 'admin']);
        $this->assertSame(200, $mw->handle($ok, $next)->status);
    }

    public function testTenantMiddlewareDerivesScopeFromUserOnly(): void
    {
        $mw = new TenantMiddleware();
        $captured = null;
        $next = static function (Request $r) use (&$captured): Response {
            $captured = $r->attr('tenant');
            return new Response('OK');
        };
        // Forged query/post tenant ids must be ignored.
        $r = new Request('GET', '/cdrs', ['customer_id' => '999', 'reseller_id' => '999'], ['customer_id' => '999']);
        $r->setAttr('user', ['id' => 3, 'role' => 'customer_owner', 'reseller_id' => 1, 'customer_id' => 10]);
        $this->assertSame(200, $mw->handle($r, $next)->status);
        $this->assertTrue($captured instanceof TenantScope);
        $this->assertSame(10, $captured->customerId);

        $bad = new Request('GET', '/cdrs');
        $bad->setAttr('user', ['id' => 4, 'role' => 'customer_owner', 'reseller_id' => null, 'customer_id' => null]);
        $this->assertSame(403, $mw->handle($bad, $next)->status, 'unbound tenant user denied');
        $this->assertSame(403, $mw->handle(new Request('GET', '/'), $next)->status, 'no user denied');
    }

    // ---- repositories against a real (sqlite) schema ----

    public function testResellerSeesOnlyOwnCustomers(): void
    {
        $repo = new CustomerRepository($this->needDb());
        $res1 = $repo->paginate(new TenantScope('reseller', 1, 1, null));
        $names = array_column($res1['rows'], 'name');
        sort($names);
        $this->assertSame(['A', 'C'], $names);
        $this->assertSame(2, $res1['total']);
        $this->assertNull($repo->find(2, new TenantScope('reseller', 1, 1, null)), 'IDOR: customer of another reseller');
        $this->assertNull($repo->find(4, new TenantScope('reseller', 1, 1, null)), 'direct customer is not a reseller\'s');
        $this->assertNotNull($repo->find(1, new TenantScope('reseller', 1, 1, null)));
    }

    public function testCustomerSeesOnlyItself(): void
    {
        $repo = new CustomerRepository($this->needDb());
        $scope = new TenantScope('customer_owner', 9, 1, 1);
        $this->assertSame(1, $repo->paginate($scope)['total']);
        $this->assertNull($repo->find(3, $scope), 'sibling customer under same reseller');
        $this->assertSame(0, $repo->count(new TenantScope('customer_owner', 9, 1, null)), 'unbound customer sees nothing');
    }

    public function testCrossTenantUpdateAndDeleteAffectNothing(): void
    {
        $pdo = $this->needDb();
        $repo = new CustomerRepository($pdo);
        $res1 = new TenantScope('reseller', 1, 1, null);
        $this->assertFalse($repo->update(2, ['name' => 'HACKED'], $res1));
        $this->assertFalse($repo->delete(2, $res1));
        $this->assertSame('B', $pdo->query('SELECT name FROM customers WHERE id = 2')->fetchColumn());
        $this->assertTrue($repo->update(1, ['name' => 'A2'], $res1));
        $this->assertSame('A2', $pdo->query('SELECT name FROM customers WHERE id = 1')->fetchColumn());
    }

    public function testOwnedTableIsolationForSipAccounts(): void
    {
        $repo = new SipAccountRepository($this->needDb());
        $this->assertSame(['a1', 'c1'], $this->usernames($repo->paginate(new TenantScope('reseller', 1, 1, null))));
        $this->assertSame(['a1'], $this->usernames($repo->paginate(new TenantScope('customer_tech', 2, 1, 1))));
        $this->assertSame(['d1'], $this->usernames($repo->paginate(new TenantScope('customer_owner', 2, null, 4))));
        $this->assertCount(4, $repo->paginate(new TenantScope('admin'))['rows']);
        $this->assertNull($repo->find(11, new TenantScope('customer_owner', 2, 1, 1)), 'IDOR on SIP account');
        // filter by someone else's customer id is still intersected with the tenant clause
        $this->assertCount(0, $repo->paginate(new TenantScope('customer_owner', 2, 1, 1), ['customer_id' => '2'])['rows']);
    }

    /** @param array{rows:list<array<string,mixed>>} $r @return list<string> */
    private function usernames(array $r): array
    {
        $u = array_column($r['rows'], 'username');
        sort($u);
        return $u;
    }

    public function testInjectionAttemptsViaSortAndFiltersAreInert(): void
    {
        $pdo = $this->needDb();
        $repo = new CustomerRepository($pdo);
        $scope = new TenantScope('admin');
        $r = $repo->paginate($scope, ['name`; DROP TABLE customers; --' => 'x', 'status' => "' OR 1=1 --"], 1, 25, 'id; DROP TABLE customers', 'desc; DROP', "%' OR '1'='1");
        $this->assertSame(0, $r['total']);   // bogus filter values simply match nothing
        $this->assertSame(4, (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(), 'table intact');
    }

    public function testCdrSearchWhereAlwaysContainsTenantClause(): void
    {
        $svc = new CdrSearchService($this->needDb());
        [$sql, $params] = $svc->where(new TenantScope('customer_owner', 1, 1, 10), ['customer_id' => '999', 'src' => '5%5', 'carrier_id' => '5']);
        $this->assertStringContains('c.customer_id = ?', $sql);
        $this->assertSame(10, $params[0], 'tenant param bound first');
        $this->assertStringNotContains('carrier_id', $sql, 'customers cannot filter on carriers');
        [$sql2, ] = $svc->where(new TenantScope('customer_owner', 1, 1, null), []);
        $this->assertStringContains('1 = 0', $sql2, 'unbound customer fails closed');
        $this->assertContains('5\%5%', $params, 'LIKE wildcards in user input are escaped');
    }
}
