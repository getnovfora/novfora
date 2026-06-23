<?php
// SPDX-License-Identifier: Apache-2.0
use App\Account\AccountDeletionService;
use App\AntiSpam\TrustLevelManager;
use App\AntiSpam\WarningService;
use App\Community\ReputationService;
use App\Models\User;
use App\Models\Warning;
use App\Models\WarningType;
use App\Moderation\UserBanService;
use App\Permissions\Scope;
use App\Support\ActorRank;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Livewire\Component;

/**
 * Admin → Members → Manage member (ACP v4 · A2 · ADR-0096). Consolidates the per-member admin actions that
 * were scattered in the front-end mod CP: account summary, group membership (reuses ⚡edit-primary-group),
 * ban (issue/lift via the shared UserBanService), warnings (issue + history via WarningService), a read-only
 * IP/session list, and a force-password-reset.
 *
 * APEX (member PII + moderation boundary):
 *  - Every entry point re-asserts admin.access + admin.members.access + staff-2FA (Livewire bypasses route mw).
 *  - Each action is gated by its own capability: ban/warn need bans.manage; reset + the IP list need users.manage.
 *  - Each destructive action runs the rank guard (ActorRank) AND an explicit NO-SELF guard — ActorRank returns
 *    true for an admin acting on themselves, so the no-self guard is what stops an admin from banning/warning
 *    themselves out of access. Group changes reuse PrimaryGroupService (its own last-owner guards).
 *  - The engine's guards are REUSED, never re-implemented.
 */
