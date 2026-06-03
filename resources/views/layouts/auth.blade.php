{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => ($authTitle ?? 'Account').' · '.config('app.name', 'Hearth')])

@section('content')
    <main style="max-width:25rem;margin:3.5rem auto;padding:0 1.25rem;font-family:system-ui,sans-serif">
        <p style="font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:#999;margin:0">{{ config('app.name', 'Hearth') }}</p>
        <h1 style="font-size:1.55rem;margin:.15rem 0 1.1rem">{{ $authTitle ?? 'Account' }}</h1>

        @if (session('status') && session('status') !== 'two-factor-required')
            <p style="background:#eef7ee;border:1px solid #bcdcbc;color:#1a6b1a;padding:.55rem .8rem;border-radius:6px">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <ul style="background:#fdeeee;border:1px solid #e9b3b3;color:#a11;padding:.55rem .8rem .55rem 1.7rem;border-radius:6px;margin:0 0 1rem">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        @yield('auth')
    </main>
@endsection
