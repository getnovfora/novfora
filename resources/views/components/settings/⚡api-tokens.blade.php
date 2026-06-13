<?php

// SPDX-License-Identifier: Apache-2.0

use App\Api\ApiTokenService;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * Personal API token management (ADR-0033) — own-tokens-only, like the other account SFCs. Re-asserts auth in
 * mount() AND every action; revoke is scoped to the signed-in user's own tokens, so one user can never revoke
 * another's. A newly issued token's plaintext is shown EXACTLY ONCE (it is stored only as a hash and can never
 * be recovered).
 */
new class extends Component
{
    public string $name = '';

    /** The one-time plaintext of a just-issued token (shown once, never persisted in the clear). */
    public ?string $plaintext = null;

    public function mount(): void
    {
        $this->user();
    }

    public function create(): void
    {
        $user = $this->user();
        $data = $this->validate(['name' => ['required', 'string', 'max:60']]);

        $issued = app(ApiTokenService::class)->issue($user, $data['name']);
        $this->plaintext = $issued['plaintext'];
        $this->reset('name');
    }

    public function revoke(int $id): void
    {
        $user = $this->user();
        $token = ApiToken::query()->where('user_id', $user->getKey())->find($id);
        if ($token instanceof ApiToken) {
            app(ApiTokenService::class)->revoke($token);
        }
    }

    public function dismissPlaintext(): void
    {
        $this->user();
        $this->plaintext = null;
    }

    /** @return Collection<int,ApiToken> */
    public function tokens(): Collection
    {
        return ApiToken::query()->where('user_id', $this->user()->getKey())->latest()->get();
    }

    private function user(): User
    {
        $u = auth()->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }
};
?>

<div class="space-y-5" dusk="api-tokens">
    <x-ui.card class="space-y-3">
        <p class="text-sm text-ink-subtle">
            {{ __('Personal tokens authenticate REST API requests as you. A token can do anything you can — keep it secret. Use it as a bearer token:') }}
            <code class="rounded bg-surface-sunken px-1.5 py-0.5 text-xs">Authorization: Bearer &lt;token&gt;</code>
        </p>
    </x-ui.card>

    @if ($plaintext)
        <x-ui.alert variant="success" :title="__('Copy your new token now')" dusk="token-plaintext">
            <p class="text-sm">{{ __('This is the only time it will be shown.') }}</p>
            <code class="mt-2 block break-all rounded bg-surface-sunken px-2 py-1 text-xs">{{ $plaintext }}</code>
            <div class="mt-2"><x-ui.button size="sm" variant="subtle" wire:click="dismissPlaintext">{{ __('Done') }}</x-ui.button></div>
        </x-ui.alert>
    @endif

    <x-ui.card>
        <form wire:submit="create" class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-48">
                <x-ui.input name="name" :label="__('New token name')" wire:model="name" placeholder="My script" dusk="token-name" />
            </div>
            <x-ui.button type="submit" variant="primary" dusk="token-create">{{ __('Create token') }}</x-ui.button>
        </form>
        @error('name') <p class="mt-2 text-xs text-danger">{{ $message }}</p> @enderror
    </x-ui.card>

    @php($tokens = $this->tokens())
    @if ($tokens->isNotEmpty())
        <x-ui.card flush>
            <ul class="divide-y divide-line">
                @foreach ($tokens as $token)
                    <li class="flex items-center justify-between gap-3 px-4 py-3" dusk="token-{{ $token->id }}">
                        <div class="min-w-0">
                            <p class="font-medium text-ink truncate">{{ $token->name }}</p>
                            <p class="text-xs text-ink-subtle">
                                {{ __('Created') }} {{ $token->created_at?->diffForHumans() }}
                                @if ($token->last_used_at) · {{ __('last used') }} {{ $token->last_used_at->diffForHumans() }} @endif
                            </p>
                        </div>
                        <x-ui.button size="sm" variant="danger" wire:click="revoke({{ $token->id }})" dusk="token-revoke-{{ $token->id }}">{{ __('Revoke') }}</x-ui.button>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