new class extends Component
{
    public int $userId = 0;

    public string $banReason = '';

    public string $banExpiresAt = '';

    public ?int $warningTypeId = null;

    public string $warnReason = '';

    // F2: manual trust override + signed reputation adjustment (each gated by its own new capability key).
    public ?int $trustLevel = null;

    public string $trustReason = '';

    public string $repDelta = '';

    public string $repReason = '';

    public ?string $flash = null;

    public ?string $error = null;

    private ?User $targetCache = null;

    public function mount(int $userId): void
    {
        $this->userId = $userId;
        $this->ensureCanView();
    }

    public function banUser(): void
    {
        $this->ensureCanBan();
        $target = $this->target();
        $this->assertActionable($target);
        $this->assertNotSoleOwner($target);
        $this->validate([
            'banReason' => ['nullable', 'string', 'max:500'],
            'banExpiresAt' => ['nullable', 'date'],
        ]);

        app(UserBanService::class)->ban(
            $target,
            $this->banReason !== '' ? $this->banReason : null,
            $this->banExpiresAt !== '' ? Carbon::parse($this->banExpiresAt) : null,
        );
        $this->reset('banReason', 'banExpiresAt');
        $this->targetCache = null;
        $this->flash = 'Member banned.';
    }

    public function liftBan(): void
    {
        $this->ensureCanBan();
        $this->assertActionable($this->target()); // rank guard (defense-in-depth; mirrors banUser/warnMember)
        $ban = app(UserBanService::class)->activeBan($this->target());
        if ($ban !== null) {
            app(UserBanService::class)->lift($ban);
            $this->targetCache = null;
            $this->flash = 'Ban lifted.';
        }
    }

    public function warnMember(): void
    {
        $this->ensureCanBan(); // warnings reuse bans.manage — there is no separate warnings key
        $target = $this->target();
        $this->assertActionable($target);
        $this->assertNotSoleOwner($target);
        $this->validate([
            'warningTypeId' => ['required', 'integer', 'exists:warning_types,id'],
            'warnReason' => ['nullable', 'string', 'max:500'],
        ]);

        $type = WarningType::where('is_active', true)->findOrFail($this->warningTypeId);
        app(WarningService::class)->issue($this->actor(), $target, $type, $this->warnReason !== '' ? $this->warnReason : null);
        $this->reset('warningTypeId', 'warnReason');
        $this->targetCache = null;
        $this->flash = 'Warning issued.';
    }

    public function forcePasswordReset(): void
    {
        $this->ensureCanManageUsers();
        $target = $this->target();
        abort_unless(ActorRank::canActOn($this->actor(), $target), 403);

        if ($target->email) {
            Password::sendResetLink(['email' => $target->email]);
        }
        $this->flash = 'A password-reset email has been sent to the member.';
    }

    /**
     * F2 (ADR-0101): manually set the member's trust level (admin override). Gated by members.trust.manage +
     * the rank guard; a staff action, so it is NOT anti-spam-gated. Reuses TrustLevelManager (the trust-group
     * swap + MembershipCache flush + audit), which marks the level sticky so the auto-recompute won't demote it.
     */
    public function setTrustLevel(): void
    {
        $this->ensureCanManageTrust();
        $target = $this->target();
        abort_unless(ActorRank::canActOn($this->actor(), $target), 403);
        $this->validate([
            'trustLevel' => ['required', 'integer', 'between:0,4'],
            'trustReason' => ['nullable', 'string', 'max:500'], // cap the free-text reason server-side (audit JSON)
        ]);

        app(TrustLevelManager::class)->manualSet(
            $target,
            (int) $this->trustLevel,
            $this->actor(),
            $this->trustReason !== '' ? $this->trustReason : null,
        );
        $this->reset('trustReason');
        $this->targetCache = null; // re-read with the new trust group (and trust_locked)
        $this->flash = 'Trust level set to TL'.(int) $this->trustLevel.'.';
    }

    /**
     * F2 (ADR-0101): apply a signed reputation adjustment with a required reason. Gated by
     * members.reputation.manage + the rank guard. Routes the delta through the existing reputation ledger
     * (ReputationService::adminAdjust — the audit row is the ledger source), never a side write to the denorm.
     */
    public function adjustReputation(): void
    {
        $this->ensureCanManageReputation();
        $target = $this->target();
        abort_unless(ActorRank::canActOn($this->actor(), $target), 403);
        $this->validate([
            'repDelta' => ['required', 'integer', 'not_in:0', 'between:-100000,100000'],
            'repReason' => ['required', 'string', 'max:500'],
        ]);

        app(ReputationService::class)->adminAdjust($target, (int) $this->repDelta, $this->actor(), $this->repReason);
        $this->reset('repDelta', 'repReason');
        $this->targetCache = null; // re-read the new reputation_points denorm
        $this->flash = 'Reputation adjusted.';
    }

    public function target(): User
    {
        return $this->targetCache ??= User::with('groups')->findOrFail($this->userId);
    }

    public function actor(): User
    {
        $u = auth()->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }

    public function canSeeEmail(): bool
    {
        return $this->actor()->canDo('users.manage', Scope::global());
    }

    public function canBan(): bool
    {
        return $this->actor()->canDo('bans.manage', Scope::global());
    }

    public function canManageTrust(): bool
    {
        return $this->actor()->canDo('members.trust.manage', Scope::global());
    }

    public function canManageReputation(): bool
    {
        return $this->actor()->canDo('members.reputation.manage', Scope::global());
    }

    public function activeBan()
    {
        return $this->canBan() ? app(UserBanService::class)->activeBan($this->target()) : null;
    }

    /** @return Collection<int, WarningType> */
    public function warningTypes(): Collection
    {
        return WarningType::where('is_active', true)->orderBy('default_points')->get();
    }

    /** @return Collection<int, Warning> */
    public function warningHistory(): Collection
    {
        $this->ensureCanBan();

        return Warning::where('user_id', $this->userId)->with('type')->latest()->get();
    }

    /** @return Collection<int, object> read-only active sessions (IP / device) — PII, gated by users.manage. */
    public function sessions(): Collection
    {
        $this->ensureCanManageUsers();

        return DB::table('sessions')->where('user_id', $this->userId)
            ->orderByDesc('last_activity')->limit(20)->get();
    }

    /** NO-SELF + rank guard. ActorRank returns true for an admin acting on self, so no-self is the real fence. */
    private function assertActionable(User $target): void
    {
        abort_if($target->getKey() === $this->actor()->getKey(), 403);
        abort_unless(ActorRank::canActOn($this->actor(), $target), 403);
    }

    /**
     * Last-owner guard (apex-review HIGH, mirrors the deletion path ADR-0080): never ban/warn the sole admin or
     * sole co-owner out of access. NOT applied to liftBan (lifting a ban is restorative). NOTE: the front-end
     * ban/warn paths (BanController + the WarningService auto-ban consequence) still lack this guard — a
     * PRE-EXISTING systemic gap flagged for an engine fast-follow (the strand is reachable there too).
     */
    private function assertNotSoleOwner(User $target): void
    {
        $svc = app(AccountDeletionService::class);
        abort_if($svc->isSoleAdmin($target) || $svc->isSoleCoOwner($target), 403);
    }

    private function ensureCanView(): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('admin.access', Scope::global()), 403);
        abort_unless($u->canDo('admin.members.access', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }

    private function ensureCanBan(): void
    {
        $this->ensureCanView();
        abort_unless($this->actor()->canDo('bans.manage', Scope::global()), 403);
    }

    private function ensureCanManageUsers(): void
    {
        $this->ensureCanView();
        abort_unless($this->actor()->canDo('users.manage', Scope::global()), 403);
    }

    private function ensureCanManageTrust(): void
    {
        $this->ensureCanView();
        abort_unless($this->actor()->canDo('members.trust.manage', Scope::global()), 403);
    }

    private function ensureCanManageReputation(): void
    {
        $this->ensureCanView();
        abort_unless($this->actor()->canDo('members.reputation.manage', Scope::global()), 403);
    }
};
?>

