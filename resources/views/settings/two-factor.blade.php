{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Two-factor · '.config('app.name', 'Hearth')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Home', 'url' => route('home')],
        ['label' => 'Settings', 'url' => route('settings.profile')],
        ['label' => 'Security'],
    ]" />
@endsection

@section('content')
    @php($user = auth()->user())
    @php($codes = $user->two_factor_recovery_codes ? json_decode(decrypt($user->two_factor_recovery_codes), true) : [])

    <x-settings.shell title="Two-factor authentication">
        @if (session('status') === 'two-factor-required')
            <x-ui.alert variant="warn">
                Two-factor authentication is required for staff accounts. Enable it below to continue.
            </x-ui.alert>
        @endif
        @if (session('status') === 'two-factor-authentication-enabled')
            <x-ui.alert variant="success">
                Two-factor enabled — scan the QR code and confirm with a code to finish.
            </x-ui.alert>
        @endif
        @if (session('status') === 'two-factor-authentication-confirmed')
            <x-ui.alert variant="success">
                Two-factor authentication is now active.
            </x-ui.alert>
        @endif
        @if ($errors->any())
            <x-ui.alert variant="danger" title="Please fix the following">
                <ul class="list-disc pl-5 space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        @if (is_null($user->two_factor_secret))
            <x-ui.card class="space-y-4">
                <p class="text-sm text-ink-muted">
                    Add a second step at sign-in using an authenticator app (TOTP — e.g. 1Password, Aegis, Google Authenticator).
                    @if ($user->isStaff()) <strong class="text-ink">Required for staff.</strong> @endif
                </p>
                <form method="POST" action="{{ route('two-factor.enable') }}">
                    @csrf
                    <x-ui.button type="submit">
                        <x-ui.icon name="shield" class="h-4 w-4" /> Enable two-factor authentication
                    </x-ui.button>
                </form>
            </x-ui.card>
        @else
            @if (is_null($user->two_factor_confirmed_at))
                <x-ui.card class="space-y-5">
                    <div class="space-y-3">
                        <h3 class="text-sm font-semibold text-ink">1 · Scan this QR code</h3>
                        <div class="inline-block rounded-lg border border-line bg-white p-2">{!! $user->twoFactorQrCodeSvg() !!}</div>
                    </div>

                    <div class="space-y-3">
                        <h3 class="text-sm font-semibold text-ink">2 · Confirm a generated code</h3>
                        <form method="POST" action="{{ route('two-factor.confirm') }}" class="flex flex-col gap-2 sm:flex-row sm:items-start">
                            @csrf
                            <div class="sm:w-48">
                                <label for="code" class="sr-only">Authentication code</label>
                                <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus
                                       class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink placeholder:text-ink-subtle border border-line transition-colors focus:border-accent">
                            </div>
                            <x-ui.button type="submit">Confirm</x-ui.button>
                        </form>
                    </div>
                </x-ui.card>
            @else
                <x-ui.alert variant="success">
                    <span class="inline-flex items-center gap-2">
                        <x-ui.icon name="check" class="h-4 w-4" />
                        Two-factor authentication is active on your account.
                    </span>
                </x-ui.alert>
            @endif

            <x-ui.card class="space-y-3">
                <h3 class="text-sm font-semibold text-ink">Recovery codes</h3>
                <p class="text-sm text-ink-muted">Keep these somewhere safe. Each can be used once if you lose your authenticator.</p>
                <ul class="rounded-lg border border-line bg-surface-sunken p-4 font-mono text-sm text-ink grid grid-cols-1 gap-1 sm:grid-cols-2 nums">
                    @foreach ($codes as $code)
                        <li>{{ $code }}</li>
                    @endforeach
                </ul>
            </x-ui.card>

            <form method="POST" action="{{ route('two-factor.disable') }}">
                @csrf
                @method('DELETE')
                <x-ui.button type="submit" variant="danger-ghost">Disable two-factor authentication</x-ui.button>
            </form>
        @endif
    </x-settings.shell>
@endsection
