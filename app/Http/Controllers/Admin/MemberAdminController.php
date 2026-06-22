<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;

/**
 * ACP v4 · A2 (ADR-0096) — host for the per-member admin management screen. The admin route group already
 * gates admin.access; the embedded <livewire:admin.members.manage> SFC re-asserts the full guard
 * (admin.access + admin.members.access + staff-2FA) and gates every action by its own capability + the
 * rank + no-self guards, so this controller is a thin host.
 */
class MemberAdminController extends Controller
{
    public function __invoke(User $user): View
    {
        return view('admin.members.manage', ['user' => $user]);
    }
}
