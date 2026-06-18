<?php
// SPDX-License-Identifier: Apache-2.0

use App\Groups\PrimaryGroupService;
use App\Models\Group;
use App\Models\User;
use App\Permissions\Scope;
use App\Support\GroupColor;
use Livewire\Component;

/**
 * Admin → Members → (member) → Primary group (ACP v3 · v3-e, ADR-0083). An admin can:
 *   • set the member's primary group to any group they belong to, locking it so the member can't override it;
 *   • clear the lock to hand the choice back to the member (the current primary remains, just unlocked).
 *
 * Authorization is re-asserted in mount() AND in every action (same pattern as every other admin SFC, because
 * Livewire actions reach the component via livewire/update — no route middleware protects them).
 */
new class extends Component
{
    /** The id of the target member (passed from the wrapper view). */
    public int $userId = 0;

    /** The group id chosen by the admin in the form. */
    public int $primaryGroupId = 0;

    public bool $isLocked = false;

    public ?string $flash = null;

    public ?string $error = null;

    public function mount(int $userId): void
    {
        $this->ensureAdmin();
        $this->userId = $userId;
        $user = $this->target();
        $svc = app(PrimaryGroupService::class);
        $this->isLocked = $svc->isAdminLocked($user);
        $primary = $user->primaryGroup();
        $this->primaryGroupId = $primary ? (int) $primary->getKey() : 0;
    }

    public function save(): void
    {
        $this->ensureAdmin();
        $this->flash = null;
        $this->error = null;

        $group = Group::find($this->primaryGroupId);
        if (! $group instanceof Group) {
            $this->error = 'Please select a group.';
            return;
        }

        try {
            app(PrimaryGroupService::class)->setByAdmin($this->target(), $group, $this->actor());
        } catch (\App\Admin\GroupException $e) {
            $this->error = $e->getMessage();
            return;
        }

        $this->isLocked = true;
        $this->flash = 'Primary group set and locked.';
    }

    public function clearLock(): void
    {
        $this->ensureAdmin();
        $this->flash = null;
        $this->error = null;

        app(PrimaryGroupService::class)->clearLock($this->target(), $this->actor());

        $this->isLocked = false;
        $this->flash = 'Lock cleared — the member can now choose their own primary group.';
    }

    /**
     * The groups the target user belongs to, with color metadata, for the selector.
     *
     * @return \Illuminate\Support\Collection<int, array{id:int, name:string, cssVar:string|null}>
     */
    public function memberships(): \Illuminate\Support\Collection
    {
        return $this->target()->groups->map(fn (Group $g): array => [
            'id'     => (int) $g->getKey(),
            'name'   => $g->name,
            'cssVar' => GroupColor::cssVar($g->color),
        ]);
    }

    private function target(): User
    {
        $user = User::find($this->userId);
        abort_unless($user instanceof User, 404);

        return $user;
    }

    private function actor(): User
    {
        $u = auth()->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('admin.access', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-5" dusk="admin-primary-group">
    @if ($flash)
        <x-ui.alert variant="success">{{ $flash }}</x-ui.alert>
    @endif

    @if ($error)
        <x-ui.alert variant="danger">{{ $error }}</x-ui.alert>
    @endif

    @if ($isLocked)
        <x-ui.alert variant="info">
            The member's primary group is currently admin-locked. You can change it below or clear the lock to
            let them choose their own.
        </x-ui.alert>
    @endif

    <form wire:submit="save" class="space-y-5">
        <x-ui.card class="space-y-4">
            <p class="text-sm text-ink-muted">
                Setting a primary group here locks it — the member will not be able to change it until you
                clear the lock.
            </p>

            <div class="space-y-2" role="radiogroup" aria-label="Primary group">
                @foreach ($this->memberships() as $m)
                    <label class="flex items-center gap-3 cursor-pointer" dusk="group-option-{{ $m['id'] }}">
                        <input type="radio"
                               wire:model="primaryGroupId"
                               name="primaryGroupId"
                               value="{{ $m['id'] }}"
                               class="form-radio text-accent focus:ring-accent"
                               dusk="group-radio-{{ $m['id'] }}" />
                        @if ($m['cssVar'])
                            <span class="inline-block w-2.5 h-2.5 rounded-full flex-shrink-0"
                                  style="background-color: {{ $m['cssVar'] }};"></span>
                        @endif
                        <span class="text-sm text-ink">{{ $m['name'] }}</span>
                    </label>
                @endforeach

                @if ($this->memberships()->isEmpty())
                    <p class="text-sm text-ink-subtle">This member doesn't belong to any groups.</p>
                @endif
            </div>
        </x-ui.card>

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button type="submit"
                         wire:loading.attr="disabled"
                         wire:target="save"
                         dusk="admin-primary-group-save">
                <span wire:loading.remove wire:target="save">Set and lock primary group</span>
                <span wire:loading wire:target="save">Saving…</span>
            </x-ui.button>

            @if ($isLocked)
                <x-ui.button type="button"
                             variant="ghost"
                             wire:click="clearLock"
                             wire:loading.attr="disabled"
                             wire:target="clearLock"
                             dusk="admin-clear-lock">
                    <span wire:loading.remove wire:target="clearLock">Clear lock</span>
                    <span wire:loading wire:target="clearLock">Clearing…</span>
                </x-ui.button>
            @endif
        </div>
    </form>
</div>
