{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Home · '.config('app.name', 'Hearth')])

@section('content')
    @php($user = auth()->user())
    <main style="max-width:46rem;margin:2.5rem auto;padding:0 1.25rem;font-family:system-ui,sans-serif">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <p style="font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:#999;margin:0">{{ config('app.name', 'Hearth') }}</p>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" style="padding:.4rem .8rem;border:1px solid #bbb;border-radius:6px;background:#fff;cursor:pointer">Log out</button>
            </form>
        </div>

        <h1 style="margin:.4rem 0">Welcome, {{ $user->display_name ?? $user->username }}</h1>
        <p style="color:#666">You’re signed in as <strong>{{ $user->username }}</strong> ({{ $user->email }}).</p>

        @if ($user->isStaff() && is_null($user->two_factor_confirmed_at))
            <p style="background:#fff6e5;border:1px solid #f0d089;color:#7a5300;padding:.7rem .9rem;border-radius:6px">
                ⚠ Two-factor authentication is <strong>required for staff accounts</strong>.
                <a href="{{ route('settings.two-factor') }}">Set it up now</a> to access staff tools.
            </p>
        @endif

        <h2 style="margin-top:1.5rem;font-size:1.1rem">Account</h2>
        <ul style="line-height:1.9">
            <li><a href="{{ route('settings.two-factor') }}">Security &amp; two-factor authentication</a></li>
            @if ($user->canDo('admin.access', \App\Permissions\Scope::global()))
                <li><a href="{{ route('admin.system.tier') }}">Admin · Service tier</a></li>
                <li><a href="{{ route('admin.system.permissions') }}">Admin · Permission inspector</a></li>
            @endif
        </ul>
    </main>
@endsection
