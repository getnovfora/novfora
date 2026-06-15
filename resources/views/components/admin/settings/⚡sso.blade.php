<?php

// SPDX-License-Identifier: Apache-2.0

use App\Auth\Social\SocialProviders;
use App\Models\User;
use App\Permissions\Scope;
use App\Settings\Settings;
use Livewire\Component;

/**
 * Admin → Settings → Social login (SSO) (Phase 4 · M2.1). Per-provider enable toggle + client id + client
 * secret (stored ENCRYPTED, never echoed — blank keeps the stored value). Every provider is OFF by default and
 * the password login path is untouched. Self-guards in mount() AND save() like every admin SFC.
 *
 * @property array<string,bool>   $enabled
 * @property array<string,string> $clientId
 * @property array<string,string> $clientSecret
 * @property array<string,bool>   $secretSet
 */
new class extends Component
{
    public array $enabled = [];

    public array $clientId = [];

    public array $clientSecret = []; // never pre-filled (secret placeholder semantics)

    public array $secretSet = [];

    public ?string $saved = null;

    public function mount(Settings $settings): void
    {
        $this->ensureAdmin();
        foreach (array_keys(SocialProviders::PROVIDERS) as $p) {
            $this->enabled[$p] = $settings->bool("oauth.{$p}.enabled");
            $this->clientId[$p] = $settings->string("oauth.{$p}.client_id");
            $this->clientSecret[$p] = '';
            $this->secretSet[$p] = $settings->secretIsSet("oauth.{$p}.client_secret");
        }
    }

    public function save(Settings $settings): void
    {
        $this->ensureAdmin();

        $rules = [];
        foreach (array_keys(SocialProviders::PROVIDERS) as $p) {
            $rules["enabled.{$p}"] = ['boolean'];
            $rules["clientId.{$p}"] = ['nullable', 'string', 'max:255'];
            $rules["clientSecret.{$p}"] = ['nullable', 'string', 'max:255'];
        }
        $this->validate($rules);

        foreach (array_keys(SocialProviders::PROVIDERS) as $p) {
            $settings->set("oauth.{$p}.enabled", (bool) ($this->enabled[$p] ?? false));
            $settings->set("oauth.{$p}.client_id", (string) ($this->clientId[$p] ?? ''));
            $settings->set("oauth.{$p}.client_secret", (string) ($this->clientSecret[$p] ?? '')); // blank ⇒ keep
            $this->clientSecret[$p] = '';
            $this->secretSet[$p] = $settings->secretIsSet("oauth.{$p}.client_secret");
        }

        $this->saved = 'Saved. Social login settings updated.';
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

    <p class="text-sm text-ink-muted">
        {{ __('Enable a provider, then paste the OAuth client id and secret from its developer console. Secrets are stored encrypted and never shown again. Password sign-in keeps working regardless.') }}
    </p>

    @foreach (\App\Auth\Social\SocialProviders::PROVIDERS as $key => $meta)
        <fieldset class="space-y-4 rounded-lg border border-line p-4" id="setting-oauth-{{ $key }}">
            <legend class="px-1 text-sm font-semibold text-ink">{{ $meta['label'] }}</legend>

            <label class="flex items-center gap-2.5 text-sm text-ink">
                <input type="checkbox" wire:model="enabled.{{ $key }}" dusk="oauth-{{ $key }}-enabled"
                       class="h-4 w-4 rounded-sm border-line text-accent focus:ring-accent">
                {{ __('Enable :provider sign-in', ['provider' => $meta['label']]) }}
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input :label="__('Client ID')" name="clientId_{{ $key }}" wire:model="clientId.{{ $key }}" autocomplete="off" />
                <x-ui.input :label="__('Client secret')" name="clientSecret_{{ $key }}" type="password"
                            wire:model="clientSecret.{{ $key }}" autocomplete="new-password"
                            :placeholder="($secretSet[$key] ?? false) ? '•••••• (leave blank to keep)' : ''"
                            :hint="__('Stored encrypted; never shown again.')" />
            </div>

            <p class="text-xs text-ink-subtle">
                {{ __('Authorised redirect URI:') }}
                <code class="break-all text-ink">{{ route('oauth.callback', $key) }}</code>
            </p>
        </fieldset>
    @endforeach

    <div>
        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">{{ __('Save changes') }}</span>
            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
        </x-ui.button>
    </div>
</form>
