<?php
// SPDX-License-Identifier: Apache-2.0

use App\Groups\PrimaryGroupService;
use App\Models\Group;
use App\Models\User;
use App\Support\GroupColor;
use Livewire\Component;

/**
 * Settings → Primary group (ACP v3 · v3-e, ADR-0083). The signed-in member picks which of their current groups
 * appears as their rank badge and name colour. The selector is hidden (displays current choice as read-only) when
 * an admin has locked the primary — the lock message explains why they can't change it. Actions re-assert auth
 * on every call; no user-id wire property is accepted (own-account only, like every other settings SFC).
 */
new class extends Component
{
    /** The group id the user has chosen in the form (wire:model target). */
    public int $primaryGroupId = 0;

    public ?string $flash   = null;
    public ?string $error   = null;

    public function mount(): void
    {
        $user = $this->me();
        $primary = $user->primaryGroup();
        $this->primaryGroupId = $primary ? (int) $primary->getKey() : 0;
    }

    public function save(): void
    {
        $this->flash = null;
        $this->error = null;

        $user = $this->me();

        $group = Group::find($this->primaryGroupId);
        if (! $group instanceof Group) {
            $this->error = 'Please select a group.';
            return;
        }

        try {
            app(PrimaryGroupService::class)->setByUser($user, $group);
        } catch (\App\Admin\GroupException $e) {
            $this->error = $e->getMessage();
            return;
        }

        $this->flash = 'Primary group updated.';
    }

    /**
     * The groups this user belongs to, with color metadata, for the selector.
     *
     * @return \Illuminate\Support\Collection<int, array{id:int, name:string, cssVar:string|null}>
     */
    public function memberships(): \Illuminate\Support\Collection
    {
        return $this->me()->groups->map(fn (Group $g): array => [
            'id'     => (int) $g->getKey(),
            'name'   => $g->name,
            'cssVar' => GroupColor::cssVar($g->color),
        ]);
    }

    public function isLocked(): bool
    {
        return app(PrimaryGroupService::class)->isAdminLocked($this->me());
    }

    private function me(): User
    {
        $u = auth()->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }
};
?>

<div class="space-y-5" dusk="primary-group-chooser">
    @if ($flash)
        <x-ui.alert variant="success">{{ $flash }}</x-ui.alert>
    @endif

    @if ($error)
        <x-ui.alert variant="danger">{{ $error }}</x-ui.alert>
    @endif

    @if ($this->isLocked())
        {{-- Admin has overridden the user's choice — show the current primary as read-only. --}}
        <x-ui.card class="space-y-3">
            <p class="text-sm text-ink">
                An administrator has set your primary group. You cannot change it while this lock is in place.
            </p>
            @php $pg = auth()->user()?->primaryGroup() @endphp
            @if ($pg)
                <div class="flex items-center gap-2 text-sm font-medium" dusk="locked-primary">
                    @if ($cssVar = \App\Support\GroupColor::cssVar($pg->color))
                        <span class="inline-block w-2.5 h-2.5 rounded-full flex-shrink-0"
                              style="background-color: {{ $cssVar }};"></span>
                    @endif
                    <span>{{ $pg->name }}</span>
                </div>
            @endif
        </x-ui.card>
    @else
        <form wire:submit="save" class="space-y-5">
            <x-ui.card class="space-y-4">
                <p class="text-sm text-ink-muted">
                    Your primary group sets your rank badge, name colour, and the title shown under your avatar.
                    Only groups you currently belong to are listed.
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
                        <p class="text-sm text-ink-subtle">You don't belong to any groups yet.</p>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.button type="submit"
                         wire:loading.attr="disabled"
                         wire:target="save"
                         dusk="primary-group-save">
                <span wire:loading.remove wire:target="save">Save primary group</span>
                <span wire:loading wire:target="save">Saving…</span>
            </x-ui.button>
        </form>
    @endif
</div>
