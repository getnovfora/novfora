{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Join '.$club->name.' · '.config('app.name', 'NovFora')])

@section('content')
    <x-ui.container size="sm" class="space-y-5">
        @if (session('error'))
            <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <div class="space-y-4">
                <h1 class="text-xl font-semibold text-ink">{{ __('You’re invited to :club', ['club' => $club->name]) }}</h1>
                @if ($club->tagline)
                    <p class="text-sm text-ink-subtle">{{ $club->tagline }}</p>
                @endif

                @if ($valid)
                    <form method="POST" action="{{ route('clubs.invite.accept', ['club' => $club, 'invitation' => $invitation->token]) }}">
                        @csrf
                        <x-ui.button type="submit" dusk="invite-accept">{{ __('Accept invitation') }}</x-ui.button>
                    </form>
                @else
                    <x-ui.alert variant="warn">{{ __('This invitation has expired or has already been used.') }}</x-ui.alert>
                    <a href="{{ route('clubs.index') }}" class="text-sm text-accent hover:underline">{{ __('Browse other clubs') }}</a>
                @endif
            </div>
        </x-ui.card>
    </x-ui.container>
@endsection
