<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;

/**
 * ACP v3 · v3-e — Admin → Members → (member) → Primary group. Resolves the target user via route-model binding
 * and passes them to the ⚡admin.members.edit-primary-group SFC, which self-guards and owns all writes.
 */
class MemberPrimaryGroupController extends Controller
{
    public function __invoke(User $user): View
    {
        return view('admin.members.primary-group', ['user' => $user]);
    }
}
