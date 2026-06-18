{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => __('forum.new_topic').' · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => __('common.forums'), 'url' => route('forums.index')],
        ['label' => $forum->title, 'url' => route('forums.show', $forum)],
        ['label' => __('forum.new_topic')],
    ]" />
@endsection

@section('content')
    <x-ui.container size="md" class="space-y-5">
        <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ __('forum.new_topic_in', ['forum' => $forum->title]) }}</h1>
        <x-ui.card>
            <livewire:forum.create-topic :forum-id="$forum->id" />
        </x-ui.card>
    </x-ui.container>
@endsection
