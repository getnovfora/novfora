<?php

// SPDX-License-Identifier: Apache-2.0

use App\Models\ModuleTrustKey;
use App\Models\User;
use App\Modules\ModuleTrustKeys;
use App\Modules\Packaging\ModuleInstaller;
use App\Modules\Packaging\PackageException;
use App\Permissions\Scope;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The ACP "Install from zip + trust keys" panel (U17, ADR-0104, apex). Admins upload a signed module .zip
 * (verified against the trusted-key registry, hardened against hostile archives) and manage the ed25519
 * public keys packages are trusted against. Admins-only (admin.access + staff-2FA), re-asserted in mount()
 * AND every action (a livewire/update carries no route middleware); the archive pipeline + key registry are
 * both audited. Installing a module is the highest-privilege act — the file arrives untrusted and only the
 * ModuleInstaller gates decide whether it ever reaches modules/.
 */
new class extends Component
{
    use WithFileUploads;

    public $archive = null;

    public bool $confirmUpgrade = false;

    public string $keyName = '';

    public string $publicKey = '';

    public ?string $error = null;

    public ?string $status = null;

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function install(): void
    {
        $this->ensureAdmin();
        $this->reset('error', 'status');

        $this->validate([
            // The zip is the compressed upload; the UNCOMPRESSED caps live in ArchiveGuard. 64 MiB ceiling here.
            'archive' => 'required|file|max:65536',
        ]);

        $path = $this->archive?->getRealPath();
        if (! is_string($path) || strtolower((string) $this->archive->getClientOriginalExtension()) !== 'zip') {
            $this->error = __('Upload a .zip package.');

            return;
        }

        try {
            $result = app(ModuleInstaller::class)->installFromZip($path, $this->confirmUpgrade);
            $this->reset('archive', 'confirmUpgrade');
            $this->status = __("Installed ':slug' (:trust). It is disabled — enable it from the list above after reviewing its capabilities.", [
                'slug' => $result['slug'],
                'trust' => $result['trust'] === 'signed' ? __('signature verified') : __('UNSIGNED — dev policy'),
            ]);
        } catch (PackageException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function addKey(): void
    {
        $this->ensureAdmin();
        $this->reset('error', 'status');
        try {
            app(ModuleTrustKeys::class)->add($this->keyName, $this->publicKey);
            $this->reset('keyName', 'publicKey');
            $this->status = __('Trusted key added.');
        } catch (PackageException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function toggleKey(int $id): void
    {
        $this->ensureAdmin();
        $key = ModuleTrustKey::findOrFail($id);
        app(ModuleTrustKeys::class)->setEnabled($key, ! $key->is_enabled);
    }

    public function removeKey(int $id): void
    {
        $this->ensureAdmin();
        app(ModuleTrustKeys::class)->remove(ModuleTrustKey::findOrFail($id));
        $this->status = __('Trusted key removed.');
    }

    public function allowUnsigned(): bool
    {
        return (bool) config('novfora.modules.zip.allow_unsigned', false);
    }

    /** @return Collection<int,ModuleTrustKey> */
    public function keys(): Collection
    {
        $this->ensureAdmin();

        return app(ModuleTrustKeys::class)->all();
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canDo('admin.access', Scope::global()), 403);
        abort_if($user->isStaff() && $user->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-5" dusk="admin-module-install">
    @if ($status) <x-ui.alert variant="success" dusk="install-status">{{ $status }}</x-ui.alert> @endif
    @if ($error) <x-ui.alert variant="danger" dusk="install-error">{{ $error }}</x-ui.alert> @endif

    <x-ui.card class="space-y-4">
        <div class="space-y-1">
            <h2 class="text-base font-semibold text-ink">{{ __('Install a plugin from a zip') }}</h2>
            <p class="text-sm text-ink-subtle">
                {{ __('Upload a packaged module (.zip with a module.json at its root). The archive is hardened against path traversal, symlink escape, and zip bombs, then its ed25519 signature is verified against your trusted keys before anything is written. It installs DISABLED — you still confirm full-trust when you enable it.') }}
            </p>
            @unless ($this->allowUnsigned())
                <p class="text-sm text-ink-muted">{{ __('Policy: only signed packages from a trusted key install. Unsigned or tampered packages are rejected.') }}</p>
            @else
                <x-ui.alert variant="warn">{{ __('Development policy: UNSIGNED packages are allowed. Do not use this on a production site.') }}</x-ui.alert>
            @endunless
        </div>

        <form wire:submit="install" class="space-y-3">
            <input type="file" wire:model="archive" accept=".zip" dusk="install-file"
                   class="block w-full text-sm text-ink file:mr-3 file:rounded-md file:border-0 file:bg-accent-soft file:px-3 file:py-2 file:text-accent-soft-ink">
            @error('archive') <p class="text-sm text-danger">{{ $message }}</p> @enderror
            <label class="flex items-center gap-2 text-sm text-ink">
                <input type="checkbox" wire:model="confirmUpgrade" class="rounded border-line" dusk="install-upgrade">
                {{ __('This replaces an already-installed module (upgrade)') }}
            </label>
            <x-ui.button type="submit" variant="primary" dusk="install-submit">{{ __('Upload & install') }}</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card class="space-y-4">
        <div class="space-y-1">
            <h2 class="text-base font-semibold text-ink">{{ __('Trusted signing keys') }}</h2>
            <p class="text-sm text-ink-subtle">{{ __('ed25519 public keys (base64). A package installs only if an enabled key here verifies its signature.') }}</p>
        </div>

        <form wire:submit="addKey" class="space-y-3">
            <x-ui.input name="keyName" :label="__('Key name')" wire:model="keyName" placeholder="Acme Plugins release key" dusk="key-name" />
            <x-ui.input name="publicKey" :label="__('Public key (base64)')" wire:model="publicKey" dusk="key-public" />
            <x-ui.button type="submit" variant="secondary" dusk="key-add">{{ __('Add trusted key') }}</x-ui.button>
        </form>

        @php($keys = $this->keys())
        @if ($keys->isNotEmpty())
            <div class="space-y-2">
                @foreach ($keys as $key)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line p-3" dusk="trust-key-{{ $key->id }}">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-ink">{{ $key->name }}</span>
                                <x-ui.badge :variant="$key->is_enabled ? 'success' : 'neutral'">{{ $key->is_enabled ? __('Trusted') : __('Disabled') }}</x-ui.badge>
                            </div>
                            <div class="text-xs text-ink-subtle"><code>{{ substr($key->fingerprint, 0, 16) }}…</code></div>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="toggleKey({{ $key->id }})" dusk="key-toggle-{{ $key->id }}">
                                {{ $key->is_enabled ? __('Disable') : __('Enable') }}
                            </x-ui.button>
                            <x-ui.button type="button" size="sm" variant="danger-soft" wire:click="removeKey({{ $key->id }})"
                                         wire:confirm="{{ __('Remove this trusted key? Packages signed only by it will no longer install.') }}" dusk="key-remove-{{ $key->id }}">
                                {{ __('Remove') }}
                            </x-ui.button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-ui.card>
</div>
