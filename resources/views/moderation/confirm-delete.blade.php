{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Delete account · '.config('app.name', 'NovFora')])

@section('content')
    <x-ui.container size="md" class="space-y-5">
        <x-ui.card class="space-y-4">
            <div>
                <h1 class="text-lg font-semibold text-ink">Delete {{ $user->display_name ?? $user->username }}'s account?</h1>
                <p class="mt-1 text-sm text-ink-subtle">
                    This permanently deletes <strong>{{ '@'.$user->username }}</strong>. Their posts and topics stay on
                    the forum but are anonymised to “[Deleted]”; their private messages, reactions, poll votes, drafts,
                    and notifications are removed. This cannot be undone.
                </p>
            </div>

            <dl class="grid grid-cols-2 gap-x-4 sm:grid-cols-3" dusk="force-delete-summary">
                @foreach ([
                    'posts' => 'Posts (anonymised)',
                    'topics' => 'Topics (anonymised)',
                    'messages' => 'Private messages',
                    'reactions' => 'Reactions given',
                    'poll_votes' => 'Poll votes',
                    'attachments' => 'Attachments',
                ] as $key => $label)
                    <div class="flex items-baseline justify-between gap-2 border-b border-line py-1 text-sm">
                        <dt class="text-ink-subtle">{{ $label }}</dt>
                        <dd class="nums font-semibold text-ink">{{ number_format((int) ($summary[$key] ?? 0)) }}</dd>
                    </div>
                @endforeach
            </dl>

            <form method="POST" action="{{ route('moderation.user-delete', $user) }}"
                  class="space-y-4 rounded-lg border border-line bg-surface-sunken p-4">
                @csrf
                @method('DELETE')
                <label class="flex items-start gap-2 text-sm text-ink">
                    <input type="checkbox" name="confirm" value="1" required class="mt-0.5 rounded border-line">
                    <span>I understand this permanently deletes this account and cannot be undone.</span>
                </label>
                <div class="flex flex-wrap items-center gap-3">
                    <x-ui.button type="submit" variant="danger">Permanently delete account</x-ui.button>
                    <x-ui.button :href="route('profiles.show', $user)" variant="subtle">Cancel</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </x-ui.container>
@endsection
