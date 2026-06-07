<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\User;
use App\Permissions\PermissionInspector;
use App\Permissions\Scope;
use Livewire\Component;

new class extends Component
{
    public string $userRef = '';

    public string $permission = '';

    public string $scopeRef = 'global';

    /** @var array<string,mixed>|null */
    public ?array $report = null;

    public ?string $error = null;

    public function inspect(): void
    {
        $this->report = null;
        $this->error = null;

        $user = is_numeric($this->userRef)
            ? User::find((int) $this->userRef)
            : User::where('email', $this->userRef)->orWhere('username', $this->userRef)->first();

        if (! $user) {
            $this->error = "No user matched [{$this->userRef}].";

            return;
        }

        try {
            $scope = Scope::parse($this->scopeRef !== '' ? $this->scopeRef : 'global');
        } catch (\InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->report = app(PermissionInspector::class)->inspect($user, trim($this->permission), $scope);
    }
};
?>

<div class="space-y-5">
    <x-ui.card>
        <form wire:submit="inspect" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
            <div class="space-y-1.5">
                <label for="pi-user" class="block text-sm font-medium text-ink">User (id or email)</label>
                <input id="pi-user" wire:model="userRef" required
                       class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink placeholder:text-ink-subtle border border-line transition-colors focus:border-accent">
            </div>
            <div class="space-y-1.5">
                <label for="pi-permission" class="block text-sm font-medium text-ink">Permission key</label>
                <input id="pi-permission" wire:model="permission" required placeholder="forum.post.create"
                       class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink placeholder:text-ink-subtle border border-line transition-colors focus:border-accent">
            </div>
            <div class="space-y-1.5">
                <label for="pi-scope" class="block text-sm font-medium text-ink">Scope</label>
                <input id="pi-scope" wire:model="scopeRef" placeholder="global | forum:2 | thread:1"
                       class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink placeholder:text-ink-subtle border border-line transition-colors focus:border-accent">
            </div>
            <div class="flex items-center gap-3">
                <x-ui.button type="submit" wire:loading.attr="disabled">Explain</x-ui.button>
                <span wire:loading class="text-xs text-ink-subtle">resolving…</span>
            </div>
        </form>
    </x-ui.card>

    @if ($error)
        <x-ui.alert variant="danger">{{ $error }}</x-ui.alert>
    @endif

    @if ($report)
        @php($granted = $report['granted'])
        <x-ui.alert :variant="$granted ? 'success' : 'danger'" :title="$granted ? 'ALLOWED' : 'DENIED'">
            {{ $report['summary'] }}
        </x-ui.alert>

        {{-- Resolution detail: label/value rows that reflow to stacked on mobile. --}}
        <x-ui.card flush>
            <dl class="divide-y divide-line text-sm">
                <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-[10rem_1fr] sm:gap-3 sm:px-5">
                    <dt class="text-ink-subtle">User</dt>
                    <dd class="text-ink"><strong class="font-semibold">{{ $report['user']['label'] }}</strong> (#{{ $report['user']['id'] }}, {{ $report['user']['status'] }})</dd>
                </div>
                <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-[10rem_1fr] sm:gap-3 sm:px-5">
                    <dt class="text-ink-subtle">Permission</dt>
                    <dd class="text-ink"><code class="font-mono">{{ $report['permission'] }}</code></dd>
                </div>
                <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-[10rem_1fr] sm:gap-3 sm:px-5">
                    <dt class="text-ink-subtle">Scope</dt>
                    <dd class="text-ink"><code class="font-mono">{{ $report['scope'] }}</code></dd>
                </div>
                <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-[10rem_1fr] sm:gap-3 sm:px-5">
                    <dt class="text-ink-subtle">Decisive rule</dt>
                    <dd class="text-ink">
                        <code class="font-mono">{{ $report['reason'] }}</code>@if ($report['decided_by']) <span class="text-ink-subtle">by {{ $report['decided_by'] }} @ {{ $report['decided_at_scope'] ?? '—' }}</span>@endif
                    </dd>
                </div>
                <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-[10rem_1fr] sm:gap-3 sm:px-5">
                    <dt class="text-ink-subtle">Scope chain</dt>
                    <dd class="text-ink"><code class="font-mono break-words">{{ implode('  →  ', $report['scope_chain']) }}</code></dd>
                </div>
                <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-[10rem_1fr] sm:gap-3 sm:px-5">
                    <dt class="text-ink-subtle">Holders</dt>
                    <dd class="text-ink">{{ implode(', ', $report['holders']) }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <section class="space-y-2">
            <h3 class="text-sm font-semibold text-ink">Candidate ACL entries</h3>
            @if ($report['entries'] === [])
                <x-ui.card>
                    <p class="text-sm text-ink-muted">No entries matched these holders for this permission in this chain — deny-by-default.</p>
                </x-ui.card>
            @else
                <x-ui.card flush>
                    <div class="hidden sm:grid grid-cols-3 gap-3 px-4 py-2.5 sm:px-5 border-b border-line bg-surface-sunken text-xs font-semibold uppercase tracking-wide text-ink-subtle">
                        <span>Holder</span>
                        <span>Scope</span>
                        <span>Value</span>
                    </div>
                    <div class="divide-y divide-line">
                        @foreach ($report['entries'] as $entry)
                            <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-3 sm:items-center sm:gap-3 sm:px-5 text-sm">
                                <span class="text-ink"><code class="font-mono">{{ $entry['holder'] }}</code></span>
                                <span class="text-ink"><code class="font-mono">{{ $entry['scope'] }}</code></span>
                                <span class="text-ink-muted">{{ $entry['value'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif
        </section>
    @endif
</div>
