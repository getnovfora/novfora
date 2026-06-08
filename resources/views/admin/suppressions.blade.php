{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'System · Email suppressions'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Admin'],
        ['label' => 'System'],
        ['label' => 'Email suppressions'],
    ]" />
@endsection

@section('content')
    <x-admin.shell title="Email suppressions">
        <p class="text-sm text-ink-muted max-w-2xl">
            Addresses that hard-bounced or filed a spam complaint are suppressed automatically (via a provider
            webhook, a polled bounce mailbox, or a signed VERP return path) and are skipped on future sends to
            protect your domain's sending reputation. You can also suppress or un-suppress an address by hand
            here — the always-available baseline floor, working even with no email provider configured.
        </p>

        <livewire:admin.suppressions />
    </x-admin.shell>
@endsection
