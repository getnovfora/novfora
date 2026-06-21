{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => ($user->display_name ?? $user->username).' · '.config('app.name', 'NovFora')])

@push('head')
    {{-- RSS/Atom auto-discovery (discovery 3.2): this member's recent topics. --}}
    <link rel="alternate" type="application/atom+xml" title="{{ $user->display_name ?? $user->username }} — topics" href="{{ route('feeds.user', $user) }}">
@endpush

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => __('common.forums'), 'url' => route('forums.index')],
        ['label' => $user->display_name ?? $user->username],
    ]" />
@endsection

@section('content')
    <x-ui.container size="md" class="space-y-5">
        {{-- Theme Studio 1.3: configurable region — admin-placed widgets at the top of a profile. --}}
        <x-region name="profile_top" />
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
                            {{-- Live staff flair (ACP v3 · v3-g) — gated by members.staff_flair_show_badge; nothing for a non-staff member. --}}
                            <x-ui.staff-flair :user="$user" />
                            <span class="text-ink-subtle" aria-hidden="true">·</span>
                            <x-ui.badge variant="accent">{{ __('profiles.trust_level') }} <span class="nums">{{ (int) $user->trust_level }}</span></x-ui.badge>
                            <x-ui.badge variant="neutral" dusk="reputation-points"><span class="nums">{{ (int) $user->reputation_points }}</span>&nbsp;{{ __('profiles.reputation') }}</x-ui.badge>
                        </p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <livewire:community.follow-button :user-id="$user->id" />
                    {{-- Member tool 2.2: ignore/block this member. --}}
                    <livewire:community.ignore-button :user-id="$user->id" />
                </div>

                @php($earned = $user->badges()->orderBy('name')->get())
                @if ($earned->isNotEmpty())
                    <div class="mt-4 flex flex-wrap items-center gap-1.5" dusk="profile-badges" aria-label="{{ __('profiles.badges') }}">
                        @foreach ($earned as $badge)
                            {{-- cssVar palette-validates the token (defence in depth — never interpolate a raw DB value into CSS). --}}
                            @php($badgeColor = \App\Support\GroupColor::cssVar($badge->color_token))
                            <span dusk="profile-badge-{{ $badge->slug }}"
                                  class="inline-flex items-center gap-1 rounded-full border border-line px-2 py-0.5 text-xs font-medium"
                                  @if ($badgeColor) style="color: {{ $badgeColor }};" @endif
                                  title="{{ $badge->description }}">
                                @if ($badge->icon_token)<x-ui.icon :name="$badge->icon_token" class="h-3.5 w-3.5" />@endif
                                {{ $badge->name }}
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-ui.card>

        {{-- BUG-017: Activity / Posts / About tabs (query-param driven, server-rendered for SEO). The About tab
             holds the custom-field card + signature that previously sat inline under the hero. --}}
        @php($tabs = [
            ['label' => __('profiles.tab_activity'), 'url' => route('profiles.show', [$user, 'tab' => 'activity']), 'active' => $tab === 'activity'],
            ['label' => __('profiles.tab_posts'), 'url' => route('profiles.show', [$user, 'tab' => 'posts']), 'active' => $tab === 'posts'],
            ['label' => __('profiles.tab_about'), 'url' => route('profiles.show', $user), 'active' => $tab === 'about'],
        ])
        <x-ui.tabs :items="$tabs" dusk="profile-tabs" />

        @if ($tab === 'activity')
            <x-ui.card flush dusk="profile-activity">
                @forelse ($activity as $item)
                    @php($phrase = match ($item->verb) {
                        \App\Models\Activity::VERB_TOPIC_CREATED => __('started a topic'),
                        \App\Models\Activity::VERB_POST_CREATED => __('replied in'),
                        \App\Models\Activity::VERB_REACT_GIVEN => __('reacted to a post in'),
                        default => __('posted in'),
                    })
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1 border-b border-line px-4 py-3 text-sm last:border-0">
                        <span class="text-ink-subtle">{{ $phrase }}</span>
                        @if ($item->isMissing())
                            <span class="italic text-ink-subtle">{{ __('a removed item') }}</span>
                        @else
                            <a href="{{ $item->url() }}" class="font-medium text-accent hover:text-accent-hover break-words">{{ $item->title() }}</a>
                        @endif
                        @if ($item->createdAt)
                            <span class="nums ml-auto text-xs text-ink-subtle" title="{{ $item->createdAt->toDateTimeString() }}">{{ $item->createdAt->diffForHumans() }}</span>
                        @endif
                    </div>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-ink-subtle">{{ __('profiles.no_activity') }}</p>
                @endforelse
            </x-ui.card>
        @elseif ($tab === 'posts')
            <x-ui.card flush dusk="profile-posts">
                @forelse ($posts as $post)
                    <div class="space-y-1 border-b border-line px-4 py-3 last:border-0">
                        <a href="{{ route('topics.show', $post->topic).'#post-'.$post->id }}" class="font-medium text-ink hover:text-accent break-words">{{ $post->topic?->title }}</a>
                        @if (filled($post->body_text))
                            <p class="text-sm text-ink-muted">{{ \Illuminate\Support\Str::limit((string) $post->body_text, 200) }}</p>
                        @endif
                        @if ($post->created_at)
                            <p class="nums text-xs text-ink-subtle">{{ $post->created_at->diffForHumans() }}</p>
                        @endif
                    </div>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-ink-subtle">{{ __('profiles.no_posts') }}</p>
                @endforelse
            </x-ui.card>
        @else
            @php($hasFields = $fields->contains(fn ($field) => filled($values->get($field->id)?->value)))
            @if ($hasFields)
                <x-ui.card class="space-y-3" dusk="profile-about">
                    <h2 class="text-sm font-semibold text-ink">{{ __('profiles.about') }}</h2>
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
                    <h2 class="text-sm font-semibold text-ink">{{ __('profiles.signature') }}</h2>
                    <div class="novfora-prose text-ink-muted">{!! $user->signature_html !!}</div>
                </x-ui.card>
            @endif

            @unless ($hasFields || $user->signature_html)
                <p class="px-1 py-6 text-center text-sm text-ink-subtle" dusk="profile-about">{{ __('profiles.no_about') }}</p>
            @endunless
        @endif

        @php($viewer = auth()->user())
        {{-- BUG-018: staff tools are gated AND now de-emphasised — a collapsed <details> below the tabs, not a
             red "Delete account" button front-and-centre under the hero. The permission gate + confirmation
             page are unchanged. --}}
        @if ($viewer instanceof \App\Models\User && \App\Account\AccountDeletionService::canForceDelete($viewer, $user))
            <details class="rounded-lg border border-line bg-surface-raised" dusk="staff-tools">
                <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-ink">{{ __('profiles.staff_tools') }}</summary>
                <div class="space-y-3 border-t border-line px-4 py-4">
                    <p class="text-sm text-ink-subtle">{{ __('profiles.staff_tools_intro') }}</p>
                    <div class="flex flex-wrap gap-3">
                        <x-ui.button :href="route('moderation.user-delete.confirm', $user)" variant="danger" size="sm" dusk="staff-delete-account">
                            {{ __('profiles.delete_account') }}
                        </x-ui.button>
                    </div>
                </div>
            </details>
        @endif

        {{-- Private staff-only notes (A1). Gated by the same authority the SFC re-asserts in mount() and every
             action — never rendered for the subject or a non-staff viewer. --}}
        @if (\App\Moderation\StaffNotes::visibleTo($viewer instanceof \App\Models\User ? $viewer : null, $user))
            <livewire:moderation.staff-notes :subject-id="$user->id" />
        @endif
    </x-ui.container>
@endsection
