<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\AntiSpam\SpamCleaner;
use App\Models\Ban;
use App\Models\User;
use App\Permissions\Scope;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Ban management (security §3) — user / IP / email / range bans, plus the Spam Cleaner. All gated on
 * `bans.manage` through the permission engine and audited. Enforcement of user bans happens before ACL
 * resolution (BanChecker, security §1.2); IP/email bans are enforced at registration (RegistrationGuard).
 */
class BanController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeBans($request);

        $data = $request->validate([
            'type' => ['required', 'in:user,ip,email,range'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'value' => ['nullable', 'string', 'max:191'],
            'reason' => ['nullable', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $ban = Ban::create([
            'user_id' => $data['type'] === 'user' ? ($data['user_id'] ?? null) : null,
            'type' => $data['type'],
            'value' => $data['type'] === 'user' ? null : ($data['value'] ?? null),
            'scope_type' => 'global',
            'reason' => $data['reason'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        if ($ban->type === 'user' && $ban->user_id) {
            User::whereKey($ban->user_id)->update(['status' => 'banned']);
        }
        Audit::log('ban.created', $ban, ['type' => $ban->type]);

        return back();
    }

    public function destroy(Request $request, Ban $ban): RedirectResponse
    {
        $this->authorizeBans($request);

        if ($ban->type === 'user' && $ban->user_id) {
            User::whereKey($ban->user_id)->where('status', 'banned')->update(['status' => 'active']);
        }
        $ban->delete();
        Audit::log('ban.lifted', $ban);

        return back();
    }

    public function spamClean(Request $request, User $user, SpamCleaner $cleaner): RedirectResponse
    {
        $this->authorizeBans($request);

        $result = $cleaner->clean($request->user(), $user, 'Spam cleaner');

        return back()->with('status', "Removed {$result['topics']} topic(s) and {$result['posts']} post(s); account banned.");
    }

    private function authorizeBans(Request $request): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('bans.manage', Scope::global()), 403);
    }
}
