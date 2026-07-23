<?php
// SPDX-License-Identifier: Apache-2.0
use App\Admin\AdminCoOwnerService;
use App\Api\ApiScopes;
use App\Api\ApiTokenService;
use App\Models\ApiToken;
use App\Models\User;
use App\Permissions\Scope;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Admin → Security → API tokens (E1 / NOV-135, ADR-0115). Mint / revoke SCOPED Admin-API tokens. Gated on
 * admin.api_tokens.manage + staff-2FA (the "minting only from a 2FA-verified panel session" rule, re-asserted in
 * mount + every action). A token acts as its owner, so it is always minted for the CURRENT admin; destructive
 * scopes (backups/restore/upgrade/populate) additionally require the actor be a co-owner. The secret is shown
 * exactly once. Revoke works on any admin token (incident response — kill a leaked key).
 */
new class extends Component
{
    public string $name = '';

    /** @var list<string> */
    public array $scopes = [];

    public string $ipAllowlist = '';

    public ?int $expiresDays = null;

    public ?string $plaintext = null;

    public ?string $message = null;

    public string $messageVariant = 'info';

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    /** @return list<string> */
    public function scopeOptions(): array
    {
        return ApiScopes::ALL;
    }

    public function create(ApiTokenService $service): void
    {
        $this->ensureAdmin();
        $user = auth()->user();
        $data = $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(ApiScopes::ALL)],
            'ipAllowlist' => ['nullable', 'string', 'max:1000'],
            'expiresDays' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $scopes = ApiScopes::sanitize($data['scopes']);
        if ($scopes === []) {
            $this->addError('scopes', 'Pick at least one valid scope.');

            return;
        }

        // Destructive / secret-bearing scopes are co-owner-mintable only.
        if (ApiScopes::requiresCoOwner($scopes) && ! app(AdminCoOwnerService::class)->isCoOwner($user)) {
            $this->addError('scopes', 'Backups, restore, upgrade, and populate scopes may only be minted by a co-owner.');

            return;
        }

        // Parse the optional IP allowlist; a non-empty box that yields no valid IP is an error.
        $ips = $this->parseIps($this->ipAllowlist);
        if (trim($this->ipAllowlist) !== '' && $ips === []) {
            $this->addError('ipAllowlist', 'Enter one valid IP address per line (or leave blank for any).');

            return;
        }

        $expiresAt = $data['expiresDays'] ? now()->addDays((int) $data['expiresDays']) : null;
        $result = $service->issue($user, $data['name'], $expiresAt, $scopes, $ips === [] ? null : $ips);
        $this->plaintext = $result['plaintext'];
        $this->reset(['name', 'scopes', 'ipAllowlist', 'expiresDays']);
    }

    public function revoke(int $id, ApiTokenService $service): void
    {
        $this->ensureAdmin();
        $token = ApiToken::query()->whereNotNull('scopes')->find($id); // admin-scoped tokens only
        if ($token instanceof ApiToken) {
            $service->revoke($token);
            $this->flash('Token revoked.', 'success');
        }
    }

    public function dismissPlaintext(): void
    {
        $this->plaintext = null;
    }

    /** @return Collection<int, ApiToken> */
    public function tokens(): Collection
    {
        $this->ensureAdmin();

        return ApiToken::query()->whereNotNull('scopes')->with('user:id,username')->latest()->get();
    }

    /**
     * Split the allowlist textarea into a de-duplicated list of valid IP strings (inet_pton). Invalid lines drop.
     *
     * @return list<string>
     */
    private function parseIps(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', trim($raw)) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line !== '' && @inet_pton($line) !== false) {
                $out[] = $line;
            }
        }

        return array_values(array_unique($out));
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
        abort_unless($u->canDo('admin.api_tokens.manage', Scope::global()), 403);
        // Minting admin credentials REQUIRES a confirmed second factor. Since every principal reaching here holds
        // admin.access (privileged), gate on 2FA unconditionally — mirroring RequireTwoFactorForStaff, which was
        // widened past isStaff() to also cover per-user / bundle-restricted admins (a Livewire action carries no
        // route middleware, so this is the authoritative mint-time 2FA gate).
        abort_if($u->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-6" dusk="acp-api-tokens">
    @if ($message)
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    @if ($plaintext)
        <x-ui.alert variant="success">
            <p class="font-medium">Copy this token now — it is shown only once.</p>
            <code class="mt-2 block break-all rounded bg-surface-sunken px-2 py-1 font-mono text-sm" dusk="acp-token-plaintext">{{ $plaintext }}</code>
            <x-ui.button type="button" variant="ghost" size="sm" class="mt-2" wire:click="dismissPlaintext">Done</x-ui.button>
        </x-ui.alert>
    @endif

    {{-- Mint a token. --}}
    <x-ui.card>
        <form wire:submit="create" class="space-y-4">
            <h2 class="text-sm font-semibold text-ink">Mint an Admin-API token</h2>
            <p class="text-sm text-ink-muted">The token acts as you — it can never do more than your own permissions. Its secret is shown once.</p>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input label="Name" name="name" wire:model="name" placeholder="e.g. CI backup runner" dusk="acp-token-name" />
                <x-ui.input label="Expires in (days, optional)" name="expiresDays" type="number" min="1" max="3650" wire:model="expiresDays" />
            </div>

            <div>
                <label class="block text-xs font-medium text-ink-muted">Scopes</label>
                <div class="mt-1 grid gap-1.5 sm:grid-cols-2">
                    @foreach ($this->scopeOptions() as $scope)
                        <label class="flex items-center gap-2 text-sm text-ink">
                            <input type="checkbox" value="{{ $scope }}" wire:model="scopes" class="rounded border-line text-accent focus:ring-accent">
                            <code class="font-mono text-xs">{{ $scope }}</code>
                        </label>
                    @endforeach
                </div>
                @error('scopes') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <x-ui.textarea label="IP allowlist (optional, one per line)" name="ipAllowlist" rows="2" wire:model="ipAllowlist"
                           hint="Restrict the token to these exact IPs. Leave blank for any." />
            @error('ipAllowlist') <p class="text-xs text-danger">{{ $message }}</p> @enderror

            <x-ui.button type="submit" dusk="acp-token-create">Mint token</x-ui.button>
        </form>
    </x-ui.card>

    {{-- Active admin tokens. --}}
    <x-ui.card flush>
        <ul class="divide-y divide-line">
            @forelse ($this->tokens() as $token)
                <li class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5 text-sm">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-ink">{{ $token->name }} <span class="text-ink-subtle">· {{ $token->user?->username ?? 'unknown' }}</span></p>
                        <p class="mt-0.5 text-xs text-ink-subtle">
                            {{ implode(', ', $token->scopes ?? []) }}
                            @if ($token->expires_at) · expires {{ $token->expires_at->diffForHumans() }} @endif
                            @if ($token->last_used_at) · last used {{ $token->last_used_at->diffForHumans() }} @else · never used @endif
                        </p>
                    </div>
                    <x-ui.button type="button" variant="danger-ghost" size="sm" wire:click="revoke({{ $token->id }})"
                                 wire:confirm="Revoke this token? Any caller using it stops working immediately."
                                 dusk="acp-token-revoke-{{ $token->id }}">Revoke</x-ui.button>
                </li>
            @empty
                <li class="px-4 py-6 sm:px-5 text-sm text-ink-subtle">No Admin-API tokens yet. Mint one above.</li>
            @endforelse
        </ul>
    </x-ui.card>
</div>
