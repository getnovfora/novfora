<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\ScheduledTasks;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Admin → System → Tasks (ACP v1, PART 4). Read-only visibility into the cron-driven scheduled tasks.
 * Gated by the admin/system route group (admin.access + staff-2FA); no writes.
 */
class TasksController extends Controller
{
    public function __invoke(ScheduledTasks $tasks): View
    {
        return view('admin.tasks', ['tasks' => $tasks->list()]);
    }
}
