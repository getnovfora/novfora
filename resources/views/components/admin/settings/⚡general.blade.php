<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\User;
use App\Permissions\Scope;
use App\Settings\Settings;
use Livewire\Component;

/**
 * Admin → Settings → General (ACP v1, PART 3.1). Site name + description (DB-backed, env/config fallback),
 * a site-wide notice banner, and the board-offline switch + message. Writes go through the Settings
 * service (audited, cache-invalidating); self-guards like the other admin SFCs.
 */
new class extends Component
{
    public string $siteName = '';

    public string $siteDescription = '';

    public string $siteNotice = '';

    public bool $boardOffline = false;

    public string $boardOfflineMessage = '';

    public int $activityFeedLimit = 15;

    public ?string $saved = null;

    public function mount(Settings $settings): void
    {
        $this->ensureAdmin();
        $this->siteName = $settings->string('general.site_name');
        $this->siteDescription = $settings->string('general.site_description');
        $this->siteNotice = $settings->string('general.site_notice');
        $this->boardOffline = $settings->bool('general.board_offline');
        $this->boardOfflineMessage = $settings->string('general.board_offline_message');
        $this->activityFeedLimit = $settings->int('general.activity_feed_limit');
    }

    public function save(Settings $settings): void
    {
        $this->ensureAdmin();
        $data = $this->validate([
            'siteName' => ['required', 'string', 'max:80'],
            'siteDescription' => ['nullable', 'string', 'max:300'],
            'siteNotice' => ['nullable', 'string', 'max:500'],
            'boardOffline' => ['boolean'],
            'boardOfflineMessage' => ['nullable', 'string', 'max:500'],
            'activityFeedLimit' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        $settings->set('general.site_name', $data['siteName']);
        $settings->set('general.site_description', $data['siteDescription'] ?? '');
        $settings->set('general.site_notice', $data['siteNotice'] ?? '');
        $settings->set('general.board_offline', $data['boardOffline']);
        $settings->set('general.board_offline_message', $data['boardOfflineMessage'] ?? '');
        $settings->set('general.activity_feed_limit', $data['activityFeedLimit']);

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

<form wire:submit="save" class="space-y-6">
    @if ($saved)
        <x-ui.alert variant="success">{{ $saved }}</x-ui.alert>
    @endif

    <div id="setting-general-site-name">
        <x-ui.input label="Site name" name="siteName" wire:model="siteName" required maxlength="80"
                    hint="Shown in the page title and as the default brand. Overrides APP_NAME." />
    </div>
    <div id="setting-general-site-description">
        <x-ui.textarea label="Site description / tagline" name="siteDescription" wire:model="siteDescription" rows="2"
                       hint="Used as the default meta description." />
    </div>
    <div id="setting-general-site-notice">
        <x-ui.textarea label="Site-wide notice" name="siteNotice" wire:model="siteNotice" rows="2"
                       hint="Shown as a banner on every page when set. Leave blank for none." />
    </div>

    <div class="border-t border-line pt-5 space-y-3" id="setting-general-board-offline">
        <x-ui.toggle name="boardOffline" wire:model.live="boardOffline" :checked="$boardOffline"
                     label="Take the board offline for visitors" />
        <p class="text-xs text-ink-subtle">Guests and members see a maintenance notice; admins keep full access.</p>
        @if ($boardOffline)
            <div id="setting-general-board-offline-message">
                <x-ui.textarea label="Offline message" name="boardOfflineMessage" wire:model="boardOfflineMessage" rows="2" />
            </div>
        @endif
    </div>

    <div class="border-t border-line pt-5" id="setting-general-activity-feed-limit">
        <x-ui.input label="Recent activity items on the homepage" name="activityFeedLimit" type="number"
                    wire:model="activityFeedLimit" min="1" max="50" required
                    hint="How many recent-activity entries the homepage feed shows (1–50)." />
    </div>

    <div>
        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">Save changes</span>
            <span wire:loading wire:target="save">Saving…</span>
        </x-ui.button>
    </div>
</form>
