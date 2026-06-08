<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\User;
use App\Permissions\Scope;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Admin → Settings → Anti-spam (ACP v1, PART 3.5). CAPTCHA provider + keys (the Turnstile secret stored
 * encrypted) and the StopForumSpam live-API toggle, all backing the existing CaptchaManager /
 * RegistrationGuard config. The crowdsourced blocklist size is shown read-only (it's cron-warmed).
 */
new class extends Component
{
    public string $captchaProvider = 'qa';

    public string $turnstileSiteKey = '';

    public string $turnstileSecret = ''; // never pre-filled — secret

    public bool $turnstileSecretSet = false;

    public bool $sfsUseApi = true;

    public ?string $saved = null;

    public function mount(Settings $settings): void
    {
        $this->ensureAdmin();
        $this->captchaProvider = $settings->string('antispam.captcha_provider') ?: 'qa';
        $this->turnstileSiteKey = $settings->string('antispam.turnstile_site_key');
        $this->turnstileSecretSet = $settings->secretIsSet('antispam.turnstile_secret');
        $this->sfsUseApi = $settings->bool('antispam.sfs_use_api');
    }

    public function save(Settings $settings): void
    {
        $this->ensureAdmin();
        $data = $this->validate([
            'captchaProvider' => ['required', 'in:qa,turnstile,none'],
            'turnstileSiteKey' => ['nullable', 'string', 'max:255'],
            'turnstileSecret' => ['nullable', 'string', 'max:255'],
            'sfsUseApi' => ['boolean'],
        ]);

        $settings->set('antispam.captcha_provider', $data['captchaProvider']);
        $settings->set('antispam.turnstile_site_key', $data['turnstileSiteKey'] ?? '');
        $settings->set('antispam.turnstile_secret', $data['turnstileSecret']); // blank ⇒ keep
        $settings->set('antispam.sfs_use_api', $data['sfsUseApi']);

        $this->turnstileSecret = '';
        $this->turnstileSecretSet = $settings->secretIsSet('antispam.turnstile_secret');
        $this->saved = 'Saved.';
    }

    /** Read-only crowdsourced blocklist stats (cron-warmed; never load-bearing). */
    public function blocklist(): array
    {
        try {
            return ['entries' => (int) DB::table('blocklist_cache')->count()];
        } catch (\Throwable) {
            return ['entries' => 0];
        }
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

        <div id="setting-antispam-captcha-provider">
            <x-ui.select label="CAPTCHA provider" name="captchaProvider" wire:model.live="captchaProvider"
                         hint="Q&A is the baseline (no external service). Turnstile degrades to Q&A if its keys are absent.">
                <option value="qa">Question &amp; answer</option>
                <option value="turnstile">Cloudflare Turnstile</option>
                <option value="none">None (not recommended)</option>
            </x-ui.select>
        </div>

        @if ($captchaProvider === 'turnstile')
            <fieldset class="grid gap-5 border-t border-line pt-5 sm:grid-cols-2">
                <legend class="sr-only">Turnstile keys</legend>
                <div id="setting-antispam-turnstile-site-key"><x-ui.input label="Turnstile site key" name="turnstileSiteKey" wire:model="turnstileSiteKey" /></div>
                <div id="setting-antispam-turnstile-secret">
                    <x-ui.input label="Turnstile secret key" name="turnstileSecret" type="password" wire:model="turnstileSecret" autocomplete="new-password"
                                :placeholder="$turnstileSecretSet ? '•••••• (leave blank to keep)' : ''"
                                hint="Stored encrypted." />
                </div>
            </fieldset>
        @endif

        <div class="space-y-3 border-t border-line pt-5" id="setting-antispam-sfs-use-api">
            <x-ui.toggle name="sfsUseApi" wire:model="sfsUseApi" :checked="$sfsUseApi" label="Use the StopForumSpam live API" />
            <p class="text-xs text-ink-subtle">When off (or unreachable), registration falls back to the cron-warmed cached blocklist — never blocking on the network.</p>
        </div>

        <div>
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save changes</span>
                <span wire:loading wire:target="save">Saving…</span>
            </x-ui.button>
        </div>
    </form>

    <x-ui.card>
        <h2 class="text-sm font-semibold text-ink">Crowdsourced blocklist</h2>
        <p class="mt-1 text-sm text-ink-muted">
            <span class="nums font-medium text-ink">{{ number_format($this->blocklist()['entries']) }}</span>
            cached entries (toxic domains / known spammers), refreshed automatically by the scheduler.
        </p>
    </x-ui.card>
</div>
