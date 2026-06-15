{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Linked accounts · '.config('app.name', 'NovFora')])

@section('content')
    <x-settings.shell title="Linked accounts">
        @if (session('status'))
            <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
        @endif
        @if (session('error'))
            <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
        @endif

        <p class="text-sm text-ink-subtle">
            {{ __('Connect a social account to sign in faster. Your password sign-in keeps working either way.') }}
        </p>

        <x-ui.card flush>
            <ul class="divide-y divide-line">
                @foreach ($providers as $key => $meta)
                    @php($isLinked = isset($linked[$key]))
                    @php($canLink = in_array($key, $available, true))
                    @if ($isLinked || $canLink)
                        <li class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5 text-sm">
                            <span class="min-w-0 flex-1 font-medium text-ink">{{ $meta['label'] }}</span>

                            @if ($isLinked)
                                <span class="text-xs text-ink-subtle">
                                    {{ __('Linked') }}@if ($linked[$key]->nickname) · {{ $linked[$key]->nickname }}@endif
                                </span>
                                <form method="POST" action="{{ route('oauth.unlink', $key) }}"
                                      onsubmit="return confirm('{{ __('Unlink :provider?', ['provider' => $meta['label']]) }}')">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" variant="ghost" size="sm" dusk="unlink-{{ $key }}">{{ __('Unlink') }}</x-ui.button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('oauth.link', $key) }}">
                                    @csrf
                                    <x-ui.button type="submit" size="sm" dusk="link-{{ $key }}">{{ __('Link') }}</x-ui.button>
                                </form>
                            @endif
                        </li>
                    @endif
                @endforeach
            </ul>
        </x-ui.card>

        @if (empty($available) && $linked->isEmpty())
            <p class="text-sm text-ink-subtle">{{ __('No social login providers are enabled on this site yet.') }}</p>
        @endif
    </x-settings.shell>
@endsection
