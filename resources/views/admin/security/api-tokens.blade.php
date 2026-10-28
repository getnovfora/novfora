{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'API tokens · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Admin'],
        ['label' => __('admin.sections.security')],
        ['label' => __('admin.nav.api_tokens')],
    ]" />
@endsection

@section('content')
    <x-admin.shell title="API tokens">
        <p class="text-sm text-ink-muted max-w-2xl">
            Scoped tokens for the Admin API (<code class="font-mono text-xs">/api/admin/v1</code>). A token acts as
            the admin who mints it and can never do more than they can — its effective ability is its scopes
            intersected with your own permissions. Destructive scopes (backups, restore, upgrade, populate) may
            only be minted by a co-owner. Secrets are shown once.
        </p>

        <livewire:admin.security.api-tokens />
    </x-admin.shell>
@endsection
