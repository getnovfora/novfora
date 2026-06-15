<?php

// SPDX-License-Identifier: Apache-2.0

use App\Models\User;
use App\Permissions\Scope;
use App\Settings\Settings;
use Livewire\Component;

/**
 * Admin → Settings → Clubs (Phase 4 · M1.6). Controls who may create a club (App\Clubs\ClubCreation).
 * Self-guards in mount() AND save(), like every admin SFC, because Livewire actions reach the component via
 * livewire/update with no route middleware.
 */
new class extends Component
{
    public string $policy = 'trust';

    public int $minTrustLevel = 2;

    public ?string $saved = null;

    public function mount(Settings $settings): void
    {
        $this->ensureAdmin();
        $this->policy = $settings->string('clubs.creation_policy') ?: 'trust';
        $this->minTrustLevel = (int) $settings->int('clubs.creation_min_trust_level');
    }

    public function save(Settings $settings): void
    {
        $this->ensureAdmin();
        $data = $this->validate([
            'policy' => ['required', 'in:any,trust,staff'],
            'minTrustLevel' => ['required', 'integer', 'min:0', 'max:4'],
        ]);

        $settings->set('clubs.creation_policy', $data['policy']);
        $settings->set('clubs.creation_min_trust_level', (string) $data['minTrustLevel']);
        $this->saved = 'Saved. Club-creation permission updated.';
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

    <div id="setting-clubs-creation-policy" class="max-w-md">
        <x-ui.select label="Who can create clubs" name="policy" wire:model.live="policy"
                     hint="Administrators and moderators can always create clubs.">
            <option value="any">Any verified member</option>
            <option value="trust">Members at a trust level (and above)</option>
            <option value="staff">Administrators &amp; moderators only</option>
        </x-ui.select>
    </div>

    @if ($policy === 'trust')
        <div id="setting-clubs-min-trust" class="max-w-xs">
            <x-ui.input type="number" name="minTrustLevel" :label="__('Minimum trust level')" wire:model="minTrustLevel"
                        min="0" max="4" hint="0 = new accounts; 2 = established members (recommended)." />
        </div>
    @endif

    <div>
        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">Save changes</span>
            <span wire:loading wire:target="save">Saving…</span>
        </x-ui.button>
    </div>
</form>
