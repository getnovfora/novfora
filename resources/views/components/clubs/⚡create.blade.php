<?php

// SPDX-License-Identifier: Apache-2.0

use App\Clubs\ClubCreation;
use App\Clubs\ClubService;
use App\Models\User;
use Livewire\Component;

/**
 * Create-a-club form (Phase 4 · M1.1). Re-asserts the creation policy in mount() AND save() — Livewire
 * actions reach the component through livewire/update, which carries no route middleware (security note from
 * the SFC convention). The founder becomes the club owner inside ClubService::create().
 */
new class extends Component
{
    public string $name = '';

    public string $tagline = '';

    public string $description = '';

    public string $privacy = 'public';

    public bool $is_listed = true;

    public string $color = '';

    public function mount(): void
    {
        $this->ensureCanCreate();
    }

    public function save(): void
    {
        $user = $this->ensureCanCreate();

        $data = $this->validate([
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'tagline' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'privacy' => ['required', 'in:'.implode(',', \App\Models\Club::PRIVACIES)],
            'is_listed' => ['boolean'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $club = app(ClubService::class)->create($user, $data);

        session()->flash('status', __('Club created.'));
        $this->redirectRoute('clubs.show', $club, navigate: true);
    }

    private function ensureCanCreate(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && app(ClubCreation::class)->canCreate($user), 403);

        return $user;
    }
};
?>

<form wire:submit="save" class="space-y-5">
    <x-ui.card>
        <div class="space-y-4">
            <x-ui.input name="name" :label="__('Club name')" wire:model="name"
                maxlength="100" required dusk="club-name" />

            <x-ui.input name="tagline" :label="__('Tagline (optional)')" wire:model="tagline"
                maxlength="120" :placeholder="__('A short one-line summary')" dusk="club-tagline" />

            <div>
                <label for="club-description" class="block text-sm font-medium text-ink">{{ __('Description (optional)') }}</label>
                <textarea id="club-description" wire:model="description" rows="4" maxlength="5000"
                    class="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink"
                    dusk="club-description"></textarea>
                @error('description') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="club-privacy" class="block text-sm font-medium text-ink">{{ __('Privacy') }}</label>
                <select id="club-privacy" wire:model.live="privacy"
                    class="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink" dusk="club-privacy">
                    <option value="public">{{ __('Public — anyone can read and join') }}</option>
                    <option value="closed">{{ __('Closed — anyone can request to join; content is members-only') }}</option>
                    <option value="private">{{ __('Private — invite-only; content is members-only') }}</option>
                </select>
            </div>

            @if ($privacy !== 'public')
                <label class="flex items-start gap-2 text-sm text-ink">
                    <input type="checkbox" wire:model="is_listed" class="mt-0.5 rounded border-line" dusk="club-listed">
                    <span>{{ __('List this club in the public directory (its name is shown, but content stays members-only). Uncheck to hide the club entirely from non-members.') }}</span>
                </label>
            @endif

            <x-ui.input type="color" name="color" :label="__('Accent colour (optional)')" wire:model="color" dusk="club-color" />
        </div>
    </x-ui.card>

    <div class="flex items-center gap-3">
        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save" dusk="club-create-submit">
            {{ __('Create club') }}
        </x-ui.button>
        <a href="{{ route('clubs.index') }}" class="text-sm text-ink-subtle hover:text-ink">{{ __('Cancel') }}</a>
    </div>
</form>
