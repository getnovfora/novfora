{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Custom profile fields · '.config('app.name', 'Hearth')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Admin'],
        ['label' => 'System'],
        ['label' => 'Custom profile fields'],
    ]" />
@endsection

@section('content')
    <x-admin.shell title="Custom profile fields"
                   description="Extra fields members can fill in on their profile. Add, review, or remove them here.">
        {{-- Existing fields: a flush card whose rows reflow to stacked at mobile. --}}
        <x-ui.card flush>
            <div class="divide-y divide-line">
                @forelse ($fields as $field)
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-5">
                        <div class="min-w-0">
                            <p class="font-medium text-ink truncate">{{ $field->label }}</p>
                            <p class="text-xs text-ink-subtle">
                                <code class="font-mono">{{ $field->key }}</code>
                                <span aria-hidden="true">·</span>
                                {{ $field->type }}
                            </p>
                        </div>
                        <form method="POST" action="{{ route('admin.system.profile-fields.destroy', $field) }}">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" variant="danger-ghost" size="sm">Delete</x-ui.button>
                        </form>
                    </div>
                @empty
                    <x-ui.empty title="No custom fields yet" :icon="'<svg viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'1.75\' stroke-linecap=\'round\' stroke-linejoin=\'round\' class=\'h-6 w-6\'><circle cx=\'12\' cy=\'8\' r=\'4\'/><path d=\'M4 21a8 8 0 0 1 16 0\'/></svg>'">
                        Add a field below to collect extra details on member profiles.
                    </x-ui.empty>
                @endforelse
            </div>
        </x-ui.card>

        {{-- Add a field --}}
        <x-ui.card>
            <form method="POST" action="{{ route('admin.system.profile-fields.store') }}" class="space-y-4">
                @csrf
                <h2 class="text-lg font-semibold text-ink">Add a field</h2>

                <x-ui.input name="label" label="Label" placeholder="e.g. Location" required />
                <x-ui.input name="key" label="Key" placeholder="e.g. location" hint="Lowercase identifier used in the database." required />
                <x-ui.select name="type" label="Type">
                    <option value="text">Text</option>
                    <option value="url">URL</option>
                    <option value="textarea">Text area</option>
                </x-ui.select>

                <x-ui.button type="submit">Add field</x-ui.button>
            </form>
        </x-ui.card>
    </x-admin.shell>
@endsection
