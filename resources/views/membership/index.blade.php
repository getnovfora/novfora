{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Membership · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Membership']]" />
@endsection

@section('content')
    <x-ui.container size="lg" class="space-y-5">
        <h1 class="text-2xl font-semibold tracking-tight text-ink">Membership</h1>

        @if ($current)
            <x-ui.alert variant="success">
                You’re on the <strong>{{ $current->tier?->name }}</strong> plan.
                @if ($current->expires_at)
                    Renews/expires {{ $current->expires_at->toFormattedDateString() }}.
                @endif
            </x-ui.alert>
        @endif

        @if ($tiers->isEmpty())
            <p class="text-sm text-ink-subtle">No membership tiers are available right now.</p>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($tiers as $tier)
                    <x-ui.card>
                        <div class="space-y-3">
                            <div>
                                <h2 class="text-lg font-semibold text-ink">{{ $tier->name }}</h2>
                                <p class="text-sm text-ink-muted">{{ $tier->priceLabel() }}</p>
                            </div>
                            @if ($tier->description)
                                <p class="text-sm text-ink-muted">{{ $tier->description }}</p>
                            @endif
                            @php($perks = $tier->perkKeys())
                            @if ($perks !== [])
                                <ul class="space-y-1 text-sm text-ink">
                                    @foreach ($perks as $perk)
                                        <li class="flex items-center gap-2">
                                            <x-ui.icon name="check" class="h-4 w-4 text-accent" />
                                            {{ \App\Membership\TierPerks::ALL[$perk] ?? $perk }}
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </x-ui.card>
                @endforeach
            </div>
            <p class="text-xs text-ink-subtle">To join a plan, contact an administrator. Online checkout availability depends on this site’s configuration.</p>
        @endif
    </x-ui.container>
@endsection
