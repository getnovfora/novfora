<?php

// SPDX-License-Identifier: Apache-2.0

use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Permissions\Scope;
use App\Webhooks\WebhookManager;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * The ACP "Webhooks" page (ADR-0033). Admins register outbound endpoints (URL + subscribed events), toggle
 * them, and remove them. Admins-only (admin.access + staff-2FA), re-asserted in mount() AND every action; all
 * writes go through the audited WebhookManager, which SSRF-guards the URL and surfaces a refusal as an inline
 * error rather than a 500.
 */
new class extends Component
{
    public string $url = '';

    public string $description = '';

    /** @var array<int,string> */
    public array $events = [];

    public ?string $error = null;

    public ?string $status = null;

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function create(): void
    {
        $this->ensureAdmin();
        $this->reset('error', 'status');
        $events = array_values(array_intersect(WebhookManager::EVENTS, $this->events));
        if ($events === []) {
            $this->error = __('Choose at least one event.');

            return;
        }
        try {
            app(WebhookManager::class)->create($this->url, $events, $this->description ?: null);
            $this->reset('url', 'description', 'events');
            $this->status = __('Webhook endpoint created.');
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function toggle(int $id): void
    {
        $this->ensureAdmin();
        $endpoint = WebhookEndpoint::findOrFail($id);
        app(WebhookManager::class)->update($endpoint, ['is_active' => ! $endpoint->is_active]);
    }

    public function remove(int $id): void
    {
        $this->ensureAdmin();
        app(WebhookManager::class)->delete(WebhookEndpoint::findOrFail($id));
        $this->status = __('Webhook endpoint removed.');
    }

    /** @return Collection<int,WebhookEndpoint> */
    public function endpoints(): Collection
    {
        $this->ensureAdmin();

        return WebhookEndpoint::query()->latest()->get();
    }

    /** @return list<string> */
    public function availableEvents(): array
    {
        return WebhookManager::EVENTS;
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canDo('admin.access', Scope::global()), 403);
        abort_if($user->isStaff() && $user->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-5" dusk="admin-webhooks">
    @if ($status) <x-ui.alert variant="success">{{ $status }}</x-ui.alert> @endif
    @if ($error) <x-ui.alert variant="danger" dusk="webhook-error">{{ $error }}</x-ui.alert> @endif

    <x-ui.card class="space-y-4">
        <div class="space-y-1">
            <h2 class="text-base font-semibold text-ink">{{ __('Add a webhook') }}</h2>
            <p class="text-sm text-ink-subtle">{{ __('NovFora POSTs a signed JSON payload to your URL when a subscribed event happens. Verify the X-NovFora-Signature header (HMAC-SHA256 of "{timestamp}.{body}" with the endpoint secret).') }}</p>
        </div>
        <form wire:submit="create" class="space-y-3">
            <x-ui.input name="url" :label="__('Endpoint URL (https)')" wire:model="url" placeholder="https://example.com/hooks/novfora" dusk="webhook-url" />
            <x-ui.input name="description" :label="__('Description (optional)')" wire:model="description" />
            <fieldset class="space-y-1.5">
                <legend class="text-sm font-medium text-ink">{{ __('Events') }}</legend>
                @foreach ($this->availableEvents() as $event)
                    <label class="flex items-center gap-2 text-sm text-ink">
                        <input type="checkbox" value="{{ $event }}" wire:model="events" class="rounded border-line">
                        <code class="text-xs">{{ $event }}</code>
                    </label>
                @endforeach
            </fieldset>
            <x-ui.button type="submit" variant="primary" dusk="webhook-create">{{ __('Create webhook') }}</x-ui.button>
        </form>
    </x-ui.card>

    @php($endpoints = $this->endpoints())
    @if ($endpoints->isNotEmpty())
        <div class="space-y-3">
            @foreach ($endpoints as $endpoint)
                <x-ui.card dusk="webhook-{{ $endpoint->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-medium text-ink break-all">{{ $endpoint->url }}</p>
                                <x-ui.badge :variant="$endpoint->is_active ? 'success' : 'neutral'">{{ $endpoint->is_active ? __('Active') : __('Paused') }}</x-ui.badge>
                            </div>
                            @if ($endpoint->description)<p class="text-sm text-ink-muted">{{ $endpoint->description }}</p>@endif
                            <p class="text-xs text-ink-subtle">{{ __('Events:') }} <code>{{ implode(', ', $endpoint->events ?? []) }}</code></p>
                            <p class="text-xs text-ink-subtle">{{ __('Signing secret:') }} <code class="break-all">{{ $endpoint->secret }}</code></p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <x-ui.button size="sm" variant="subtle" wire:click="toggle({{ $endpoint->id }})">{{ $endpoint->is_active ? __('Pause') : __('Resume') }}</x-ui.button>
                            <x-ui.button size="sm" variant="danger" wire:click="remove({{ $endpoint->id }})" dusk="webhook-remove-{{ $endpoint->id }}">{{ __('Remove') }}</x-ui.button>
                        </div>
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif
</div>
