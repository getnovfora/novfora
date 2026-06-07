<?php
// SPDX-License-Identifier: Apache-2.0
use Livewire\Component;

new class extends Component
{
    // Any Livewire action re-renders this component, which re-runs the (per-request) probes.
    public function redetect(): void {}
};
?>

@php($snapshot = app(\App\Services\Tier\ServiceTier::class)->snapshot(fresh: true))
<div class="space-y-6">
    <div class="flex flex-wrap items-center gap-3">
        <span class="text-sm font-medium text-ink">Overall tier</span>
        <x-ui.badge variant="accent">{{ $snapshot->overall->label() }}</x-ui.badge>
        <x-ui.button type="button" variant="ghost" size="sm" wire:click="redetect" wire:loading.attr="disabled">Re-detect</x-ui.button>
        <span wire:loading class="text-xs text-ink-subtle">probing…</span>
    </div>

    {{-- Capabilities --}}
    <section class="space-y-2">
        <h3 class="text-sm font-semibold text-ink">Capabilities</h3>
        <x-ui.card flush>
            {{-- Column header (desktop only); rows reflow to stacked cards on mobile. --}}
            <div class="hidden sm:grid grid-cols-[1fr_1fr_auto] gap-3 px-4 py-2.5 sm:px-5 border-b border-line bg-surface-sunken text-xs font-semibold uppercase tracking-wide text-ink-subtle">
                <span>Capability</span>
                <span>Active driver</span>
                <span>Tier</span>
            </div>
            <div class="divide-y divide-line">
                @foreach ($snapshot->capabilities as $c)
                    <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-[1fr_1fr_auto] sm:items-center sm:gap-3 sm:px-5">
                        <span class="font-medium text-ink">{{ $c->capability->label() }}</span>
                        <span class="text-sm text-ink-muted"><code class="font-mono">{{ $c->driver }}</code></span>
                        <span class="text-sm text-ink-muted">{{ $c->tier->label() }}</span>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    </section>

    {{-- Optional enhanced services --}}
    <section class="space-y-2">
        <h3 class="text-sm font-semibold text-ink">Optional enhanced services</h3>
        <x-ui.card flush>
            <div class="hidden md:grid grid-cols-[1.4fr_auto_auto_auto_2fr] gap-3 px-4 py-2.5 sm:px-5 border-b border-line bg-surface-sunken text-xs font-semibold uppercase tracking-wide text-ink-subtle">
                <span>Service</span>
                <span>Configured</span>
                <span>Reachable</span>
                <span>Latency</span>
                <span>Enabling it unlocks…</span>
            </div>
            <div class="divide-y divide-line">
                @foreach ($snapshot->services as $s)
                    <div class="grid grid-cols-1 gap-1.5 px-4 py-3 md:grid-cols-[1.4fr_auto_auto_auto_2fr] md:items-center md:gap-3 sm:px-5">
                        <span class="font-medium text-ink">{{ $s->label }}</span>
                        <span class="text-sm">
                            @if ($s->configured)
                                <x-ui.badge variant="success">Configured</x-ui.badge>
                            @else
                                <x-ui.badge>Not configured</x-ui.badge>
                            @endif
                        </span>
                        <span class="text-sm">
                            @if (! $s->configured)
                                <span class="text-ink-subtle">—</span>
                            @elseif ($s->reachable)
                                <x-ui.badge variant="success">Reachable</x-ui.badge>
                            @else
                                <x-ui.badge variant="danger">Unreachable</x-ui.badge>
                            @endif
                        </span>
                        <span class="text-sm text-ink-muted nums">{{ $s->latencyMs !== null ? $s->latencyMs.' ms' : '—' }}</span>
                        <span class="text-sm text-ink-muted">{{ $s->unlocks }}</span>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    </section>
</div>
