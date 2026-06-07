{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Edit profile · '.config('app.name', 'Hearth')])

@section('content')
    <x-settings.shell title="Profile">
        <p class="text-sm text-ink-muted">
            Update how you appear across {{ config('app.name', 'Hearth') }}. Your signature and custom fields
            show on your public profile and posts.
        </p>

        {{-- enctype is required for the avatar/cover file uploads. Field names and ids are unchanged so the
             controller bindings and validation bag continue to resolve. The signature is authored as Markdown
             and rendered server-side through the canonical sanitisation pipeline (signature_html). --}}
        <form method="POST" action="{{ route('settings.profile.save') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf

            <x-ui.card class="space-y-5">
                <x-ui.textarea name="signature" id="signature" label="Signature (Markdown)" rows="4"
                               maxlength="1000" class="font-mono text-sm"
                               hint="Markdown is rendered through the canonical sanitisation pipeline.">{{ old('signature', $user->signature_doc['source'] ?? '') }}</x-ui.textarea>

                @foreach ($fields as $field)
                    <x-ui.input id="field-{{ $field->key }}" name="fields[{{ $field->key }}]"
                                :label="$field->label"
                                :type="$field->type === 'url' ? 'url' : 'text'"
                                :value="$values->get($field->id)?->value" />
                @endforeach
            </x-ui.card>

            <x-ui.card class="space-y-5">
                <div class="space-y-1.5">
                    <label for="avatar" class="block text-sm font-medium text-ink">Avatar</label>
                    <input id="avatar" type="file" name="avatar" accept="image/*"
                           class="block w-full text-sm text-ink-muted file:mr-3 file:min-h-9 file:rounded-md file:border file:border-line file:bg-surface-raised file:px-3 file:text-sm file:font-medium file:text-ink hover:file:bg-surface-sunken">
                </div>
                <div class="space-y-1.5">
                    <label for="cover" class="block text-sm font-medium text-ink">Cover image</label>
                    <input id="cover" type="file" name="cover" accept="image/*"
                           class="block w-full text-sm text-ink-muted file:mr-3 file:min-h-9 file:rounded-md file:border file:border-line file:bg-surface-raised file:px-3 file:text-sm file:font-medium file:text-ink hover:file:bg-surface-sunken">
                </div>
            </x-ui.card>

            <div class="flex items-center gap-3">
                <x-ui.button type="submit">Save profile</x-ui.button>
            </div>
        </form>
    </x-settings.shell>
@endsection
