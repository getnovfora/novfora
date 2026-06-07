{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Notification preferences · '.config('app.name', 'Hearth')])

@section('content')
    <x-settings.shell title="Notifications">
        <p class="text-sm text-ink-muted">
            Choose how you’re notified for each event. Email on a shared host is best-effort.
        </p>

        @if (session('status'))
            <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ route('settings.notifications.save') }}" class="space-y-5">
            @csrf

            <x-ui.card flush>
                <ul class="divide-y divide-line">
                    @foreach ($events as $event)
                        <li class="p-4 sm:px-5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-sm font-medium text-ink capitalize">{{ $event }}</p>
                                <div class="flex flex-wrap gap-x-5 gap-y-2">
                                    @foreach ($channels as $channel)
                                        <label class="inline-flex items-center gap-2 min-h-11 cursor-pointer select-none">
                                            <input type="checkbox" name="pref[{{ $event }}][{{ $channel }}]" value="1"
                                                   @checked($current["{$event}.{$channel}"] ?? true)
                                                   class="h-4 w-4 rounded-sm border-line-strong text-accent focus-visible:ring-accent">
                                            <span class="text-sm text-ink-muted capitalize">{{ $channel === 'database' ? 'In-app' : $channel }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>

            <x-ui.button type="submit">Save preferences</x-ui.button>
        </form>
    </x-settings.shell>
@endsection
