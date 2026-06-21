<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\User;
use App\Permissions\Scope;
use App\Settings\Settings;
use Livewire\Component;

/**
 * Admin → Members → Staff flair (ACP v3 · v3-g). Two DISPLAY-ONLY toggles: members.staff_flair_show_badge (the
 * master switch for the live, group-derived staff role badge shown across the UI) + members.staff_roster_enabled
 * (publishes the public /staff "The Team" page). Self-guards in mount() AND save() like every admin SFC, because
 * a Livewire action reaches the component via livewire/update with no route middleware. Never touches acl_entries.
 */
new class extends Component
{
    public bool $showBadge = true;

    public bool $rosterEnabled = false;

    public ?string $saved = null;

    public function mount(Settings $settings): void
    {
        $this->ensureAdmin();
        $this->showBadge = $settings->bool('members.staff_flair_show_badge');
        $this->rosterEnabled = $settings->bool('members.staff_roster_enabled');
    }

    public function save(Settings $settings): void
    {
        $this->ensureAdmin();
        $settings->set('members.staff_flair_show_badge', $this->showBadge);
        $settings->set('members.staff_roster_enabled', $this->rosterEnabled);
        $this->saved = 'Saved. Staff flair settings have been updated.';
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('admin.access', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<form wire:submit="save" class="space-y-6">
    @if ($saved)
        <x-ui.alert variant="success">{{ $saved }}</x-ui.alert>
    @endif

    <div id="setting-members-staff-flair-show-badge" class="space-y-1.5">
        <x-ui.toggle name="showBadge" wire:model="showBadge" :checked="$showBadge" label="Show staff role badges" />
        <p class="text-sm text-ink-subtle">Shows a live role marker (Co-owner / Administrator / Moderator / Forum
            moderator) on posts, profiles, and the members directory. Derived from a member's groups — display only.</p>
    </div>

    <div id="setting-members-staff-roster-enabled" class="space-y-1.5">
        <x-ui.toggle name="rosterEnabled" wire:model="rosterEnabled" :checked="$rosterEnabled" label="Publish the public Team page" />
        <p class="text-sm text-ink-subtle">Publishes a public <code class="text-ink-muted">/staff</code> page listing
            your staff, grouped by role. Off by default; the page returns 404 while unpublished.</p>
    </div>

    <div>
        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">Save changes</span>
            <span wire:loading wire:target="save">Saving…</span>
        </x-ui.button>
    </div>
</form>
