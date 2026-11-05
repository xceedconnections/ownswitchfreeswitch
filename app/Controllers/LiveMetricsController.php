<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Reports\ConcurrentCallsService;
use Ownswitch\Services\System\HostMetricsService;

/** JSON endpoints for realtime dashboard widgets (10s poll). */
final class LiveMetricsController extends BaseController
{
    public function host(Request $r, array $p = []): Response
    {
        if (!$this->scope($r)->isStaff()) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        return Response::json((new HostMetricsService())->snapshot());
    }

    public function concurrent(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $cid = $scope->customerId;
        if ($cid === null && $scope->isStaff()) {
            $q = $r->query('customer_id');
            $cid = is_string($q) && ctype_digit($q) ? (int) $q : null;
        }
        if ($cid === null) {
            return Response::json(['error' => 'no customer'], 400);
        }
        if ($scope->isCustomerRole() && (int) $scope->customerId !== (int) $cid) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        return Response::json((new ConcurrentCallsService($this->db()))->forCustomer((int) $cid));
    }
}
