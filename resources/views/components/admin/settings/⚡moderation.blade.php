<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\User;
use App\Permissions\Scope;
use App\Settings\Settings;
use Livewire\Component;

/**
 * Admin → Settings → Moderation defaults (ACP v1, PART 3.4). Knobs that back EXISTING mechanisms: the
 * new-user first-post hold count (NewUserModeration; 0 = auto-post; tracks HEARTH_NEW_USER_HOLD_POSTS
 * until set here), the suspicious-score hold threshold + links-per-post (LocalHeuristicsScanner), and the
 * per-trust flood limits (PostRateLimiter). An edit-time/grace window has no current mechanism, so it is
 * flagged here rather than invented (scope fence).
 */
new class extends Component
{
    public int $holdPosts = 2;

    public int $suspiciousScore = 2;

    public int $maxLinks = 3;

    public int $rateTl0 = 2;

    public int $rateTl1 = 8;

    public int $rateDefault = 20;

    public ?string $saved = null;

    public function mount(Settings $settings): void
    {
        $this->ensureAdmin();
        $this->holdPosts = $settings->int('moderation.new_user_hold_posts');
        $this->suspiciousScore = $settings->int('moderation.suspicious_score');
        $this->maxLinks = $settings->int('moderation.max_links');
        $this->rateTl0 = $settings->int('moderation.rate_tl0');
        $this->rateTl1 = $settings->int('moderation.rate_tl1');
        $this->rateDefault = $settings->int('moderation.rate_default');
    }

    public function save(Settings $settings): void
    {
        $this->ensureAdmin();
        $data = $this->validate([
            'holdPosts' => ['required', 'integer', 'min:0', 'max:50'],
            'suspiciousScore' => ['required', 'integer', 'min:0', 'max:20'],
            'maxLinks' => ['required', 'integer', 'min:0', 'max:50'],
            'rateTl0' => ['required', 'integer', 'min:0', 'max:1000'],
            'rateTl1' => ['required', 'integer', 'min:0', 'max:1000'],
            'rateDefault' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);

        $settings->set('moderation.new_user_hold_posts', $data['holdPosts']);
        $settings->set('moderation.suspicious_score', $data['suspiciousScore']);
        $settings->set('moderation.max_links', $data['maxLinks']);
        $settings->set('moderation.rate_tl0', $data['rateTl0']);
        $settings->set('moderation.rate_tl1', $data['rateTl1']);
        $settings->set('moderation.rate_default', $data['rateDefault']);
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

    <div id="setting-moderation-new-user-hold-posts">
        <x-ui.input label="New-user first-post hold count" name="holdPosts" type="number" wire:model="holdPosts" min="0" max="50"
                    hint="A new (TL0) member's first N posts are held for approval. 0 = auto-post (no hold)." />
    </div>

    <fieldset class="space-y-5 border-t border-line pt-5">
        <legend class="text-sm font-semibold text-ink">Content suspicion</legend>
        <div class="grid gap-5 sm:grid-cols-2">
            <div id="setting-moderation-suspicious-score">
                <x-ui.input label="Suspicious-score hold threshold" name="suspiciousScore" type="number" wire:model="suspiciousScore" min="0" max="20"
                            hint="A post scoring at/above this is held for moderation (never hard-blocked)." />
            </div>
            <div id="setting-moderation-max-links">
                <x-ui.input label="Links per post before suspicion" name="maxLinks" type="number" wire:model="maxLinks" min="0" max="50" />
            </div>
        </div>
    </fieldset>

    <fieldset class="space-y-5 border-t border-line pt-5">
        <legend class="text-sm font-semibold text-ink">Flood limits (posts per minute)</legend>
        <div class="grid gap-5 sm:grid-cols-3">
            <div id="setting-moderation-rate-tl0"><x-ui.input label="New users (TL0)" name="rateTl0" type="number" wire:model="rateTl0" min="0" /></div>
            <div id="setting-moderation-rate-tl1"><x-ui.input label="TL1" name="rateTl1" type="number" wire:model="rateTl1" min="0" /></div>
            <div id="setting-moderation-rate-default"><x-ui.input label="Established" name="rateDefault" type="number" wire:model="rateDefault" min="0" /></div>
        </div>
    </fieldset>

    <p class="text-xs text-ink-subtle">A post edit-time/grace window has no engine behind it yet — it’s noted for a future release rather than shown as a no-op control.</p>

    <div>
        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">Save changes</span>
            <span wire:loading wire:target="save">Saving…</span>
        </x-ui.button>
    </div>
</form>
