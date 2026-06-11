{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => ($user->display_name ?? $user->username).' · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Forums', 'url' => route('forums.index')],
        ['label' => $user->display_name ?? $user->username],
    ]" />
@endsection

@section('content')
    <x-ui.container size="md" class="space-y-5">
        <x-ui.card flush class="overflow-hidden">
            @if ($user->cover_path)
                <img src="{{ Storage::disk('public')->url($user->cover_path) }}" alt=""
                     class="h-32 w-full object-cover sm:h-44">
            @else
                <div class="h-20 w-full bg-surface-sunken sm:h-28" aria-hidden="true"></div>
            @endif

            <div class="px-4 pb-5 sm:px-6">
                <div class="-mt-10 flex flex-col gap-3 sm:-mt-12 sm:flex-row sm:items-end">
                    <x-ui.avatar :user="$user" size="xl" class="ring-4 ring-surface-raised" />
                    <div class="min-w-0 sm:pb-1">
                        <h1 class="text-2xl font-semibold tracking-tight text-ink"><x-ui.user-name :user="$user" /></h1>
                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink-muted">
                            <span>{{ '@'.$user->username }}</span>
                            <span class="text-ink-subtle" aria-hidden="true">·</span>
                            <x-ui.badge variant="accent">Trust level <span class="nums">{{ (int) $user->trust_level }}</span></x-ui.badge>
                        </p>
                    </div>
                </div>
            </div>
        </x-ui.card>

        @php($hasFields = $fields->contains(fn ($field) => filled($values->get($field->id)?->value)))
        @if ($hasFields)
            <x-ui.card class="space-y-3">
                <h2 class="text-sm font-semibold text-ink">About</h2>
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    @foreach ($fields as $field)
                        @php($value = $values->get($field->id)?->value)
                        @if ($value)
                            <div class="space-y-0.5">
                                <dt class="text-xs font-medium uppercase tracking-wide text-ink-subtle">{{ $field->label }}</dt>
                                <dd class="text-sm text-ink">
                                    @if ($field->type === 'url' && \Illuminate\Support\Str::startsWith($value, ['http://', 'https://']))
                                        <a href="{{ $value }}" rel="nofollow noopener noreferrer"
                                           class="text-accent hover:text-accent-hover break-words">{{ $value }}</a>
                                    @else
                                        {{ $value }}
                                    @endif
                                </dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
            </x-ui.card>
        @endif

        @if ($user->signature_html)
            <x-ui.card class="space-y-2">
                <h2 class="text-sm font-semibold text-ink">Signature</h2>
                <div class="novfora-prose text-ink-muted">{!! $user->signature_html !!}</div>
            </x-ui.card>
        @endif
    </x-ui.container>
@endsection
