<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\User;
use App\Permissions\Scope;
use App\Settings\Settings;
use Livewire\Component;

/**
 * Admin → Settings → Registration (ACP v1, PART 3.2). The two toggles that map to existing mechanisms:
 * registration on/off (enforced in CreateNewUser + the register view) and email-verification requirement
 * (drives email_verified_at at signup). The current anti-spam gates are shown READ-ONLY here; they are
 * edited on the Anti-spam page. Approval/invite registration modes are Phase 2 — not built (scope fence).
 */
new class extends Component
{
    public bool $enabled = true;

    public bool $requireEmailVerification = true;

    public ?string $saved = null;

    public function mount(Settings $settings): void
    {
        $this->ensureAdmin();
        $this->enabled = $settings->bool('registration.enabled');
        $this->requireEmailVerification = $settings->bool('registration.require_email_verification');
    }

    public function save(Settings $settings): void
    {
        $this->ensureAdmin();
        $data = $this->validate([
            'enabled' => ['boolean'],
            'requireEmailVerification' => ['boolean'],
        ]);

        $settings->set('registration.enabled', $data['enabled']);
        $settings->set('registration.require_email_verification', $data['requireEmailVerification']);
        $this->saved = 'Saved.';
    }

    /**
     * Read-only summary of the anti-spam registration gates (edited on the Anti-spam page). Resolves the
     * Settings store internally — Livewire only container-injects lifecycle hooks (mount/boot) and actions
     * reached via the update lifecycle, NOT a method called as `$this->gates()` straight from the rendered
     * view; a typed parameter here would arrive with zero arguments and fatal ("Too few arguments"). This
     * mirrors the sibling read-only display helper on the Anti-spam page ({@see blocklist()}).
     */
    public function gates(): array
    {
        $settings = app(Settings::class);

        return [
            'CAPTCHA' => ($p = $settings->string('antispam.captcha_provider')) !== '' ? strtoupper($p) : 'None',
            'StopForumSpam' => $settings->bool('antispam.sfs_use_api') ? 'Live API + cached blocklist' : 'Cached blocklist only',
            'Honeypot + timing trap' => (bool) config('hearth.antispam.registration.honeypot.required') ? 'Required' : 'Best-effort',
            'Per-IP rate limit' => (bool) config('hearth.antispam.registration.rate_limit.enabled')
                ? ((int) config('hearth.antispam.registration.rate_limit.per_ip_per_hour')).' / hour'
                : 'Off',
        ];
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('admin.access', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-6">
    <form wire:submit="save" class="space-y-5">
        @if ($saved)
            <x-ui.alert variant="success">{{ $saved }}</x-ui.alert>
        @endif

        <div class="space-y-3" id="setting-registration-enabled">
            <x-ui.toggle name="enabled" wire:model="enabled" :checked="$enabled" label="Allow new registrations" />
            <p class="text-xs text-ink-subtle">When off, the sign-up page shows a notice and any sign-up attempt is rejected.</p>
        </div>

        <div class="space-y-3 border-t border-line pt-5" id="setting-registration-require-email-verification">
            <x-ui.toggle name="requireEmailVerification" wire:model="requireEmailVerification" :checked="$requireEmailVerification"
                         label="Require email verification" />
            <p class="text-xs text-ink-subtle">New members must confirm their email before posting. Turn off to mark accounts verified at sign-up.</p>
        </div>

        <div>
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save changes</span>
                <span wire:loading wire:target="save">Saving…</span>
            </x-ui.button>
        </div>
    </form>

    <x-ui.card flush>
        <div class="flex items-center justify-between border-b border-line px-4 py-3 sm:px-5">
            <h2 class="text-sm font-semibold text-ink">Anti-spam gates (read-only)</h2>
            <a href="{{ route('admin.settings.antispam') }}" class="text-xs text-accent hover:text-accent-hover">Edit on Anti-spam</a>
        </div>
        <dl class="divide-y divide-line text-sm">
            @foreach ($this->gates() as $label => $value)
                <div class="grid grid-cols-1 gap-1 px-4 py-2.5 sm:grid-cols-2 sm:px-5">
                    <dt class="text-ink-muted">{{ $label }}</dt>
                    <dd class="text-ink">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </x-ui.card>

    <p class="text-xs text-ink-subtle">Approval-required and invite-only registration modes are planned for a later release.</p>
</div>
