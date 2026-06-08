{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Anti-spam settings'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'Settings'], ['label' => 'Anti-spam']]" />
@endsection

@section('content')
    <x-admin.shell title="Anti-spam settings" description="CAPTCHA, StopForumSpam, and the crowdsourced blocklist.">
        <livewire:admin.settings.antispam />
    </x-admin.shell>
@endsection
