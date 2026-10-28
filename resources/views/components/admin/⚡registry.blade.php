<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\User;
use App\Permissions\Scope;
use App\Registry\RegistryClient;
use App\Registry\RegistryException;
use Livewire\Component;

/**
 * Admin → Plugins → Registry (Registry v1, NOV-125, ADR-0113). Browse the signed feed and one-click-install a
 * listed theme/plugin. All verification (feed signature, sequence, content hash, publisher status, downgrade)
 * lives in RegistryClient — this SFC only presents and dispatches, re-asserting admin + staff-2FA in mount()
 * AND every action (Livewire actions bypass route middleware).
 */
new class extends Component
{
    public ?string $message = null;

    public string $messageVariant = 'info';

    public ?string $feedError = null;

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    /** @return array{configured:bool, stale:bool, packages:list<array<string,mixed>>, alerts:list<array<string,mixed>>} */
    public function data(RegistryClient $registry): array
    {
        $this->ensureAdmin();
        $out = ['configured' => $registry->isConfigured(), 'stale' => false, 'packages' => [], 'alerts' => []];
        if (! $out['configured']) {
            return $out;
        }
        try {
            $feed = $registry->feed();
            $out['stale'] = (bool) $feed['stale'];
            $out['packages'] = array_values($feed['packages']);
            $out['alerts'] = $registry->revocationAlerts();
            $this->feedError = null;
        } catch (RegistryException $e) {
            $this->feedError = $e->getMessage();
        }

        return $out;
    }

    public function checkForUpdates(RegistryClient $registry): void
    {
        $this->ensureAdmin();
        try {
            $registry->refresh();
            $this->flash('Checked the registry.', 'success');
        } catch (RegistryException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    public function install(string $slug, RegistryClient $registry): void
    {
        $this->ensureAdmin();
        try {
            $record = $registry->install($slug);
            $this->flash("Installed “{$record->slug}” ({$record->version}).", 'success');
        } catch (RegistryException $e) {
            $this->flash('Could not install: '.$e->getMessage(), 'danger');
        }
    }

    private function flash(string $message, string $variant = 'info'): void
    {
        $this->message = $message;
        $this->messageVariant = $variant;
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('admin.access', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-5" dusk="acp-registry">
    @php($d = $this->data(app(\App\Registry\RegistryClient::class)))

    @if ($message)
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="max-w-2xl text-sm text-ink-muted">
            Browse and install signed themes &amp; plugins from the NovFora Registry. Every package is verified
            against the registry’s signature and its published checksum before it installs.
        </p>
        @if ($d['configured'])
            <x-ui.button type="button" size="sm" variant="subtle" wire:click="checkForUpdates" dusk="acp-registry-check">Check for updates</x-ui.button>
        @endif
    </div>

    @unless ($d['configured'])
        <x-ui.alert variant="info">The registry is not configured on this install (a feed URL and pinned root key are required).</x-ui.alert>
    @endunless

    @if ($feedError)
        <x-ui.alert variant="warn" dusk="acp-registry-error">{{ $feedError }}</x-ui.alert>
    @endif

    @if ($d['stale'])
        <x-ui.alert variant="warn">The registry feed hasn’t been updated in a while — a mirror may be stale.</x-ui.alert>
    @endif

    @foreach ($d['alerts'] as $alert)
        <x-ui.alert variant="danger" dusk="acp-registry-revoked">
            <strong>Security alert:</strong> the publisher of installed {{ $alert['type'] }} “{{ $alert['slug'] }}”
            ({{ $alert['version'] }}) has been <strong>revoked</strong>. {{ $alert['reason'] }} Consider disabling it.
        </x-ui.alert>
    @endforeach

    @if ($d['configured'] && ! $feedError)
        <x-ui.card flush>
            <ul class="divide-y divide-line">
                @forelse ($d['packages'] as $pkg)
                    <li class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5 text-sm">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-ink">{{ $pkg['title'] ?? $pkg['slug'] }}</span>
                                <x-ui.badge variant="neutral">{{ $pkg['type'] ?? '?' }}</x-ui.badge>
                                <span class="text-xs text-ink-subtle">{{ $pkg['latest'] ?? '' }}</span>
                            </div>
                            <p class="truncate text-xs text-ink-subtle">{{ $pkg['description'] ?? '' }} <span class="font-mono">{{ $pkg['slug'] }}</span></p>
                        </div>
                        <x-ui.button type="button" size="sm" wire:click="install('{{ $pkg['slug'] }}')" dusk="acp-registry-install-{{ \Illuminate\Support\Str::slug($pkg['slug']) }}">Install</x-ui.button>
                    </li>
                @empty
                    <li class="px-4 py-6 sm:px-5 text-sm text-ink-subtle">The registry feed lists no packages yet.</li>
                @endforelse
            </ul>
        </x-ui.card>
    @endif
</div>
