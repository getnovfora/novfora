<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Forum;
use Illuminate\Contracts\View\View;

/**
 * ACP v3 · v3-c — the per-forum "Permissions" home (Forums → a forum → Permissions). Renders the card-per-group
 * editor scoped to this forum; the editor SFC self-guards (manage-permissions capability + rank guard), so a
 * non-permission-admin who reaches the page is 403'd by the component's mount(). Read-only here.
 */
class ForumPermissionsController extends Controller
{
    public function __invoke(Forum $forum): View
    {
        return view('admin.forum-permissions', ['forum' => $forum]);
    }
}
