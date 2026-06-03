{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Edit post · '.config('app.name', 'Hearth')])

@section('content')
    <main style="max-width:52rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        <nav style="color:#888;font-size:.85rem">
            <a href="{{ route('topics.show', $post->topic_id) }}">← Back to topic</a>
        </nav>

        <livewire:forum.edit-post :post-id="$post->id" />
    </main>
@endsection
