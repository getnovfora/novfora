<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Forum;
use Illuminate\Contracts\View\View;

/**
 * ACP v3 · v3-b — the per-forum "Moderators" home (Forums → a forum → Moderators). Renders the assignment SFC
 * scoped to this forum; the SFC self-guards (admin.access + permissions.manage + staff-2FA, then the projector's
 * ceiling/rank fences), so a non-manager who reaches the page is 403'd by the component's mount(). Read-only here.
 */
class ForumModeratorsController extends Controller
{
    public function __invoke(Forum $forum): View
    {
        return view('admin.forum-moderators', ['forum' => $forum]);
    }
}
