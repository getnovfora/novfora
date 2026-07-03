{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- The route-level friendly-403 (NOV-96 / ADR-0109). Rendered ONLY by App\Exceptions\FriendlyDenialException,
     which carries a curated i18n reason — so, unlike the standalone errors/403 page, this renders inside the
     full app chrome and is auth-aware (a sign-in CTA for guests). A 403 is not a broken app, so the app layout
     is safe here. --}}
@extends('layouts.app', ['title' => __('permissions.denied.page_title').' · '.config('app.name', 'NovFora')])

@section('content')
    <x-ui.container size="sm" class="py-12">
        <div class="mx-auto max-w-md rounded-lg border border-line bg-surface-raised p-6 text-center">
            <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-surface-sunken text-ink-muted">
                <x-ui.icon name="lock" class="h-6 w-6" />
            </div>
            <h1 class="text-xl font-semibold text-ink">{{ __('permissions.denied.heading') }}</h1>
            <p class="mt-2 text-sm text-ink-muted">{{ $reason }}</p>
            <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                @guest
                    <x-ui.button :href="route('login')" variant="primary">{{ __('permissions.denied.sign_in') }}</x-ui.button>
                @endguest
                <x-ui.button :href="url()->previous() && url()->previous() !== url()->current() ? url()->previous() : route('forums.index')"
                             variant="ghost">{{ __('permissions.denied.go_back') }}</x-ui.button>
            </div>
        </div>
    </x-ui.container>
@endsection
