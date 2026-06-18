{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Manage '.$club->name.' · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Forums', 'url' => route('forums.index')],
        ['label' => 'Clubs', 'url' => route('clubs.index')],
        ['label' => $club->name, 'url' => route('clubs.show', $club)],
        ['label' => 'Manage'],
    ]" />
@endsection

@section('content')
    <x-ui.container size="md" class="space-y-5">
        <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ __('Manage club') }}</h1>
        <livewire:clubs.edit :club="$club" />

        {{-- ACP v3 · v3-c — club-scope card-per-group permission editor (the third home). Gated by club.manage
             inside the component; scoped to this club so its grants never escalate global privilege. --}}
        <section class="space-y-3" aria-labelledby="club-perms-heading">
            <h2 id="club-perms-heading" class="text-lg font-semibold tracking-tight text-ink">{{ __('admin.perms.title') }}</h2>
            <livewire:permissions.group-editor scope-type="club" :scope-id="$club->id" />
        </section>
    </x-ui.container>
@endsection
