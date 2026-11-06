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
        $tz = \Ownswitch\Support\Timezone::fromUser($this->user($r));
        $data = (new LiveDialogStatsService($this->db()))->byCustomer($tz);
        return $this->view('stats/users', [
            'title' => 'Stats · Users',
            'rows' => $data['rows'],
            'totals' => $data['totals'],
            'error' => $data['error'],
            'refreshedAt' => \Ownswitch\Support\Timezone::format(gmdate('Y-m-d H:i:s'), $tz) . ' ' . \Ownswitch\Support\Timezone::label($tz),
        ]);
    }

    public function carriers(Request $r, array $p = []): Response
    {
        $tz = \Ownswitch\Support\Timezone::fromUser($this->user($r));
        $data = (new LiveDialogStatsService($this->db()))->byTrunk($tz);
        return $this->view('stats/carriers', [
            'title' => 'Stats · Carriers',
            'rows' => $data['rows'],
            'totals' => $data['totals'],
            'error' => $data['error'],
            'refreshedAt' => \Ownswitch\Support\Timezone::format(gmdate('Y-m-d H:i:s'), $tz) . ' ' . \Ownswitch\Support\Timezone::label($tz),
        ]);
    }
}
