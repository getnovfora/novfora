{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Forums & structure'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Admin'],
        ['label' => 'Content'],
        ['label' => 'Forums & structure'],
    ]" />
@endsection

@section('content')
    <x-admin.shell title="Forums &amp; structure"
                   description="Organise the board: categories hold boards, boards hold sub-boards. Deleting a board with topics asks where to move them — nothing is ever silently lost.">
        <livewire:admin.structure />
    </x-admin.shell>
@endsection
