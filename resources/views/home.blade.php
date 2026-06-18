{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Home · '.config('app.name', 'NovFora')])

@section('content')
    @php($user = auth()->user())
    <x-ui.container size="md" class="space-y-6">
        <div class="space-y-1">
            <p class="text-xs font-medium uppercase tracking-wide text-ink-subtle">{{ config('app.name', 'NovFora') }}</p>
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Welcome, <x-ui.user-name :user="$user" /></h1>
            <p class="text-sm text-ink-muted">
                You’re signed in as <span class="font-medium text-ink">{{ $user->username }}</span> ({{ $user->email }}).
            </p>
        </div>

        @if ($user->isStaff() && is_null($user->two_factor_confirmed_at))
            <x-ui.alert variant="warn" title="Two-factor authentication required">
                Staff accounts must enable 2FA before using staff tools.
                <a href="{{ route('settings.two-factor') }}" class="font-medium underline underline-offset-2">Set it up now</a>.
            </x-ui.alert>
        @endif

        <x-ui.card>
            <h2 class="text-lg font-semibold text-ink">Account</h2>
            <ul class="mt-3 divide-y divide-line">
                <li>
                    <a href="{{ route('settings.two-factor') }}"
                       class="flex items-center gap-3 min-h-11 -mx-2 px-2 rounded-md text-sm text-ink hover:bg-surface-sunken">
                        <x-ui.icon name="shield" class="h-5 w-5 text-ink-subtle" />
                        <span>Security &amp; two-factor authentication</span>
                        <x-ui.icon name="chevron-right" class="h-4 w-4 text-ink-subtle ml-auto" />
                    </a>
                </li>
                @if ($user->canDo('admin.access', \App\Permissions\Scope::global()))
                    <li>
                        <a href="{{ route('admin.system.tier') }}"
                           class="flex items-center gap-3 min-h-11 -mx-2 px-2 rounded-md text-sm text-ink hover:bg-surface-sunken">
                            <x-ui.icon name="cog" class="h-5 w-5 text-ink-subtle" />
                            <span>Admin · Service tier</span>
                            <x-ui.icon name="chevron-right" class="h-4 w-4 text-ink-subtle ml-auto" />
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('admin.security.permissions') }}"
                           class="flex items-center gap-3 min-h-11 -mx-2 px-2 rounded-md text-sm text-ink hover:bg-surface-sunken">
                            <x-ui.icon name="shield" class="h-5 w-5 text-ink-subtle" />
                            <span>Admin · Permission inspector</span>
                            <x-ui.icon name="chevron-right" class="h-4 w-4 text-ink-subtle ml-auto" />
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('admin.system.upgrade') }}"
                           class="flex items-center gap-3 min-h-11 -mx-2 px-2 rounded-md text-sm text-ink hover:bg-surface-sunken">
                            <x-ui.icon name="cog" class="h-5 w-5 text-ink-subtle" />
                            <span>Admin · Upgrade</span>
                            <x-ui.icon name="chevron-right" class="h-4 w-4 text-ink-subtle ml-auto" />
                        </a>
                    </li>
                @endif
            </ul>
        </x-ui.card>
    </x-ui.container>
@endsection
