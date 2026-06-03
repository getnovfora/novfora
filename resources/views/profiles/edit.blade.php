{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Edit profile · '.config('app.name', 'Hearth')])

@section('content')
    <main style="max-width:36rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        <h1>Edit profile</h1>
        @if (session('status'))
            <p style="color:#1a7a1a">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('settings.profile.save') }}" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:.7rem">
            @csrf

            <label for="signature">Signature (Markdown)</label>
            <textarea id="signature" name="signature" rows="4" maxlength="1000"
                      style="padding:.55rem;border:1px solid #bbb;border-radius:6px;font-family:ui-monospace,monospace">{{ old('signature', $user->signature_doc['source'] ?? '') }}</textarea>
            @error('signature') <span style="color:#b00020">{{ $message }}</span> @enderror

            @foreach ($fields as $field)
                <label for="field-{{ $field->key }}">{{ $field->label }}</label>
                <input id="field-{{ $field->key }}" type="{{ $field->type === 'url' ? 'url' : 'text' }}"
                       name="fields[{{ $field->key }}]" value="{{ $values->get($field->id)?->value }}"
                       style="padding:.5rem;border:1px solid #bbb;border-radius:6px">
            @endforeach

            <label for="avatar">Avatar</label>
            <input id="avatar" type="file" name="avatar" accept="image/*">
            <label for="cover">Cover image</label>
            <input id="cover" type="file" name="cover" accept="image/*">

            <button type="submit" style="margin-top:.5rem;padding:.55rem 1.1rem;border:0;border-radius:6px;background:#2d2a6b;color:#fff;cursor:pointer">Save profile</button>
        </form>
    </main>
@endsection