<div class="space-y-4">
    @php($member = $this->target())
    @php($canSeeEmail = $this->canSeeEmail())
    @php($canBan = $this->canBan())
    @php($activeBan = $this->activeBan())
    @php($isSelf = $member->getKey() === auth()->id())
    @php($soleOwnerSvc = app(\App\Account\AccountDeletionService::class))
    @php($isSoleOwner = $soleOwnerSvc->isSoleAdmin($member) || $soleOwnerSvc->isSoleCoOwner($member))
    @php($actor = auth()->user())
    @php($canActOnTarget = $actor instanceof \App\Models\User && \App\Support\ActorRank::canActOn($actor, $member))

    <a href="{{ route('admin.members.index') }}" class="inline-flex items-center gap-1 text-sm text-ink-muted hover:text-ink" dusk="back-to-members">
        <x-ui.icon name="arrow-left" class="h-4 w-4" /> All members
    </a>

    @if ($flash)
        <div class="rounded-md border border-success/40 bg-success-soft px-4 py-2.5 text-sm text-success-ink" dusk="member-flash">{{ $flash }}</div>
    @endif
    @if ($error)
        <div class="rounded-md border border-danger/40 bg-danger-soft px-4 py-2.5 text-sm text-danger-ink">{{ $error }}</div>
    @endif

    {{-- Account summary --}}
    <x-ui.card>
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="text-lg font-semibold text-ink"><x-ui.user-name :user="$member" /></h2>
            @php($tone = ['active' => 'success', 'pending' => 'warn', 'suspended' => 'warn', 'banned' => 'danger'][$member->status] ?? 'neutral')
            <x-ui.badge :variant="$tone">{{ ucfirst((string) $member->status) }}</x-ui.badge>
        </div>
        <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Username</dt><dd class="text-ink">{{ '@'.($member->username ?? '—') }}</dd></div>
            @if ($canSeeEmail)
                <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Email</dt><dd class="text-ink break-all">{{ $member->email }}</dd></div>
            @endif
            <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Trust level</dt><dd class="text-ink">TL{{ $member->trustLevel() }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Posts</dt><dd class="text-ink">{{ (int) ($member->post_count ?? 0) }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Reputation</dt><dd class="text-ink">{{ (int) ($member->reputation_points ?? 0) }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Joined</dt><dd class="text-ink">{{ optional($member->created_at)->format('M j, Y') }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Last active</dt><dd class="text-ink">{{ $member->last_active_at ? \Illuminate\Support\Carbon::parse($member->last_active_at)->diffForHumans() : '—' }}</dd></div>
            <div class="flex justify-between gap-3 sm:col-span-2"><dt class="text-ink-subtle">Groups</dt><dd class="text-ink text-right">{{ $member->groups->pluck('name')->join(', ') ?: '—' }}</dd></div>
        </dl>
    </x-ui.card>

    {{-- Group membership (reuses the v3-e primary-group editor) --}}
    <x-ui.card>
        <h3 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle mb-3">Group membership</h3>
        <livewire:admin.members.edit-primary-group :user-id="$member->id" :key="'epg-'.$member->id" />
    </x-ui.card>

    @if ($this->canManageTrust())
        {{-- Trust level (manual admin override — F2) --}}
        <x-ui.card>
            <h3 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle mb-3">Trust level</h3>
            <p class="text-sm text-ink-muted mb-3">
                Current: <strong class="text-ink">TL{{ $member->trustLevel() }}</strong>
                @if ($member->trust_locked)<span class="text-ink-subtle"> · admin-locked (auto-recompute will not demote below this)</span>@endif
            </p>
            @if (! $canActOnTarget)
                <p class="text-sm text-ink-subtle">This member outranks you — you cannot change their trust level.</p>
            @else
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="trust-level" class="block text-sm font-medium text-ink mb-1.5">Set trust level</label>
                        <select id="trust-level" wire:model="trustLevel"
                                class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                            <option value="">Select a level…</option>
                            @for ($i = 0; $i <= 4; $i++)
                                <option value="{{ $i }}">TL{{ $i }}</option>
                            @endfor
                        </select>
                        @error('trustLevel') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="trust-reason" class="block text-sm font-medium text-ink mb-1.5">Reason (optional)</label>
                        <input id="trust-reason" wire:model="trustReason" maxlength="500"
                               class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                    </div>
                    <div class="sm:col-span-2">
                        <x-ui.button variant="subtle" size="sm" wire:click="setTrustLevel" dusk="set-trust">Set trust level</x-ui.button>
                    </div>
                </div>
                <p class="mt-2 text-xs text-ink-subtle">
                    A manual set is a sticky override: the auto-recompute won’t demote below it (it may still promote above, and serious infractions still hard-demote to TL0).
                </p>
            @endif
        </x-ui.card>
    @endif

    @if ($this->canManageReputation())
        {{-- Reputation (signed manual adjustment — F2) --}}
        <x-ui.card>
            <h3 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle mb-3">Reputation</h3>
            <p class="text-sm text-ink-muted mb-3">Current: <strong class="text-ink nums">{{ (int) $member->reputation_points }}</strong></p>
            @if (! $canActOnTarget)
                <p class="text-sm text-ink-subtle">This member outranks you — you cannot adjust their reputation.</p>
            @else
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="rep-delta" class="block text-sm font-medium text-ink mb-1.5">Adjust by (signed, e.g. 5 or −3)</label>
                        <input id="rep-delta" type="number" inputmode="numeric" wire:model="repDelta"
                               class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                        @error('repDelta') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="rep-reason" class="block text-sm font-medium text-ink mb-1.5">Reason (required)</label>
                        <input id="rep-reason" wire:model="repReason" maxlength="500"
                               class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                        @error('repReason') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <x-ui.button variant="subtle" size="sm" wire:click="adjustReputation" dusk="adjust-rep">Apply adjustment</x-ui.button>
                    </div>
                </div>
                <p class="mt-2 text-xs text-ink-subtle">Writes through the reputation ledger and is audited (actor, reason, old → new).</p>
            @endif
        </x-ui.card>
    @endif

    @if ($canBan)
        {{-- Ban --}}
        <x-ui.card>
            <h3 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle mb-3">Ban</h3>
            @if ($activeBan)
                <p class="text-sm text-ink-muted mb-3">
                    This member is <strong class="text-danger">banned</strong>@if ($activeBan->reason) — “{{ $activeBan->reason }}”@endif.
                    @if ($activeBan->expires_at) Expires {{ \Illuminate\Support\Carbon::parse($activeBan->expires_at)->diffForHumans() }}.@else Permanent.@endif
                </p>
                <x-ui.button variant="danger-ghost" size="sm" wire:click="liftBan" dusk="lift-ban">Lift ban</x-ui.button>
            @elseif ($isSelf || $isSoleOwner)
                <p class="text-sm text-ink-subtle">{{ $isSelf ? 'You cannot ban your own account.' : 'The last administrator / co-owner cannot be banned.' }}</p>
            @else
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="ban-reason" class="block text-sm font-medium text-ink mb-1.5">Reason (optional)</label>
                        <input id="ban-reason" wire:model="banReason" maxlength="500"
                               class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                        @error('banReason') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="ban-expires" class="block text-sm font-medium text-ink mb-1.5">Expires (blank = permanent)</label>
                        <input id="ban-expires" type="date" wire:model="banExpiresAt"
                               class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                        @error('banExpiresAt') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-end">
                        <x-ui.button variant="danger" wire:click="banUser" dusk="ban-user">Ban member</x-ui.button>
                    </div>
                </div>
            @endif
        </x-ui.card>

        {{-- Warnings --}}
        <x-ui.card>
            <h3 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle mb-3">Warnings</h3>
            @if ($isSelf || $isSoleOwner)
                <p class="text-sm text-ink-subtle mb-3">{{ $isSelf ? 'You cannot warn your own account.' : 'The last administrator / co-owner cannot be warned.' }}</p>
            @else
                <div class="grid gap-3 sm:grid-cols-2 mb-4">
                    <div>
                        <label for="warn-type" class="block text-sm font-medium text-ink mb-1.5">Type</label>
                        <select id="warn-type" wire:model="warningTypeId"
                                class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                            <option value="">Select a warning type…</option>
                            @foreach ($this->warningTypes() as $t)
                                <option value="{{ $t->id }}">{{ $t->label }} ({{ (int) $t->default_points }} pts)</option>
                            @endforeach
                        </select>
                        @error('warningTypeId') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="warn-reason" class="block text-sm font-medium text-ink mb-1.5">Note (optional)</label>
                        <input id="warn-reason" wire:model="warnReason" maxlength="500"
                               class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                    </div>
                    <div class="sm:col-span-2">
                        <x-ui.button variant="subtle" size="sm" wire:click="warnMember" dusk="warn-member">Issue warning</x-ui.button>
                    </div>
                </div>
            @endif

            @php($history = $this->warningHistory())
            @if ($history->isEmpty())
                <p class="text-sm text-ink-subtle">No warnings on record.</p>
            @else
                <ul class="divide-y divide-line text-sm">
                    @foreach ($history as $w)
                        <li class="py-2 flex items-center justify-between gap-3">
                            <span class="text-ink">{{ $w->type?->label ?? 'Warning' }} <span class="text-ink-subtle">({{ (int) $w->points }} pts)</span>@if ($w->reason) — {{ $w->reason }}@endif</span>
                            <span class="text-ink-subtle whitespace-nowrap">
                                {{ optional($w->created_at)->format('M j, Y') }}
                                @if ($w->expires_at && \Illuminate\Support\Carbon::parse($w->expires_at)->isFuture()) · live @else · expired @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @endif

    @if ($canSeeEmail)
        {{-- Account security: force a password reset + read-only session/IP list (PII → users.manage only) --}}
        <x-ui.card>
            <h3 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle mb-3">Account security</h3>
            <x-ui.button variant="ghost" size="sm" wire:click="forcePasswordReset" dusk="force-reset">Send password-reset email</x-ui.button>

            @php($sessions = $this->sessions())
            <div class="mt-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle mb-2">Recent sessions (IP / device)</p>
                @if ($sessions->isEmpty())
                    <p class="text-sm text-ink-subtle">No active sessions.</p>
                @else
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($sessions as $s)
                            <li class="py-2 flex items-center justify-between gap-3">
                                <span class="text-ink font-mono">{{ $s->ip_address ?? '—' }}</span>
                                <span class="text-ink-subtle truncate max-w-[60%]">{{ \Illuminate\Support\Str::limit((string) ($s->user_agent ?? ''), 60) ?: '—' }}</span>
                                <span class="text-ink-subtle whitespace-nowrap">{{ $s->last_activity ? \Illuminate\Support\Carbon::createFromTimestamp((int) $s->last_activity)->diffForHumans() : '' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </x-ui.card>
    @endif
</div>
