<?php

declare(strict_types=1);

use Ownswitch\Controllers\AuditLogController;
use Ownswitch\Controllers\AuthController;
use Ownswitch\Controllers\BillingController;
use Ownswitch\Controllers\CarrierController;
use Ownswitch\Controllers\CallerIdGroupController;
use Ownswitch\Controllers\CliRestrictionController;
use Ownswitch\Controllers\CdrController;
use Ownswitch\Controllers\CustomRateController;
use Ownswitch\Controllers\CustomerController;
use Ownswitch\Controllers\DashboardController;
use Ownswitch\Controllers\DialerController;
use Ownswitch\Controllers\DestinationPolicyController;
use Ownswitch\Controllers\DncController;
use Ownswitch\Controllers\FirewallController;
use Ownswitch\Controllers\FraudController;
use Ownswitch\Controllers\InvoiceController;
use Ownswitch\Controllers\IpAuthController;
use Ownswitch\Controllers\LedgerController;
use Ownswitch\Controllers\LiveMetricsController;
use Ownswitch\Controllers\ModuleScaffoldController;
use Ownswitch\Controllers\MyRatesController;
use Ownswitch\Controllers\PaymentController;
use Ownswitch\Controllers\PlanPrefixController;
use Ownswitch\Controllers\AreaCodeController;
use Ownswitch\Controllers\AreaCodeImportController;
use Ownswitch\Controllers\PrefixController;
use Ownswitch\Controllers\RatePlanController;
use Ownswitch\Controllers\RecordingController;
use Ownswitch\Controllers\ReportController;
use Ownswitch\Controllers\ResellerController;
use Ownswitch\Controllers\RoutingController;
use Ownswitch\Controllers\SettingsController;
use Ownswitch\Controllers\SslController;
use Ownswitch\Controllers\WebrtcSettingsController;
use Ownswitch\Controllers\VersionController;
use Ownswitch\Controllers\VoucherController;
use Ownswitch\Controllers\VoucherRedeemController;
use Ownswitch\Controllers\SipUserController;
use Ownswitch\Controllers\StatsController;
use Ownswitch\Controllers\SystemController;
use Ownswitch\Controllers\TariffController;
use Ownswitch\Controllers\CallsOnlineController;
use Ownswitch\Controllers\DidController;
use Ownswitch\Controllers\FreeswitchXmlController;
use Ownswitch\Controllers\PrefixImportController;
use Ownswitch\Controllers\TariffImportController;
use Ownswitch\Http\Response;
use Ownswitch\Router;
use Ownswitch\Support\Menu;

/** @var Router $router */

$role = static fn (array $roles): string => 'role:' . implode(',', $roles);
$STAFF = $role(Menu::STAFF);

/** Register list/create/store/edit/update/delete for a CrudController instance. */
$crud = static function (Router $router, string $prefix, object $c, string $roles): void {
    $router->get($prefix, [$c, 'index'], [$roles]);
    $router->get($prefix . '/create', [$c, 'create'], [$roles]);
    $router->post($prefix, [$c, 'store'], [$roles]);
    $router->get($prefix . '/{id}/edit', [$c, 'edit'], [$roles]);
    $router->post($prefix . '/{id}', [$c, 'update'], [$roles]);
    $router->post($prefix . '/{id}/delete', [$c, 'destroy'], [$roles]);
};

// ---- FreeSWITCH xml_curl / xml_cdr (loopback nginx only, shared secret, no session) ----
$fsXml = new FreeswitchXmlController();
$router->post('/internal/fs/xml', [$fsXml, 'xml']);
$router->post('/internal/fs/directory', [$fsXml, 'xml']);
$router->post('/internal/fs/dialplan', [$fsXml, 'xml']);
$router->post('/internal/fs/cdr', [$fsXml, 'cdr']);

// ---- public ----
$router->group('', ['csrf'], function (Router $r): void {
    $r->get('/login', [AuthController::class, 'loginForm']);
    $r->post('/login', [AuthController::class, 'login']);
});

