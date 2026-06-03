{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Forums · '.config('app.name', 'Hearth')])

@section('content')
    <main style="max-width:60rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        <h1>Forums</h1>

        @forelse ($tree as $node)
            @if ($node->isCategory())
                <section style="margin-top:1.5rem">
                    <h2 style="border-bottom:2px solid #ececf1;padding-bottom:.3rem;font-size:1.1rem">{{ $node->title }}</h2>
                    @foreach ($node->children as $forum)
                        @if ($viewer->canDo('forum.view', $forum->permissionScope()))
                            @include('forum.partials.forum-row', ['forum' => $forum])
                        @endif
                    @endforeach
                </section>
            @elseif ($viewer->canDo('forum.view', $node->permissionScope()))
                @include('forum.partials.forum-row', ['forum' => $node])
            @endif
        @empty
            <p style="color:#777">No forums have been created yet.</p>
        @endforelse
    </main>
@endsection
