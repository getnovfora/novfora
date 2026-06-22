<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Account\AccountDeletionException;
use App\Account\AccountDeletionService;
use App\AntiSpam\SpamCleaner;
use App\Models\Ban;
use App\Models\User;
use App\Moderation\OwnerStrandException;
use App\Moderation\OwnerStrandGuard;
use App\Permissions\Scope;
use App\Support\ActorRank;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ban management (security §3) — user / IP / email / range bans, plus the Spam Cleaner. All gated on
 * `bans.manage` through the permission engine and audited. Enforcement of user bans happens before ACL
 * resolution (BanChecker, security §1.2); IP/email bans are enforced at registration (RegistrationGuard).
 */
class BanController extends Controller
{
    public function store(Request $request, OwnerStrandGuard $ownerGuard): RedirectResponse
    {
        $this->authorizeBans($request);

        $data = $request->validate([
            'type' => ['required', 'in:user,ip,email,range'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'value' => ['nullable', 'string', 'max:191'],
            'reason' => ['nullable', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $actor = $request->user();

        // A USER ban flips the account to `banned` — an absolute lockout enforced by BanChecker BEFORE ACL
        // resolution — so it carries two guards the value-bans don't:
        //   • the actor-vs-target rank guard (phase-1.5 F-F): a mod can't ban an admin; and
        //   • the OWNER-STRAND backstop (apex, ADR-0100): a banned owner can never reach the panel to lift their
        //     own ban, so banning the sole owner is forum-fatal. The locked re-read, the Ban row and the status
        //     flip commit atomically — the re-read runs FIRST, closing the TOCTOU window against a concurrent ban.
        if ($data['type'] === 'user' && ! empty($data['user_id'])) {
            $target = User::find($data['user_id']);
            abort_unless($actor instanceof User && $target instanceof User && ActorRank::canActOn($actor, $target), 403);

            try {
                DB::transaction(function () use ($ownerGuard, $target, $data): void {
                    $ownerGuard->assertBanWontStrandOwnerTier($target);

                    $ban = Ban::create([
                        'user_id' => $target->getKey(),
                        'type' => 'user',
                        'value' => null,
                        'scope_type' => 'global',
                        'reason' => $data['reason'] ?? null,
                        'expires_at' => $data['expires_at'] ?? null,
                    ]);
                    User::whereKey($target->getKey())->update(['status' => 'banned']);
                    Audit::log('ban.created', $ban, ['type' => 'user']);
                });
            } catch (OwnerStrandException $e) {
                return back()->with('error', $e->getMessage());
            }

            return back();
        }

        // IP / email / range (value-based) ban — no account status flip, so no owner-strand surface.
        $ban = Ban::create([
            'user_id' => null,
            'type' => $data['type'],
            'value' => $data['value'] ?? null,
            'scope_type' => 'global',
            'reason' => $data['reason'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);
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

        // Rank check (phase-1.5 F-F): can't spam-clean a target of equal-or-higher rank.
        $actor = $request->user();
        abort_unless($actor instanceof User && ActorRank::canActOn($actor, $user), 403);

        // The cleaner BANS the account, so it runs the owner-strand backstop (ADR-0100) inside its transaction;
        // a refusal rolls back the whole clean (no content soft-deleted) and is surfaced gracefully here.
        try {
            $result = $cleaner->clean($actor, $user, 'Spam cleaner');
        } catch (OwnerStrandException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Removed {$result['topics']} topic(s) and {$result['posts']} post(s); account banned.");
    }

    /**
     * Admin-forced account deletion (ADR-0025) — step 1: show the same pre-deletion summary the voluntary
     * path shows, plus an explicit confirm. Gated identically to the destructive action (the SINGLE guard
     * predicate on the service) so an unauthorised actor never even sees the summary.
     */
    public function confirmDelete(Request $request, User $user): View
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && AccountDeletionService::canForceDelete($actor, $user), 403);

        return view('moderation.confirm-delete', [
            'user' => $user,
            'summary' => app(AccountDeletionService::class)->summary($user),
        ]);
    }

    /**
     * Admin-forced account deletion — step 2: execute. The service re-asserts the full guard (bans.manage +
     * rank + no-equal/higher-admin + no-self) and runs the one audited cascade; we require an explicit
     * confirmation field and surface the sole-admin block gracefully.
     */
    public function forceDelete(Request $request, User $user, AccountDeletionService $service): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && AccountDeletionService::canForceDelete($actor, $user), 403);

        $request->validate(['confirm' => ['accepted']]);
        $name = $user->display_name ?? $user->username;

        try {
            $service->deleteAccountAsAdmin($actor, $user);
        } catch (AccountDeletionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('forums.index')->with('status', "Account “{$name}” was permanently deleted.");
    }

    private function authorizeBans(Request $request): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('bans.manage', Scope::global()), 403);
    }
}
