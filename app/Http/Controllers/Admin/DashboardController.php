<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\DashboardData;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * The ACP landing dashboard (ACP v1, PART 1). Read-only: pending actions → stat cards → health strip →
 * recent audit. Authorization is the admin route group (admin.access + staff-2FA); this controller only
 * gathers cheap data and renders. No writes, so no in-controller re-guard is needed (cf. the Livewire
 * SFCs, which self-guard because their actions bypass route middleware).
 */
class DashboardController extends Controller
{
    public function __invoke(DashboardData $data): View
    {
        return view('admin.dashboard', [
            'stats' => $data->stats(),
            'pending' => $data->pendingActions(),
            'health' => $data->health(),
            'recentAudit' => $data->recentAudit(),
        ]);
    }
}
