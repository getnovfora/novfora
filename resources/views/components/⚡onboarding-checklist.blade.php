<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\Post;
use App\Models\Reaction;
use App\Models\User;
use Livewire\Component;

/**
 * ⚡onboarding-checklist (onboarding-lite, NOV-123) — a dismissible getting-started card for new members. Its
 * three items mirror the SAME signals BadgeService rewards (the account exists; an approved post; a given
 * reaction), so the checklist tracks the earn-path rather than inventing a second one. It self-gates: shown
 * only to a signed-in member who has NOT dismissed it AND has not yet completed every item (so it silently
 * disappears for established members — no deploy-day nag — and can be dismissed early).
 */
new class extends Component
{
    /** The onboarding window — the checklist is only ever computed for accounts younger than this. */
    private const WINDOW_DAYS = 30;

    public function dismiss(): void
    {
        $user = auth()->user();
        if ($user instanceof User) {
            $user->forceFill(['onboarding_dismissed_at' => now()])->save();
        }
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        $user = auth()->user();

        // Hot-path gate: only a genuinely-new, not-yet-dismissed member runs the signal queries below. Anyone
        // who dismissed, or whose account is older than the onboarding window, short-circuits on two already-
        // loaded columns and pays ZERO queries — so the home page stays cheap for the established majority.
        if (! $user instanceof User
            || $user->onboarding_dismissed_at !== null
            || ($user->created_at !== null && $user->created_at->lt(now()->subDays(self::WINDOW_DAYS)))) {
            return ['show' => false, 'items' => collect()];
        }

        $items = collect([
            [
                'key' => 'profile',
                'done' => (bool) ($user->avatar_path || filled($user->signature_doc) || $user->customFieldValues()->exists()),
                'label' => __('onboarding.item_profile'),
                'url' => route('settings.profile'),
            ],
            [
                'key' => 'post',
                'done' => Post::where('user_id', $user->getKey())->where('approved_state', 'approved')->exists(),
                'label' => __('onboarding.item_post'),
                'url' => route('forums.index'),
            ],
            [
                'key' => 'react',
                'done' => Reaction::where('user_id', $user->getKey())->exists(),
                'label' => __('onboarding.item_react'),
                'url' => route('forums.index'),
            ],
        ]);

        // Dismissal + window were already ruled out above, so the only remaining gate is "not yet finished".
        return [
            'show' => ! $items->every(fn ($i) => $i['done']),
            'items' => $items,
        ];
    }
}; ?>

<div>
    @if ($show)
        <x-ui.card class="mb-4" dusk="onboarding-checklist">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-ink">{{ __('onboarding.title') }}</h2>
                    <p class="mt-0.5 text-sm text-ink-muted">{{ __('onboarding.intro') }}</p>
                </div>
                <button type="button" wire:click="dismiss" class="shrink-0 text-ink-subtle hover:text-ink"
                        aria-label="{{ __('onboarding.dismiss') }}" dusk="onboarding-dismiss">
                    <x-ui.icon name="close" class="h-4 w-4" />
                </button>
            </div>
            <ul class="mt-3 space-y-2">
                @foreach ($items as $item)
                    <li class="flex items-center gap-2 text-sm">
                        @if ($item['done'])
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-soft-ink">
                                <x-ui.icon name="check" class="h-3.5 w-3.5" />
                            </span>
                            <span class="text-ink-subtle line-through">{{ $item['label'] }}</span>
                        @else
                            <span class="h-5 w-5 shrink-0 rounded-full border border-line"></span>
                            <a href="{{ $item['url'] }}" class="text-accent hover:underline">{{ $item['label'] }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
