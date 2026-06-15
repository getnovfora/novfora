<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme\Widgets;

use App\Models\User;
use App\Presence\OnlineMembers;
use App\Theme\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * "Who's online" — members whose `last_active_at` falls inside a recent window. BASELINE-SAFE: it reads the
 * existing last-active timestamp (no WebSocket presence required) and is cached for a minute so it never adds
 * a query to every render. Names are escaped and link to the public profile.
 *
 * Phase 4 · M4.3: it resolves through {@see OnlineMembers} so the presence OPT-IN (show_online_status,
 * default false) is enforced here as on every other surface — a member who has not opted in never appears.
 */
final class OnlineUsersWidget extends Widget
{
    public function key(): string
    {
        return 'online_users';
    }

    public function name(): string
    {
        return "Who's online";
    }

    /** @return list<array{key:string,label:string,type:string,default?:mixed}> */
    public function fields(): array
    {
        return [
            ['key' => 'minutes', 'label' => 'Active within (minutes)', 'type' => 'number', 'default' => 15],
        ];
    }

    /** @param array<string,mixed> $settings */
    public function render(array $settings): string
    {
        $minutes = max(1, min(1440, (int) ($settings['minutes'] ?? 15)));

        /** @var Collection<int,User> $users */
        $users = Cache::remember('novfora:widget:online:'.$minutes, now()->addMinute(), function () use ($minutes) {
            return app(OnlineMembers::class)->recent(30, $minutes);
        });

        $body = $users->isEmpty()
            ? '<p class="text-sm text-ink-subtle">'.e(__('No one online right now.')).'</p>'
            : '<ul class="flex flex-wrap gap-x-2 gap-y-1 text-sm">'.$users->map(fn (User $u) => '<li>'
                .'<a class="text-ink hover:text-accent" href="'.e(route('profiles.show', $u)).'">'.e((string) $u->username).'</a>'
                .'</li>')->implode('').'</ul>';

        return '<div class="rounded-lg border border-line bg-surface-raised p-4">'
            .'<h3 class="mb-2 text-sm font-semibold text-ink">'.e(__('Who’s online')).' <span class="text-ink-subtle">('.e((string) $users->count()).')</span></h3>'
            .$body.'</div>';
    }
}
