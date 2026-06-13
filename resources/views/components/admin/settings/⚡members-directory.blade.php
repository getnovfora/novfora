<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\User;
use App\Permissions\Scope;
use App\Settings\Settings;
use Livewire\Component;

/**
 * Admin → Members → Directory. The single control that gates the public /members listing
 * (App\Community\MembersDirectory). Self-guards in mount() AND save(), like every admin SFC, because
 * Livewire actions reach the component via livewire/update with no route middleware.
 */
new class extends Component
{
    public string $visibility = 'everyone';

    public ?string $saved = null;

    public function mount(Settings $settings): void
    {
        $this->ensureAdmin();
        $this->visibility = $settings->string('members.directory_visibility') ?: 'everyone';
    }

    public function save(Settings $settings): void
    {
        $this->ensureAdmin();
        $data = $this->validate([
            'visibility' => ['required', 'in:disabled,staff,members,everyone'],
        ]);
        $settings->set('members.directory_visibility', $data['visibility']);
        $this->saved = 'Saved. The members directory visibility has been updated.';
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('admin.access', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<form wire:submit="save" class="space-y-5">
    @if ($saved)
        <x-ui.alert variant="success">{{ $saved }}</x-ui.alert>
    @endif

    <div id="setting-members-directory-visibility" class="max-w-md">
        <x-ui.select label="Who can view the members directory" name="visibility" wire:model="visibility"
                     hint="Gates the public /members page. Members keep their individual profile privacy regardless.">
            <option value="everyone">Everyone (including guests)</option>
            <option value="members">Signed-in members only</option>
            <option value="staff">Staff only</option>
            <option value="disabled">Disabled (no one)</option>
        </x-ui.select>
    </div>

    <div>
        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">Save changes</span>
            <span wire:loading wire:target="save">Saving…</span>
        </x-ui.button>
    </div>
</form>
