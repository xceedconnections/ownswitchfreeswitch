<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Reports\LiveDialogStatsService;

/** Admin real-time User / Carrier stats (live dialogs + today's ASR/ACD). */
final class StatsController extends BaseController
{
    public function users(Request $r, array $p = []): Response
    {
        $data = (new LiveDialogStatsService($this->db()))->byCustomer();
        return $this->view('stats/users', [
            'title' => 'Stats · Users',
            'rows' => $data['rows'],
            'totals' => $data['totals'],
            'error' => $data['error'],
            'refreshedAt' => gmdate('Y-m-d H:i:s') . ' UTC',
        ]);
    }

    public function carriers(Request $r, array $p = []): Response
    {
        $data = (new LiveDialogStatsService($this->db()))->byTrunk();
        return $this->view('stats/carriers', [
            'title' => 'Stats · Carriers',
            'rows' => $data['rows'],
            'totals' => $data['totals'],
            'error' => $data['error'],
            'refreshedAt' => gmdate('Y-m-d H:i:s') . ' UTC',
        ]);
    }
}
