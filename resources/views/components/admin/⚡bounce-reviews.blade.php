<?php
// SPDX-License-Identifier: Apache-2.0
use App\Deliverability\Suppressor;
use App\Models\BounceReview;
use App\Models\User;
use App\Permissions\Scope;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin → System → Email · non-VERP bounce review queue (P2-M2, spike-p2-memo §2b / §8). Lists bounces a
 * polled mailbox could NOT cryptographically authenticate (no VERP), so they were NOT auto-suppressed. The
 * candidate address is UNVERIFIED — a staff member eyeballs the excerpt and either suppresses it by hand
 * (the authentication) or dismisses it. Self-guards like the other admin SFCs.
 */
new class extends Component
{
    use WithPagination;

    public ?string $flash = null;

    public string $flashVariant = 'success';

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function suppress(int $id): void
    {
        $this->ensureAdmin();

        $review = BounceReview::where('id', $id)->where('status', BounceReview::PENDING)->first();
        if (! $review instanceof BounceReview) {
            return;
        }

        // Staff review IS the authentication for a non-VERP bounce. Resolve inside the action (never via
        // method-injection — the ACP v1.1 Livewire footgun).
        app(Suppressor::class)->suppress($review->candidate_email, $review->event_type);
        $review->forceFill([
            'status' => BounceReview::RESOLVED,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ])->save();

        $this->resetPage();
        $this->flash = "Suppressed {$review->candidate_email}.";
        $this->flashVariant = 'success';
    }

    public function dismiss(int $id): void
    {
        $this->ensureAdmin();

        $review = BounceReview::where('id', $id)->where('status', BounceReview::PENDING)->first();
        if (! $review instanceof BounceReview) {
            return;
        }

        $review->forceFill([
            'status' => BounceReview::DISMISSED,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ])->save();

        $this->resetPage();
        $this->flash = 'Dismissed — the address can still receive mail.';
        $this->flashVariant = 'info';
    }

    public function pending()
    {
        $this->ensureAdmin();

        return BounceReview::query()->where('status', BounceReview::PENDING)->latest('id')->paginate(10);
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('admin.access', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-4">
    @php($reviews = $this->pending())

    <div>
        <h2 class="text-sm font-semibold text-ink">Bounces awaiting review</h2>
        <p class="mt-1 text-sm text-ink-muted max-w-2xl">
            These bounced/complained from a polled mailbox with <strong>no VERP</strong> configured, so the
            address could not be cryptographically verified and was <strong>not</strong> auto-suppressed. Review
            the excerpt and suppress by hand only if you trust it — these addresses are <strong>unverified</strong>.
        </p>
    </div>

    @if ($flash)
        <x-ui.alert :variant="$flashVariant">{{ $flash }}</x-ui.alert>
    @endif

    <x-ui.card flush>
        <div class="divide-y divide-line">
            @forelse ($reviews as $review)
                <div class="px-4 py-3 sm:px-5 text-sm space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-ink break-all font-medium">{{ $review->candidate_email }}</span>
                        <x-ui.badge :variant="$review->event_type === 'complaint' ? 'danger' : 'warn'">{{ $review->event_type }}</x-ui.badge>
                        <x-ui.badge variant="neutral">unverified</x-ui.badge>
                        <time class="text-ink-subtle ml-auto" datetime="{{ optional($review->created_at)->toIso8601String() }}">
                            {{ optional($review->created_at)->diffForHumans() }}
                        </time>
                    </div>
                    @if ($review->excerpt)
                        <pre class="max-h-32 overflow-auto rounded bg-surface-sunken p-2 text-xs text-ink-muted whitespace-pre-wrap break-words">{{ \Illuminate\Support\Str::limit($review->excerpt, 400) }}</pre>
                    @endif
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="button" size="sm"
                                     wire:click="suppress({{ $review->id }})"
                                     wire:confirm="Suppress {{ $review->candidate_email }}? It will be skipped on future sends.">Suppress</x-ui.button>
                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="dismiss({{ $review->id }})">Dismiss</x-ui.button>
                    </div>
                </div>
            @empty
                <p class="px-4 py-6 text-center text-sm text-ink-subtle sm:px-5">No bounces are awaiting review.</p>
            @endforelse
        </div>
    </x-ui.card>

    <div>{{ $reviews->links() }}</div>
</div>