// ---- authenticated (session + tenant scope + CSRF on all unsafe methods) ----
$router->group('', ['auth', 'tenant', 'csrf'], function (Router $r) use ($crud, $role, $STAFF): void {
    $r->post('/logout', [AuthController::class, 'logout']);
    $r->get('/', [DashboardController::class, 'index']);
    $r->get('/account/password', [AuthController::class, 'passwordForm']);
    $r->post('/account/password', [AuthController::class, 'passwordChange']);
    $r->get('/account/profile', [AuthController::class, 'profileForm']);
    $r->post('/account/profile', [AuthController::class, 'profileSave']);

    // Live metrics (10s poll) — host for staff, concurrent for customers
    $live = new LiveMetricsController();
    $r->get('/api/live/host', [$live, 'host'], [$STAFF]);
    $r->get('/api/live/concurrent', [$live, 'concurrent'], [$role(Menu::SIP_USERS)]);

    // Clients — Magnus Users (+ legacy /customers redirects)
    $users = new CustomerController();
    $crud($r, '/users', $users, $role(Menu::RESELLER_UP));
    $r->get('/customers', static fn () => Response::redirect(url('/users')));
    $r->get('/customers/create', static fn () => Response::redirect(url('/users/create')));
    $r->get('/customers/{id}/edit', static function ($req, array $p) {
        return Response::redirect(url('/users/' . (int) $p['id'] . '/edit'));
    });
    $crud($r, '/resellers', new ResellerController(), $STAFF);
    $crud($r, '/sip-users', new SipUserController(), $role(Menu::SIP_USERS));
    $dialer = new DialerController();
    $r->get('/dialer', [$dialer, 'index'], [$role(Menu::SIP_USERS)]);
    $r->post('/dialer/credentials', [$dialer, 'credentials'], [$role(Menu::SIP_USERS)]);
    $callsOnline = new CallsOnlineController();
    $r->get('/calls-online', [$callsOnline, 'index'], [$role(Menu::SIP_USERS)]);
    $r->post('/calls-online/hangup', [$callsOnline, 'hangup'], [$role(Menu::SIP_USERS)]);
    $r->post('/calls-online/monitor', [$callsOnline, 'monitor'], [$STAFF]);
    $r->post('/calls-online/monitor-ext', [$callsOnline, 'saveMonitorExt'], [$STAFF]);
    $crud($r, '/ip-endpoints', new IpAuthController(), $role(Menu::SIP_USERS)); // IP allowlist
    // Legacy /ip-auth bookmarks
    $r->get('/ip-auth', static fn () => Response::redirect(url('/ip-endpoints')));
    $r->get('/ip-auth/create', static fn () => Response::redirect(url('/ip-endpoints/create')));
    $r->get('/ip-auth/{id}/edit', static function ($req, array $p) {
        return Response::redirect(url('/ip-endpoints/' . (int) $p['id'] . '/edit'));
    });
    $r->get('/ip-auth/{rest}', static fn () => Response::redirect(url('/ip-endpoints')));
    $cidGroups = new CallerIdGroupController();
    $crud($r, '/callerid-groups', $cidGroups, $STAFF);
    $r->post('/callerid-groups/{id}/numbers', [$cidGroups, 'addNumber'], [$STAFF]);
    $r->post('/callerid-groups/{id}/numbers/{numberId}/delete', [$cidGroups, 'deleteNumber'], [$STAFF]);
    $r->get('/my-rates', [MyRatesController::class, 'index'], [$role(Menu::CUSTOMER_RATES)]);
    $r->get('/my-rates/export', [MyRatesController::class, 'export'], [$role(Menu::CUSTOMER_RATES)]);

    // DIDs (inbound)
    $crud($r, '/did-providers', new DidController('providers'), $role(Menu::DID_STAFF));
    $crud($r, '/dids', new DidController('dids'), $role(Menu::DID_STAFF));
    $crud($r, '/did-assignments', new DidController('assignments'), $role(Menu::DID_VIEW));

    // Rates
    $crud($r, '/rate-plans', new RatePlanController(), $STAFF);
    $crud($r, '/plan-prefixes', new PlanPrefixController(), $STAFF);
    $crud($r, '/prefixes', new PrefixController(), $STAFF);
    $areaCodes = new AreaCodeController();
    $r->post('/area-codes/bulk-delete', [$areaCodes, 'bulkDestroy'], [$STAFF]);
    $crud($r, '/area-codes', $areaCodes, $STAFF);
    $aci = new AreaCodeImportController();
    $r->get('/area-codes/import', [$aci, 'form'], [$STAFF]);
    $r->get('/area-codes/export', [$aci, 'export'], [$STAFF]);
    $r->post('/area-codes/import/upload', [$aci, 'upload'], [$STAFF]);
    $r->post('/area-codes/import/preview', [$aci, 'preview'], [$STAFF]);
    $r->post('/area-codes/import/commit', [$aci, 'commit'], [$STAFF]);
    $tariffs = new TariffController('customer');
    $r->post('/tariffs/bulk-delete', [$tariffs, 'bulkDestroy'], [$STAFF]);
    $r->post('/tariffs/bulk-rate-update', [$tariffs, 'bulkRateUpdate'], [$STAFF]);
    $crud($r, '/tariffs', $tariffs, $STAFF);
    $crud($r, '/custom-rates', new CustomRateController(), $STAFF);
    $ti = new TariffImportController();
    $r->get('/tariffs/import', [$ti, 'form'], [$STAFF]);
    $r->get('/tariffs/export', [$ti, 'export'], [$STAFF]);
    $r->post('/tariffs/import/upload', [$ti, 'upload'], [$STAFF]);
    $r->post('/tariffs/import/preview', [$ti, 'preview'], [$STAFF]);
    $r->post('/tariffs/import/commit', [$ti, 'commit'], [$STAFF]);
    $pi = new PrefixImportController();
    $r->get('/prefixes/import', [$pi, 'form'], [$STAFF]);
    $r->get('/prefixes/export', [$pi, 'export'], [$STAFF]);
    $r->post('/prefixes/import/upload', [$pi, 'upload'], [$STAFF]);
    $r->post('/prefixes/import/preview', [$pi, 'preview'], [$STAFF]);
    $r->post('/prefixes/import/commit', [$pi, 'commit'], [$STAFF]);

    // Routes — Providers / Trunk Groups / Provider Rates (legacy paths redirect)
    $providers = new CarrierController('carrier');
    $crud($r, '/providers', $providers, $STAFF);
    $r->get('/carriers', static fn () => Response::redirect(url('/providers')));
    $r->get('/carriers/create', static fn () => Response::redirect(url('/providers/create')));
    $r->get('/carriers/{id}/edit', static function ($req, array $p) {
        return Response::redirect(url('/providers/' . (int) $p['id'] . '/edit'));
    });
    $crud($r, '/trunks', new CarrierController('trunk'), $STAFF);
    $providerRates = new TariffController('carrier');
    $r->post('/provider-rates/bulk-delete', [$providerRates, 'bulkDestroy'], [$STAFF]);
    $r->post('/provider-rates/bulk-rate-update', [$providerRates, 'bulkRateUpdate'], [$STAFF]);
    $crud($r, '/provider-rates', $providerRates, $STAFF);
    $r->get('/carrier-tariffs', static fn () => Response::redirect(url('/provider-rates')));
    $r->get('/carrier-tariffs/create', static fn () => Response::redirect(url('/provider-rates/create')));
    $r->get('/carrier-tariffs/{id}/edit', static function ($req, array $p) {
        return Response::redirect(url('/provider-rates/' . (int) $p['id'] . '/edit'));
    });
    $sim = new RoutingController('rules');
    $r->get('/routing/simulate', [$sim, 'simulateForm'], [$STAFF]);
    $r->post('/routing/simulate', [$sim, 'simulate'], [$STAFF]);
    $trunkGroups = new RoutingController('plans');
    $crud($r, '/trunk-groups', $trunkGroups, $STAFF);
    $crud($r, '/destination-policies', new DestinationPolicyController(), $STAFF);
    $cli = new CliRestrictionController();
    $r->get('/cli-restrictions', [$cli, 'index'], [$STAFF]);
    $r->get('/cli-restrictions/create', [$cli, 'create'], [$STAFF]);
    $r->post('/cli-restrictions', [$cli, 'store'], [$STAFF]);
    $r->get('/cli-restrictions/{id}', [$cli, 'numbers'], [$STAFF]);
    $r->post('/cli-restrictions/{id}', [$cli, 'update'], [$STAFF]);
    $r->post('/cli-restrictions/{id}/delete', [$cli, 'destroy'], [$STAFF]);
    $r->post('/cli-restrictions/{id}/numbers', [$cli, 'addNumbers'], [$STAFF]);
    $r->post('/cli-restrictions/{id}/numbers/{numberId}/delete', [$cli, 'deleteNumber'], [$STAFF]);
    $dnc = new DncController();
    $r->get('/dnc', [$dnc, 'index'], [$STAFF]);
    $r->get('/dnc/create', [$dnc, 'create'], [$STAFF]);
    $r->post('/dnc', [$dnc, 'store'], [$STAFF]);
    $r->get('/dnc/{id}', [$dnc, 'numbers'], [$STAFF]);
    $r->get('/dnc/{id}/edit', [$dnc, 'edit'], [$STAFF]);
    $r->post('/dnc/{id}', [$dnc, 'update'], [$STAFF]);
    $r->post('/dnc/{id}/delete', [$dnc, 'destroy'], [$STAFF]);
    $r->post('/dnc/{id}/numbers', [$dnc, 'addNumber'], [$STAFF]);
    $r->post('/dnc/{id}/numbers/delete', [$dnc, 'deleteNumbers'], [$STAFF]);
    $r->get('/dnc/{id}/import', [$dnc, 'importForm'], [$STAFF]);
    $r->post('/dnc/{id}/import/upload', [$dnc, 'importUpload'], [$STAFF]);
    $r->post('/dnc/{id}/import/preview', [$dnc, 'importPreview'], [$STAFF]);
    $r->post('/dnc/{id}/import/commit', [$dnc, 'importCommit'], [$STAFF]);
    $r->post('/dnc/{id}/rebuild-cache', [$dnc, 'rebuildCache'], [$STAFF]);
    $r->get('/routing/plans', static fn () => Response::redirect(url('/trunk-groups')));
    $r->get('/routing/plans/create', static fn () => Response::redirect(url('/trunk-groups/create')));
    $r->get('/routing/plans/{id}/edit', static function ($req, array $p) {
        return Response::redirect(url('/trunk-groups/' . (int) $p['id'] . '/edit'));
    });
    // Legacy /routing CRUD removed — Trunk Groups cover multi-trunk failover
    $r->get('/routing', static fn () => Response::redirect(url('/trunk-groups')));
    $r->get('/routing/create', static fn () => Response::redirect(url('/trunk-groups/create')));
    $r->get('/routing/{id}/edit', static function ($req, array $p) {
        return Response::redirect(url('/trunk-groups'));
    });

    // Reports
    $cdr = new CdrController();
    $all = $role(Menu::ALL);
    $r->get('/cdrs', [$cdr, 'index'], [$all]);
    $r->get('/cdrs/export', [$cdr, 'export'], [$all]);
    $r->post('/cdrs/delete', [$cdr, 'delete'], [$role(Menu::STAFF)]);
    $r->get('/cdrs/{id}', [$cdr, 'show'], [$all]);
    $rep = new ReportController();
    $r->get('/reports/cdr-failed', [$rep, 'cdrFailed'], [$all]);
    $r->get('/reports/asr-acd', static fn () => Response::redirect(url('/reports/summary-day')));
    $r->get('/reports/pdd', [$rep, 'pdd'], [$all]);
    $r->get('/reports/sip-response', [$rep, 'sipResponse'], [$all]);
    $r->get('/reports/cdr-reconciliation', [$rep, 'cdrReconciliation'], [$role(Menu::FINANCE)]);
    $r->get('/reports/financial', [$rep, 'financial'], [$role(Menu::FINANCE)]);
    // Slugs kept inline so a missing CallSummaryService class cannot 500 the whole app (incl. /login).
    $summaryReports = [
        'summary-day' => false,
        'summary-day-user' => false,
        'summary-day-trunk' => true,
        'summary-month' => false,
        'summary-month-user' => false,
        'summary-month-trunk' => true,
        'summary-user' => false,
        'summary-trunk' => true,
        'summary-destination' => false,
        'summary-month-destination' => false,
    ];
    foreach ($summaryReports as $slug => $staffOnly) {
        $repRoles = $staffOnly ? $STAFF : $all;
        $r->get('/reports/' . $slug, static function ($req, array $p) use ($rep, $slug) {
            return $rep->callSummary($req, ['slug' => $slug]);
        }, [$repRoles]);
    }

    // Billing
    $bill = new BillingController();
    $r->get('/billing/preview', [$bill, 'previewForm'], [$STAFF]);
    $r->post('/billing/preview', [$bill, 'preview'], [$STAFF]);
    $r->get('/billing/refill', [$bill, 'topupForm'], [$STAFF]);
    $r->post('/billing/refill', [$bill, 'topup'], [$STAFF]);
    $r->get('/billing/topup', static fn () => Response::redirect(url('/billing/refill')));
    $crud($r, '/vouchers', new VoucherController(), $STAFF);
    $redeem = new VoucherRedeemController();
    $r->get('/billing/redeem-voucher', [$redeem, 'form'], [$role(Menu::CUSTOMER)]);
    $r->post('/billing/redeem-voucher', [$redeem, 'redeem'], [$role(Menu::CUSTOMER)]);
    $pay = new PaymentController();
    $r->get('/payments', [$pay, 'index'], [$role(Menu::FINANCE)]);
    $r->get('/payments/create', [$pay, 'create'], [$role(Menu::RESELLER_UP)]);
    $r->post('/payments', [$pay, 'store'], [$role(Menu::RESELLER_UP)]);
    // Ledger / invoices removed from UI — keep redirects for old bookmarks
    $r->get('/ledger', static fn () => Response::redirect(url('/payments')));
    $r->get('/ledger/{id}/reverse', static fn () => Response::redirect(url('/payments')));
    $r->post('/ledger/{id}/reverse', static fn () => Response::redirect(url('/payments')));
    $r->get('/statements', static fn () => Response::redirect(url('/payments')));
    $r->get('/invoices', static fn () => Response::redirect(url('/payments')));
    $r->get('/invoices/{id}', static fn () => Response::redirect(url('/payments')));
    $r->post('/invoices/generate', static fn () => Response::redirect(url('/payments')));

    // Media & fraud (under Reports in menu)
    $rec = new RecordingController();
    $recRoles = $role(['super_admin', 'admin', 'reseller', 'customer_owner']);
    $r->get('/recordings', [$rec, 'index'], [$recRoles]);
    $r->get('/recordings/{id}/play', [$rec, 'play'], [$recRoles]);
    $r->post('/recordings/delete', [$rec, 'deleteSelected'], [$STAFF]);
    $r->post('/recordings/{id}/delete', [$rec, 'delete'], [$STAFF]);
    $fraud = new FraudController();
    $r->get('/fraud', [$fraud, 'index'], [$role(Menu::RESELLER_UP)]);
    $r->post('/fraud/scan', [$fraud, 'scan'], [$STAFF]);
    $r->post('/fraud/{id}/ack', [$fraud, 'acknowledge'], [$role(Menu::RESELLER_UP)]);
    $r->post('/fraud/rules/{id}', [$fraud, 'updateRule'], [$STAFF]);
    $r->get('/audit-logs', [new AuditLogController(), 'index'], [$STAFF]);

    // Settings (includes kill switch, CPS/concurrent caps, failover SIP codes, tax)
    $r->get('/system/status', [new SystemController(), 'status'], [$STAFF]);
    $stats = new StatsController();
    $r->get('/stats/users', [$stats, 'users'], [$STAFF]);
    $r->get('/stats/carriers', [$stats, 'carriers'], [$STAFF]);
    $settings = new SettingsController();
    $r->get('/branding/logo', [$settings, 'logo']);
    $r->get('/settings', [$settings, 'edit'], [$STAFF]);
    $r->post('/settings', [$settings, 'update'], [$STAFF]);
    $ssl = new SslController();
    $r->get('/settings/ssl', [$ssl, 'index'], [$STAFF]);
    $r->post('/settings/ssl', [$ssl, 'install'], [$STAFF]);
    $webrtc = new WebrtcSettingsController();
    $r->get('/settings/webrtc', [$webrtc, 'index'], [$STAFF]);
    $r->post('/settings/webrtc', [$webrtc, 'enable'], [$STAFF]);
    $fw = new FirewallController();
    $r->get('/settings/firewall', [$fw, 'index'], [$STAFF]);
    $r->post('/settings/firewall/unban', [$fw, 'unban'], [$STAFF]);
    $r->post('/settings/firewall/ban', [$fw, 'ban'], [$STAFF]);
    $r->post('/settings/firewall/config', [$fw, 'saveConfig'], [$STAFF]);
    $ver = new VersionController();
    $r->get('/settings/version', [$ver, 'index'], [$STAFF]);
    $r->get('/settings/version/update', static fn () => Response::redirect(url('/settings/version')));
    $r->post('/settings/version/update', [$ver, 'update'], [$STAFF]);

    // Magnus scaffold modules (every remaining menu item)
    $r->get('/modules/{slug}', [new ModuleScaffoldController(), 'show'], [$STAFF]);
});
