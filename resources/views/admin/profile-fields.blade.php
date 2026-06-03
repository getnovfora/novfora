{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Custom profile fields · '.config('app.name', 'Hearth')])

@section('content')
    <main style="max-width:40rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        <h1>Custom profile fields</h1>
        @if (session('status'))
            <p style="color:#1a7a1a">{{ session('status') }}</p>
        @endif

        @forelse ($fields as $field)
            <div style="display:flex;justify-content:space-between;align-items:center;padding:.5rem 0;border-bottom:1px solid #f0f0f3">
                <span><strong>{{ $field->label }}</strong> <span style="color:#999">({{ $field->key }} · {{ $field->type }})</span></span>
                <form method="POST" action="{{ route('admin.system.profile-fields.destroy', $field) }}">@csrf @method('DELETE')
                    <button style="padding:.25rem .6rem;border:1px solid #d99;border-radius:6px;background:#fff;color:#b00020;cursor:pointer;font-size:.8rem">Delete</button></form>
            </div>
        @empty
            <p style="color:#999">No custom fields yet.</p>
        @endforelse

        <form method="POST" action="{{ route('admin.system.profile-fields.store') }}" style="margin-top:1.5rem;display:flex;flex-direction:column;gap:.5rem">
            @csrf
            <h2 style="font-size:1.05rem">Add a field</h2>
            @error('key') <span style="color:#b00020">{{ $message }}</span> @enderror
            <input type="text" name="label" placeholder="Label (e.g. Location)" required style="padding:.5rem;border:1px solid #bbb;border-radius:6px">
            <input type="text" name="key" placeholder="key (e.g. location)" required style="padding:.5rem;border:1px solid #bbb;border-radius:6px">
            <select name="type" style="padding:.5rem;border:1px solid #bbb;border-radius:6px">
                <option value="text">Text</option>
                <option value="url">URL</option>
                <option value="textarea">Text area</option>
            </select>
            <button type="submit" style="padding:.5rem 1rem;border:0;border-radius:6px;background:#2d2a6b;color:#fff;cursor:pointer;align-self:flex-start">Add field</button>
        </form>
    </main>
@endsection
