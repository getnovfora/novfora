<?php

// SPDX-License-Identifier: Apache-2.0

use App\Clubs\ClubService;
use App\Models\Club;
use App\Models\User;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Edit / delete a club (Phase 4 · M1.1). Owner-or-staff only, re-asserted in mount() AND every action. The
 * club model is #[Locked] so the client cannot swap which club is edited; the gate re-checks regardless.
 * Slug is immutable (stable URLs). Delete is a two-step armed confirmation (soft delete).
 */
new class extends Component
{
    #[Locked]
    public Club $club;

    public string $name = '';

    public string $tagline = '';

    public string $description = '';

    public string $privacy = 'public';

    public bool $is_listed = true;

    public string $color = '';

    public bool $confirmingDelete = false;

    public function mount(Club $club): void
    {
        $this->club = $club;
        $this->ensureCanManage();

        $this->name = (string) $club->name;
        $this->tagline = (string) ($club->tagline ?? '');
        $this->description = (string) ($club->description ?? '');
        $this->privacy = (string) $club->privacy;
        $this->is_listed = (bool) $club->is_listed;
        $this->color = (string) ($club->color ?? '');
    }

    public function save(): void
    {
        $this->ensureCanManage();

        $data = $this->validate([
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'tagline' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'privacy' => ['required', 'in:'.implode(',', Club::PRIVACIES)],
            'is_listed' => ['boolean'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        app(ClubService::class)->update($this->club, $data);

        session()->flash('status', __('Club updated.'));
        $this->redirectRoute('clubs.show', $this->club, navigate: true);
    }

    public function deleteClub(): void
    {
        $this->ensureCanManage();

        if (! $this->confirmingDelete) {
            $this->confirmingDelete = true;

            return;
        }

        app(ClubService::class)->delete($this->club);

        session()->flash('status', __('Club deleted.'));
        $this->redirectRoute('clubs.index', navigate: true);
    }

    private function ensureCanManage(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $this->club->isManageableBy($user), 403);

        return $user;
    }
};
?>

<div class="space-y-5">
    <form wire:submit="save" class="space-y-5">
        <x-ui.card>
            <div class="space-y-4">
                <x-ui.input name="name" :label="__('Club name')" wire:model="name" maxlength="100" required dusk="club-name" />
                <x-ui.input name="tagline" :label="__('Tagline (optional)')" wire:model="tagline" maxlength="120" dusk="club-tagline" />

                <div>
                    <label for="club-description" class="block text-sm font-medium text-ink">{{ __('Description (optional)') }}</label>
                    <textarea id="club-description" wire:model="description" rows="4" maxlength="5000"
                        class="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink" dusk="club-description"></textarea>
                    @error('description') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="club-privacy" class="block text-sm font-medium text-ink">{{ __('Privacy') }}</label>
                    <select id="club-privacy" wire:model.live="privacy"
                        class="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink" dusk="club-privacy">
                        <option value="public">{{ __('Public — anyone can read and join') }}</option>
                        <option value="closed">{{ __('Closed — request to join; content members-only') }}</option>
                        <option value="private">{{ __('Private — invite-only; content members-only') }}</option>
                    </select>
                </div>

                @if ($privacy !== 'public')
                    <label class="flex items-start gap-2 text-sm text-ink">
                        <input type="checkbox" wire:model="is_listed" class="mt-0.5 rounded border-line" dusk="club-listed">
                        <span>{{ __('List this club in the public directory (name shown; content stays members-only).') }}</span>
                    </label>
                @endif

                <x-ui.input type="color" name="color" :label="__('Accent colour (optional)')" wire:model="color" dusk="club-color" />
            </div>
        </x-ui.card>

        <div class="flex items-center gap-3">
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save" dusk="club-edit-submit">
                {{ __('Save changes') }}
            </x-ui.button>
            <a href="{{ route('clubs.show', $club) }}" class="text-sm text-ink-subtle hover:text-ink">{{ __('Cancel') }}</a>
        </div>
    </form>

    <x-ui.card>
        <div class="space-y-3">
            <h3 class="text-base font-semibold text-ink">{{ __('Delete club') }}</h3>
            <p class="text-sm text-ink-subtle">{{ __('Deleting a club hides it and its discussion. This can be reversed by an administrator from the recycle bin.') }}</p>
            @if ($confirmingDelete)
                <div class="flex flex-wrap items-center gap-3 rounded-lg border border-danger/40 bg-danger/5 p-3">
                    <span class="text-sm font-medium text-ink">{{ __('Are you sure?') }}</span>
                    <x-ui.button type="button" variant="danger" wire:click="deleteClub" dusk="club-delete-confirm">{{ __('Yes, delete this club') }}</x-ui.button>
                    <x-ui.button type="button" variant="subtle" wire:click="$set('confirmingDelete', false)">{{ __('Cancel') }}</x-ui.button>
                </div>
            @else
                <x-ui.button type="button" variant="danger" wire:click="deleteClub" dusk="club-delete">{{ __('Delete club…') }}</x-ui.button>
            @endif
        </div>
    </x-ui.card>
</div>
