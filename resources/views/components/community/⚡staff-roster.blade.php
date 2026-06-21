<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\Group;
use App\Models\User;
use App\Settings\Settings;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * "The Team" public roster (ACP v3 · v3-g) — the curated public staff page at /staff. DISPLAY-ONLY: it lists
 * the ACTIVE members of every group an admin has flagged `show_on_staff_page` (seeded on Administrators +
 * Moderators), PLUS anyone holding a per-user per-forum moderator assignment, grouped by their canonical staff
 * role (Co-owners → Administrators → Moderators → Forum moderators). It NEVER reads `acl_entries`.
 *
 * Gated by `members.staff_roster_enabled`: the route AND this component 404 when the roster is off (no
 * disclosure). Self-guards in mount() AND the data method because a Livewire request reaches the component with
 * no route middleware (the v3-* SFC discipline) — although this component exposes no actions.
 *
 * Bucketing is by role, not by raw group, so co-owners separate cleanly from administrators within the admins
 * group and the global order is fixed. A flagged-group member whose staffRole() is null (e.g. an admin flagged a
 * non-staff custom group) is omitted — the four canonical buckets are what the Team page shows.
 */
new class extends Component
{
    /** Fixed display order of the staff-role buckets (matches the spec ordering). */
    private const ROLE_ORDER = ['co_owner', 'administrator', 'moderator', 'forum_moderator'];

    public function mount(Settings $settings): void
    {
        abort_unless($settings->bool('members.staff_roster_enabled'), 404);
    }

    /**
     * The roster bucketed by staff role in ROLE_ORDER, empty buckets dropped. Candidates = active members of
     * `show_on_staff_page` groups ∪ active per-user forum-moderators; each lands in exactly one bucket via
     * User::staffRole() (so the set self-dedupes). Eager-loads groups + moderatorAssignments so staffRole()
     * resolves with no per-row query.
     *
     * @return array<string, Collection<int, User>>
     */
    public function roster(): array
    {
        abort_unless(app(Settings::class)->bool('members.staff_roster_enabled'), 404);

        $flaggedIds = Group::query()->where('show_on_staff_page', true)->pluck('id');

        $candidates = User::query()
            ->where('status', 'active')
            ->where(function ($q) use ($flaggedIds): void {
                $q->whereHas('groups', fn ($g) => $g->whereIn('groups.id', $flaggedIds))
                    ->orWhereHas('moderatorAssignments');
            })
            ->with(['groups', 'moderatorAssignments'])
            ->orderBy('display_name')
            ->orderBy('username')
            ->get();

        $buckets = [];
        foreach (self::ROLE_ORDER as $role) {
            $buckets[$role] = collect();
        }
        foreach ($candidates as $user) {
            $role = $user->staffRole();
            if ($role !== null && isset($buckets[$role])) {
                $buckets[$role]->push($user);
            }
        }

        return array_filter($buckets, fn (Collection $bucket): bool => $bucket->isNotEmpty());
    }
};
?>

<div class="space-y-6" dusk="staff-roster">
    @php($roster = $this->roster())
    @if (empty($roster))
        <x-ui.card>
            <p class="text-sm text-ink-subtle">{{ __('forum.staff_empty') }}</p>
        </x-ui.card>
    @else
        @foreach ($roster as $role => $members)
            <section dusk="staff-bucket-{{ $role }}">
                <h2 class="mb-3 text-lg font-semibold text-ink">{{ __('forum.staff_heading_'.$role) }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($members as $member)
                        <x-ui.card>
                            <div class="flex items-start gap-3">
                                <a href="{{ route('profiles.show', $member) }}" class="shrink-0">
                                    <x-ui.avatar :user="$member" size="lg" />
                                </a>
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold text-ink truncate">
                                        <x-ui.user-name :user="$member" :link="true" />
                                    </p>
                                    <p class="text-xs text-ink-subtle truncate">{{ '@'.$member->username }}</p>
                                    <div class="mt-1.5 empty:hidden"><x-ui.staff-flair :user="$member" /></div>
                                </div>
                            </div>
                        </x-ui.card>
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif
</div>
