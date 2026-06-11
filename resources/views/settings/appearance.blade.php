{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Appearance · '.config('app.name', 'NovFora')])

@section('content')
    <x-settings.shell title="Appearance">
        <p class="text-sm text-ink-muted">
            Choose how {{ config('app.name', 'NovFora') }} looks for you. These settings follow your account on
            every device, and apply even with JavaScript disabled.
        </p>

        {{-- Works with NO JavaScript (a normal form POST). Alpine enhancement applies the change instantly. --}}
        <form method="POST" action="{{ route('settings.appearance.save') }}" class="space-y-8"
              x-data="{
                  mode: @js($user->color_mode),
                  density: @js($user->density),
                  setMode(m) { this.mode = m; window.NovFora && window.NovFora.setColorMode(m); },
                  setDensity(d) { this.density = d; window.NovFora && window.NovFora.setDensity(d); },
              }">
            @csrf

            {{-- Colour mode --}}
            <x-ui.card>
                <fieldset>
                    <legend class="text-sm font-semibold text-ink">Colour mode</legend>
                    <p class="text-xs text-ink-muted mt-0.5 mb-3">“Automatic” follows your device’s light/dark setting.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        @foreach (['auto' => ['Automatic', 'monitor'], 'light' => ['Light', 'sun'], 'dark' => ['Dark', 'moon']] as $value => [$label, $icon])
                            <label class="relative flex items-center gap-3 rounded-lg border p-3 cursor-pointer transition-colors"
                                   :class="mode === '{{ $value }}' ? 'border-accent bg-accent-soft' : 'border-line hover:border-line-strong'">
                                <input type="radio" name="color_mode" value="{{ $value }}" @checked($user->color_mode === $value)
                                       class="sr-only peer" @change="setMode('{{ $value }}')">
                                <x-ui.icon name="{{ $icon }}" class="h-5 w-5 text-ink-muted" />
                                <span class="text-sm font-medium text-ink">{{ $label }}</span>
                                <x-ui.icon name="check" class="h-4 w-4 text-accent ml-auto" x-show="mode === '{{ $value }}'" x-cloak />
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            </x-ui.card>

            {{-- Density --}}
            <x-ui.card>
                <fieldset>
                    <legend class="text-sm font-semibold text-ink">Density</legend>
                    <p class="text-xs text-ink-muted mt-0.5 mb-3">Compact tightens spacing for denser lists.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach (['comfortable' => 'Comfortable', 'compact' => 'Compact'] as $value => $label)
                            <label class="relative flex items-center gap-3 rounded-lg border p-3 cursor-pointer transition-colors"
                                   :class="density === '{{ $value }}' ? 'border-accent bg-accent-soft' : 'border-line hover:border-line-strong'">
                                <input type="radio" name="density" value="{{ $value }}" @checked($user->density === $value)
                                       class="sr-only peer" @change="setDensity('{{ $value }}')">
                                <span class="text-sm font-medium text-ink">{{ $label }}</span>
                                <x-ui.icon name="check" class="h-4 w-4 text-accent ml-auto" x-show="density === '{{ $value }}'" x-cloak />
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            </x-ui.card>

            <div class="flex items-center gap-3">
                <x-ui.button type="submit">Save appearance</x-ui.button>
                <span class="text-xs text-ink-subtle">Changes preview instantly; Save stores them to your account.</span>
            </div>
        </form>
    </x-settings.shell>
@endsection
