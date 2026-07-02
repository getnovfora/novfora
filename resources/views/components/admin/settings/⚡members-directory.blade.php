<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\User;
use App\Permissions\Scope;
use App\Settings\Settings;
use Livewire\Component;

/**
 * Admin → Members → Directory. Gates the public /members listing (App\Community\MembersDirectory) and
 * holds the Gravatar opt-in (U18, ADR-0107 — a privacy fence, default OFF, read by <x-ui.avatar>).
 * Self-guards in mount() AND save(), like every admin SFC, because Livewire actions reach the component
 * via livewire/update with no route middleware.
 */
new class extends Component
{
    public string $visibility = 'everyone';

    public bool $gravatarEnabled = false;

    public ?string $saved = null;

    public function mount(Settings $settings): void
    {
        $this->ensureAdmin();
        $this->visibility = $settings->string('members.directory_visibility') ?: 'everyone';
        $this->gravatarEnabled = $settings->bool('members.gravatar_enabled');
    }

    public function save(Settings $settings): void
    {
        $this->ensureAdmin();
        $data = $this->validate([
            'visibility' => ['required', 'in:disabled,staff,members,everyone'],
            'gravatarEnabled' => ['boolean'],
        ]);
        $settings->set('members.directory_visibility', $data['visibility']);
        $settings->set('members.gravatar_enabled', $data['gravatarEnabled']);
        $this->saved = 'Saved.';
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

    {{-- Gravatar opt-in (U18, ADR-0107) — a privacy fence, so it carries the amber callout. --}}
    <div class="space-y-2 rounded-lg border border-amber-300/40 bg-amber-50/40 p-4" id="setting-members-gravatar-enabled">
        <x-ui.toggle name="gravatarEnabled" wire:model="gravatarEnabled" :checked="$gravatarEnabled"
                     label="Use Gravatar for members without an uploaded avatar" />
        <p class="text-xs text-ink-muted">
            <strong>Privacy:</strong> off by default. When on, each member's browser fetches their avatar from
            gravatar.com using a hash of the member's email address — so that hash is sent to gravatar.com by the
            browser only while this is enabled. This server never contacts gravatar.com, and an uploaded avatar
            always takes priority.
        </p>
    </div>

    <div>
        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">Save changes</span>
            <span wire:loading wire:target="save">Saving…</span>
        </x-ui.button>
    </div>
</form>
