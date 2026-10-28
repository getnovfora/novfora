{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Custom topic fields · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Admin'],
        ['label' => __('admin.sections.forums')],
        ['label' => __('admin.nav.topic_fields')],
    ]" />
@endsection

@section('content')
    <x-admin.shell title="Custom topic fields"
                   description="Extra fields authors fill in when starting a topic. Scope a field to one forum or to all, and mark it required if it must be answered.">
        {{-- Existing fields --}}
        <x-ui.card flush>
            <div class="divide-y divide-line">
                @forelse ($fields as $field)
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-5">
                        <div class="min-w-0">
                            <p class="font-medium text-ink truncate">
                                {{ $field->label }}
                                @if ($field->is_required)
                                    <x-ui.badge variant="neutral">required</x-ui.badge>
                                @endif
                            </p>
                            <p class="text-xs text-ink-subtle">
                                <code class="font-mono">{{ $field->key }}</code>
                                <span aria-hidden="true">·</span> {{ $field->type }}
                                <span aria-hidden="true">·</span> {{ $field->forum?->title ?? 'All forums' }}
                            </p>
                        </div>
                        <form method="POST" action="{{ route('admin.topic-fields.destroy', $field) }}"
                              onsubmit="return confirm('Delete “{{ $field->label }}” and its values on every topic?')">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" variant="danger-ghost" size="sm">Delete</x-ui.button>
                        </form>
                    </div>
                @empty
                    <x-ui.empty title="No topic fields yet" :icon="'<svg viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'1.75\' stroke-linecap=\'round\' stroke-linejoin=\'round\' class=\'h-6 w-6\'><path d=\'M4 7h16M4 12h16M4 17h10\'/></svg>'">
                        Add a field below to collect extra details when authors post.
                    </x-ui.empty>
                @endforelse
            </div>
        </x-ui.card>

        {{-- Add a field --}}
        <x-ui.card>
            <form method="POST" action="{{ route('admin.topic-fields.store') }}" class="space-y-4">
                @csrf
                <h2 class="text-lg font-semibold text-ink">Add a field</h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="label" label="Label" placeholder="e.g. Version affected" required />
                    <x-ui.input name="key" label="Key" placeholder="e.g. version" hint="Lowercase identifier used in the database." required />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select name="type" label="Type">
                        <option value="text">Text</option>
                        <option value="url">URL</option>
                        <option value="textarea">Text area</option>
                        <option value="select">Select (choices)</option>
                    </x-ui.select>
                    <x-ui.select name="forum_id" label="Applies to">
                        <option value="">All forums (global)</option>
                        @foreach ($forums as $forum)
                            <option value="{{ $forum->id }}">{{ $forum->title }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
                <x-ui.textarea name="options" label="Choices (for Select)" rows="3"
                               hint="One choice per line. Ignored for non-select fields." />
                <div class="flex flex-wrap items-center gap-4">
                    <x-ui.input name="position" label="Position" type="number" min="0" value="0" class="w-28" />
                    <label class="flex items-center gap-2 text-sm text-ink">
                        <input type="checkbox" name="is_required" value="1" class="rounded border-line text-accent focus:ring-accent">
                        Required
                    </label>
                </div>

                <x-ui.button type="submit">Add field</x-ui.button>
            </form>
        </x-ui.card>
    </x-admin.shell>
@endsection
