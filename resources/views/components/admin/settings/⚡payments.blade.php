<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\User;
use App\Permissions\Scope;
use App\Settings\Settings;
use Livewire\Component;

/**
 * Admin → Settings → Payments (Phase 4 · M5.3). Stripe HOSTED Checkout config. CHARGING IS DISABLED until an
 * admin enters the keys AND turns it on — both secrets stored ENCRYPTED. This build never charges; enabling
 * Stripe is a deliberate, documented owner step (the webhook secret signs inbound payment confirmations).
 */
new class extends Component
{
    public bool $enabled = false;

    public string $publishableKey = '';

    public string $secretKey = ''; // never pre-filled

    public bool $secretSet = false;

    public string $webhookSecret = ''; // never pre-filled

    public bool $webhookSecretSet = false;

    public ?string $saved = null;

    public function mount(Settings $settings): void
    {
        $this->ensureAdmin();
        $this->enabled = $settings->bool('payments.stripe.enabled');
        $this->publishableKey = $settings->string('payments.stripe.publishable_key');
        $this->secretSet = $settings->secretIsSet('payments.stripe.secret_key');
        $this->webhookSecretSet = $settings->secretIsSet('payments.stripe.webhook_secret');
    }

    public function save(Settings $settings): void
    {
        $this->ensureAdmin();
        $data = $this->validate([
            'enabled' => ['boolean'],
            'publishableKey' => ['nullable', 'string', 'max:255'],
            'secretKey' => ['nullable', 'string', 'max:255'],
            'webhookSecret' => ['nullable', 'string', 'max:255'],
        ]);

        $settings->set('payments.stripe.publishable_key', $data['publishableKey'] ?? '');
        $settings->set('payments.stripe.secret_key', $data['secretKey']);       // blank ⇒ keep
        $settings->set('payments.stripe.webhook_secret', $data['webhookSecret']); // blank ⇒ keep

        // Only allow enabling once a secret key is configured (no half-configured "live" state).
        $hasSecret = $settings->secretIsSet('payments.stripe.secret_key');
        $settings->set('payments.stripe.enabled', $data['enabled'] && $hasSecret);

        $this->enabled = $settings->bool('payments.stripe.enabled');
        $this->secretKey = $this->webhookSecret = '';
        $this->secretSet = $hasSecret;
        $this->webhookSecretSet = $settings->secretIsSet('payments.stripe.webhook_secret');
        $this->saved = $this->enabled
            ? 'Saved. Stripe is enabled — test a checkout before announcing it.'
            : 'Saved. Stripe is disabled (no charges can be initiated).';
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
    <x-ui.alert variant="warn">
        Charging is <strong>disabled by default</strong>. Stripe only activates when you enter a secret key and turn it on.
        Card data never touches this server — Stripe hosts the payment page.
    </x-ui.alert>

    <form wire:submit="save" class="space-y-5">
        @if ($saved)
            <x-ui.alert variant="success">{{ $saved }}</x-ui.alert>
        @endif

        <div id="setting-payments-stripe-enabled">
            <x-ui.toggle name="enabled" wire:model="enabled" :checked="$enabled" label="Enable Stripe payments" />
        </div>

        <div id="setting-payments-stripe-publishable-key">
            <x-ui.input label="Publishable key" name="publishableKey" wire:model="publishableKey" placeholder="pk_live_…" />
        </div>
        <div id="setting-payments-stripe-secret-key">
            <x-ui.input label="Secret key" name="secretKey" type="password" wire:model="secretKey" autocomplete="new-password"
                        :placeholder="$secretSet ? '•••••• (leave blank to keep)' : 'sk_live_…'" hint="Stored encrypted." />
        </div>
        <div id="setting-payments-stripe-webhook-secret">
            <x-ui.input label="Webhook signing secret" name="webhookSecret" type="password" wire:model="webhookSecret" autocomplete="new-password"
                        :placeholder="$webhookSecretSet ? '•••••• (leave blank to keep)' : 'whsec_…'"
                        hint="Signs the inbound checkout.session.completed event. Point your Stripe webhook at /webhooks/stripe." />
        </div>

        <x-ui.button type="submit">Save changes</x-ui.button>
    </form>
</div>
