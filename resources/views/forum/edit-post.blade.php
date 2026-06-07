{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Edit post · '.config('app.name', 'Hearth')])

@section('content')
    <x-ui.container size="md" class="space-y-4">
        <a href="{{ route('topics.show', $post->topic_id) }}"
           class="inline-flex items-center gap-1.5 text-sm text-ink-muted hover:text-ink">
            <x-ui.icon name="arrow-left" class="h-4 w-4" /> Back to topic
        </a>
        <x-ui.card>
            <livewire:forum.edit-post :post-id="$post->id" />
        </x-ui.card>
    </x-ui.container>
@endsection
