{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => ($user->display_name ?? $user->username).' · '.config('app.name', 'Hearth')])

@section('content')
    <main style="max-width:42rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        @if ($user->cover_path)
            <img src="{{ Storage::disk('public')->url($user->cover_path) }}" alt="" style="width:100%;height:140px;object-fit:cover;border-radius:10px">
        @endif

        <div style="display:flex;gap:1rem;align-items:center;margin-top:1rem">
            @if ($user->avatar_path)
                <img src="{{ Storage::disk('public')->url($user->avatar_path) }}" alt="{{ $user->username }}'s avatar"
                     style="width:64px;height:64px;border-radius:50%;object-fit:cover">
            @endif
            <div>
                <h1 style="margin:0">{{ $user->display_name ?? $user->username }}</h1>
                <span style="color:#777">&commat;{{ $user->username }} · Trust level {{ (int) $user->trust_level }}</span>
            </div>
        </div>

        @foreach ($fields as $field)
            @php($value = $values->get($field->id)?->value)
            @if ($value)
                <p style="margin:.4rem 0"><strong>{{ $field->label }}:</strong>
                    @if ($field->type === 'url' && \Illuminate\Support\Str::startsWith($value, ['http://', 'https://']))
                        <a href="{{ $value }}" rel="nofollow noopener noreferrer">{{ $value }}</a>
                    @else
                        {{ $value }}
                    @endif
                </p>
            @endif
        @endforeach

        @if ($user->signature_html)
            <hr style="margin:1.2rem 0;border:0;border-top:1px solid var(--hearth-border, #e3e3ea)">
            <div class="hearth-prose" style="color:#555">{!! $user->signature_html !!}</div>
        @endif
    </main>
@endsection
