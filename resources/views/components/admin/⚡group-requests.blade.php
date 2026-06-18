<?php
// SPDX-License-Identifier: Apache-2.0
use App\Admin\GroupException;
use App\Groups\GroupMembershipService;
use App\Models\GroupJoinRequest;
use App\Models\User;
use App\Permissions\Scope;
use App\Support\GroupColor;
use Livewire\Component;

/**
 * Admin → Groups → Join requests (ACP v3 · v3-e). The approval queue for pending
 * GroupJoinRequests — admins can approve or deny each request. Like every admin SFC
 * the authorization is re-asserted in mount() AND every action.
 */
new class extends Component
{
    public ?string $message = null;

    public string $messageVariant = 'info';

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    // Arg-first, service-second — the proven Livewire action-injection order.
    public function approve(int $id, GroupMembershipService $svc): void
    {
        $this->ensureAdmin();
        $request = GroupJoinRequest::findOrFail($id);
        try {
            $svc->approve($request, auth()->user());
            $this->flash('Approved.', 'success');
        } catch (GroupException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    public function deny(int $id, GroupMembershipService $svc): void
    {
        $this->ensureAdmin();
        $request = GroupJoinRequest::findOrFail($id);
        try {
            $svc->deny($request, auth()->user());
            $this->flash('Denied.', 'success');
        } catch (GroupException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    /** @return list<GroupJoinRequest> */
    public function rows(): array
    {
        $this->ensureAdmin();

        return GroupJoinRequest::with(['user', 'group'])->pending()->latest()->get()->all();
    }

    private function flash(string $message, string $variant = 'info'): void
    {
        $this->message = $message;
        $this->messageVariant = $variant;
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canDo('admin.access', Scope::global()), 403);
        abort_if($user->isStaff() && $user->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-5" dusk="acp-group-requests">
    @if ($message)
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    <p class="text-sm text-ink-muted max-w-2xl">
        Members who have requested to join a <strong>request-model</strong> group are listed here.
        Approve to seat them as members; deny to decline without adding membership.
    </p>

    @php($rows = $this->rows())

    @if (empty($rows))
        <x-ui.card>
            <p class="py-6 text-center text-sm text-ink-subtle">No pending join requests.</p>
        </x-ui.card>
    @else
        <x-ui.card flush>
            <div class="hidden sm:grid grid-cols-[1fr_10rem_8rem_10rem] gap-3 px-4 py-2.5 sm:px-5 border-b border-line bg-surface-sunken text-xs font-semibold uppercase tracking-wide text-ink-subtle">
                <span>User</span>
                <span>Group</span>
                <span>Requested</span>
                <span class="text-right">Actions</span>
            </div>
            <ul class="divide-y divide-line">
                @foreach ($rows as $r)
                    @php($gc = GroupColor::cssVar($r->group->color))
                    <li>
                        <div class="grid grid-cols-1 gap-2 px-4 py-3 sm:grid-cols-[1fr_10rem_8rem_10rem] sm:items-center sm:gap-3 sm:px-5 text-sm">
                            <div class="min-w-0 truncate">
                                <x-ui.user-name :user="$r->user" />
                            </div>
                            <div class="min-w-0 truncate font-medium" @if ($gc) style="color: {{ $gc }};" @endif>
                                {{ $r->group->name }}
                            </div>
                            <div class="text-ink-muted text-xs">
                                {{ $r->created_at->diffForHumans() }}
                            </div>
                            <div class="flex flex-wrap items-center gap-1 sm:justify-end">
                                <x-ui.button type="button" size="sm" wire:click="approve({{ $r->id }})" dusk="approve-{{ $r->id }}">
                                    Approve
                                </x-ui.button>
                                <x-ui.button type="button" size="sm" variant="danger-ghost" wire:click="deny({{ $r->id }})" dusk="deny-{{ $r->id }}">
                                    Deny
                                </x-ui.button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
