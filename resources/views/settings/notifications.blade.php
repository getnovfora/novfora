{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Notification preferences · '.config('app.name', 'Hearth')])

@section('content')
    <main style="max-width:34rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        <h1>Notification preferences</h1>
        <p style="color:#777">Choose how you're notified for each event. Email on a shared host is best-effort.</p>

        @if (session('status'))
            <p style="color:#1a7a1a">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('settings.notifications.save') }}">
            @csrf
            <table style="width:100%;border-collapse:collapse;margin-top:1rem">
                <thead>
                    <tr style="text-align:left;border-bottom:1px solid #ddd">
                        <th style="padding:.4rem">Event</th>
                        @foreach ($channels as $channel)
                            <th style="padding:.4rem;text-transform:capitalize">{{ $channel === 'database' ? 'In-app' : $channel }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($events as $event)
                        <tr style="border-bottom:1px solid #f0f0f3">
                            <td style="padding:.5rem;text-transform:capitalize">{{ $event }}</td>
                            @foreach ($channels as $channel)
                                <td style="padding:.5rem">
                                    <input type="checkbox" name="pref[{{ $event }}][{{ $channel }}]" value="1"
                                           @checked($current["{$event}.{$channel}"] ?? true)>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <button type="submit" style="margin-top:1rem;padding:.55rem 1.1rem;border:0;border-radius:6px;background:#2d2a6b;color:#fff;cursor:pointer">Save preferences</button>
        </form>
    </main>
@endsection
