<?php
// SPDX-License-Identifier: Apache-2.0
use App\Members\MemberActivationService;
use App\Models\Ban;
use App\Models\Post;
use App\Models\RegistrationCheck;
use App\Models\User;
use App\Moderation\OwnerStrandException;
use App\Moderation\UserBanService;
use App\Permissions\Scope;
use Livewire\Component;

/**
 * Admin → Members → Pending members (U14 / NOV-112, ADR-0119). The pending-member exit ramp: a review queue of
 * `status='pending'` (registration-flagged) accounts — the flag reason, join date, post + mod-approved counts —
 * with per-row Activate (lift the pending hold via MemberActivationService) and Reject (ban via the
 * UserBanService owner-strand chokepoint). Closes the "Dan" false-flag gap where a flagged member sits held
 * forever with no operator path to clear them. Authorization re-asserted in mount() AND every action; the
 * flag-reason / IP fields are PII, gated at the users.manage ceiling.
 */
new class extends Component
{
    public ?int $rejectId = null;

    public ?string $message = null;

    public string $messageVariant = 'info';

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    /** @return list<array{id:int,username:string,email:string,joined:string,reason:string,posts:int,approved:int}> */
    public function rows(): array
    {
        $this->ensureAdmin();

        $pending = User::query()->where('status', 'pending')->orderBy('created_at')->limit(200)->get();
        if ($pending->isEmpty()) {
            return [];
        }

        // Latest registration check per user (the flag reason lives in decision + provider_scores).
        $checks = RegistrationCheck::query()
            ->whereIn('user_id', $pending->pluck('id'))
            ->orderByDesc('id')->get()->keyBy('user_id');

        // Approved-post counts in one grouped query (the human-vouch signal).
        $approvedCounts = Post::query()->whereIn('user_id', $pending->pluck('id'))
            ->where('approved_state', 'approved')->groupBy('user_id')
            ->selectRaw('user_id, count(*) as c')->pluck('c', 'user_id');
        $totalCounts = Post::query()->whereIn('user_id', $pending->pluck('id'))
            ->groupBy('user_id')->selectRaw('user_id, count(*) as c')->pluck('c', 'user_id');

        return $pending->map(fn (User $u): array => [
            'id' => (int) $u->id,
            'username' => (string) $u->username,
            'email' => (string) $u->email,
            'joined' => $u->created_at?->diffForHumans() ?? '',
            'reason' => $this->flagReason($checks->get($u->id)),
            'posts' => (int) ($totalCounts[$u->id] ?? 0),
            'approved' => (int) ($approvedCounts[$u->id] ?? 0),
        ])->all();
    }

    public function activate(int $id, MemberActivationService $service): void
    {
        $this->ensureAdmin();
        $user = User::findOrFail($id);
        $activated = $service->activate($user, auth()->user(), 'manual');
        $this->flash($activated
            ? "Activated “{$user->username}”. Their content is no longer auto-held."
            : "Could not activate “{$user->username}” (already active, or the account is banned).",
            $activated ? 'success' : 'warn');
    }

    public function askReject(int $id): void
    {
        $this->ensureAdmin();
        $this->rejectId = $id;
        $this->message = null;
    }

    public function cancelReject(): void
    {
        $this->rejectId = null;
    }

    public function reject(UserBanService $bans): void
    {
        $this->ensureAdmin();
        if ($this->rejectId === null) {
            return;
        }
        $user = User::findOrFail($this->rejectId);
        abort_unless(\App\Support\ActorRank::canActOn(auth()->user(), $user), 403);
        try {
            $bans->ban($user, 'Rejected from the pending-member review queue.', null);
            $this->flash("Rejected + banned “{$user->username}”.", 'success');
        } catch (OwnerStrandException $e) {
            $this->flash($e->getMessage(), 'warn');
        }
        $this->rejectId = null;
    }

    /** A human flag reason from the account's registration check (decision + the raw provider signals). */
    private function flagReason(?RegistrationCheck $check): string
    {
        if (! $check instanceof RegistrationCheck) {
            return 'Flagged at registration (no check on record).';
        }
        $signals = [];
        foreach ((array) ($check->provider_scores ?? []) as $key => $val) {
            if ($val !== false && $val !== null && $val !== '') {
                $signals[] = (string) $key;
            }
        }
        $detail = $signals !== [] ? ' — '.implode(', ', array_slice($signals, 0, 5)) : '';

        return ucfirst((string) ($check->decision ?? 'flag')).$detail;
    }

    private function flash(string $message, string $variant = 'info'): void
    {
        $this->message = $message;
        $this->messageVariant = $variant;
    }

    public function canManage(): bool
    {
        $u = auth()->user();

        return $u instanceof User && $u->canDo('users.manage', Scope::global());
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('admin.access', Scope::global()), 403);
        abort_unless($u->canDo('admin.members.access', Scope::global()), 403);
        // The queue exposes PII (email + flag signals) — gate it at the users.manage ceiling.
        abort_unless($u->canDo('users.manage', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-5" dusk="acp-pending-members">
    @if ($message)
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    <p class="max-w-2xl text-sm text-ink-muted">
        Members flagged at registration sit in <strong>pending</strong> — every post they make is held for review
        and they can’t promote out of the new-user tier until you clear them. <strong>Activate</strong> lifts the
        hold (and promotes them if they’ve earned it); <strong>Reject</strong> bans the account.
    </p>

    <x-ui.card flush>
        <ul class="divide-y divide-line">
            @forelse ($this->rows() as $row)
                <li>
                    <div class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5 text-sm">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <a href="{{ route('admin.members.show', $row['id']) }}" class="font-medium text-ink hover:text-accent">{{ $row['username'] }}</a>
                                <span class="text-xs text-ink-subtle">{{ $row['email'] }}</span>
                            </div>
                            <p class="text-xs text-ink-subtle">
                                Joined {{ $row['joined'] }} · {{ $row['approved'] }}/{{ $row['posts'] }} posts approved · <span class="text-warn">{{ $row['reason'] }}</span>
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-1">
                            <x-ui.button type="button" size="sm" wire:click="activate({{ $row['id'] }})" dusk="acp-pending-activate-{{ $row['id'] }}">Activate</x-ui.button>
                            <x-ui.button type="button" variant="danger-ghost" size="sm" wire:click="askReject({{ $row['id'] }})" dusk="acp-pending-reject-{{ $row['id'] }}">Reject</x-ui.button>
                        </div>
                    </div>
                    @if ($rejectId === $row['id'])
                        <div class="border-t border-line bg-surface-sunken px-4 py-4 sm:px-5">
                            <x-ui.alert variant="warn" class="mb-3">Reject and ban “{{ $row['username'] }}”? They’ll be blocked from the site.</x-ui.alert>
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.button type="button" variant="danger" wire:click="reject" dusk="acp-pending-reject-confirm">Reject + ban</x-ui.button>
                                <x-ui.button type="button" variant="ghost" wire:click="cancelReject">Cancel</x-ui.button>
                            </div>
                        </div>
                    @endif
                </li>
            @empty
                <li class="px-4 py-6 sm:px-5 text-sm text-ink-subtle">No pending members — every flagged account has been cleared.</li>
            @endforelse
        </ul>
    </x-ui.card>
</div>
