<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\AntiSpam\WarningService;
use App\Models\User;
use App\Models\Warning;
use App\Models\WarningType;
use App\Permissions\Scope;
use App\Support\ActorRank;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Warnings / infractions (security §3). Staff (bans.manage) issue a typed warning; the member sees and
 * acknowledges their own warnings (the IPS "acknowledge to restore posting" flow).
 */
class WarningController extends Controller
{
    public function store(Request $request, User $user, WarningService $service): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->canDo('bans.manage', Scope::global()), 403);

        // Rank check (phase-1.5 F-F): can't warn a target of equal-or-higher rank (a mod can't warn an admin).
        abort_unless(ActorRank::canActOn($actor, $user), 403);

        $data = $request->validate([
            'warning_type_id' => ['required', 'integer', 'exists:warning_types,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $service->issue($actor, $user, WarningType::findOrFail($data['warning_type_id']), $data['reason'] ?? null);

        return back();
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $warnings = Warning::where('user_id', $user->getKey())->with('type')->latest()->get();

        return view('warnings.index', compact('warnings'));
    }

    public function acknowledge(Request $request, Warning $warning, WarningService $service): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $service->acknowledge($user, $warning);

        return back();
    }
}
