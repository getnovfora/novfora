{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Two-factor · '.config('app.name', 'Hearth')])

@section('content')
    @php($user = auth()->user())
    @php($codes = $user->two_factor_recovery_codes ? json_decode(decrypt($user->two_factor_recovery_codes), true) : [])
    <main style="max-width:40rem;margin:2.5rem auto;padding:0 1.25rem;font-family:system-ui,sans-serif">
        <nav style="color:#888;font-size:.85rem"><a href="{{ route('home') }}">Home</a> → Security</nav>
        <h1>Two-factor authentication</h1>

        @if (session('status') === 'two-factor-required')
            <p style="background:#fff6e5;border:1px solid #f0d089;color:#7a5300;padding:.7rem .9rem;border-radius:6px">
                Two-factor authentication is required for staff accounts. Enable it below to continue.
            </p>
        @endif
        @if (session('status') === 'two-factor-authentication-enabled')
            <p style="background:#eef7ee;border:1px solid #bcdcbc;color:#1a6b1a;padding:.6rem .85rem;border-radius:6px">
                Two-factor enabled — scan the QR code and confirm with a code to finish.
            </p>
        @endif
        @if (session('status') === 'two-factor-authentication-confirmed')
            <p style="background:#eef7ee;border:1px solid #bcdcbc;color:#1a6b1a;padding:.6rem .85rem;border-radius:6px">
                Two-factor authentication is now active.
            </p>
        @endif
        @if ($errors->any())
            <ul style="background:#fdeeee;border:1px solid #e9b3b3;color:#a11;padding:.55rem .8rem .55rem 1.7rem;border-radius:6px">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        @if (is_null($user->two_factor_secret))
            <p style="color:#555">
                Add a second step at sign-in using an authenticator app (TOTP — e.g. 1Password, Aegis, Google Authenticator).
                @if ($user->isStaff()) <strong>Required for staff.</strong> @endif
            </p>
            <form method="POST" action="{{ route('two-factor.enable') }}">
                @csrf
                <button type="submit" style="padding:.6rem 1rem;border:0;border-radius:6px;background:#2d2a6b;color:#fff;font-size:1rem;cursor:pointer">Enable two-factor authentication</button>
            </form>
        @else
            @if (is_null($user->two_factor_confirmed_at))
                <h2 style="font-size:1.05rem;margin-bottom:.3rem">1 · Scan this QR code</h2>
                <div style="background:#fff;display:inline-block;padding:.5rem;border:1px solid #eee;border-radius:8px">{!! $user->twoFactorQrCodeSvg() !!}</div>

                <h2 style="font-size:1.05rem;margin-bottom:.3rem">2 · Confirm a generated code</h2>
                <form method="POST" action="{{ route('two-factor.confirm') }}" style="display:flex;gap:.5rem;align-items:center">
                    @csrf
                    <input name="code" inputmode="numeric" autocomplete="one-time-code" autofocus
                           style="padding:.5rem;border:1px solid #bbb;border-radius:6px">
                    <button type="submit" style="padding:.55rem .9rem;border:0;border-radius:6px;background:#2d2a6b;color:#fff;cursor:pointer">Confirm</button>
                </form>
            @else
                <p style="background:#eef7ee;border:1px solid #bcdcbc;color:#1a6b1a;padding:.6rem .85rem;border-radius:6px">
                    ✅ Two-factor authentication is active on your account.
                </p>
            @endif

            <h2 style="font-size:1.05rem;margin-top:1.5rem">Recovery codes</h2>
            <p style="color:#555">Keep these somewhere safe. Each can be used once if you lose your authenticator.</p>
            <ul style="font-family:ui-monospace,monospace;background:#f7f7f8;border:1px solid #eee;border-radius:6px;padding:.8rem 1.6rem;display:inline-block">
                @foreach ($codes as $code)
                    <li>{{ $code }}</li>
                @endforeach
            </ul>

            <form method="POST" action="{{ route('two-factor.disable') }}" style="margin-top:1rem">
                @csrf
                @method('DELETE')
                <button type="submit" style="padding:.5rem .9rem;border:1px solid #d99;border-radius:6px;background:#fff;color:#a11;cursor:pointer">Disable two-factor authentication</button>
            </form>
        @endif
    </main>
@endsection
