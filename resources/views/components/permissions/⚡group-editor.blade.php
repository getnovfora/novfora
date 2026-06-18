<?php

// SPDX-License-Identifier: Apache-2.0

use App\Models\Club;
use App\Models\Forum;
use App\Models\Group;
use App\Models\Permission;
use App\Models\User;
use App\Permissions\GroupPermissionEditor;
use App\Permissions\Scope;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * ACP v3 · v3-c — the card-per-group permission editor (the headline "simple mode"). One component, three homes
 * on the SAME data: GLOBAL defaults (Groups → Group permissions), per-FORUM overrides (Forums → forum →
 * Permissions), and per-CLUB on the club manage screen. Each group is a card; each row is a plain-language
 * sentence with a Yes / No / Never toggle that writes the group's OWN acl_entries via GroupPermissionEditor.
 *
 * Scope is #[Locked] so the client can't retarget it past the gate. Every mutation re-asserts the gate (the
 * manage-permissions capability for global/forum, club.manage for club) AND a rank guard (you cannot edit a
 * group ranked at or above you unless you are an admin). The PermissionInspector is the test oracle.
 */
new class extends Component
{
    #[Locked]
    public string $scopeType = 'global'; // global | forum | club

    #[Locked]
    public ?int $scopeId = null;

    public string $flash = '';

    public function mount(): void
    {
        abort_unless(in_array($this->scopeType, ['global', 'forum', 'club'], true), 404);
        $this->authorizeScope();
    }

    public function scope(): Scope
    {
        return new Scope($this->scopeType, $this->scopeId);
    }

    /** Set a group's Yes/No/Never for one permission at this scope. */
    public function setState(int $groupId, string $key, string $state, GroupPermissionEditor $editor): void
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeEdit($group);     // gate + rank guard (the TARGET group's rank)
        $this->assertKeyVisible($key);     // only the keys this scope exposes may be written

        // Per-KEY escalation fence: only a full admin may grant/deny an administration capability. Without it a
        // non-admin permissions.manage holder could hand admin.access (etc.) to a group they merely outrank.
        if (in_array($key, $this->adminTierKeys(), true)) {
            abort_unless((bool) auth()->user()?->isAdmin(), 403);
        }

        // Lockout guard: never strip the admins group's own ACP-recovery keys at global scope (clean 403; the
        // service throws as an actor-independent backstop).
        if ($state !== 'yes' && $this->scopeType === 'global' && $editor->protectsAdminRecovery($group, $key)) {
            abort(403, (string) __('admin.perms.locked_recovery'));
        }

        if ($editor->set($group, $key, $this->scope(), $state)) {
            $this->flash = (string) __('admin.perms.saved');
        }
    }

    /** @return list<string> the administration-tier keys only a full admin may grant/deny (the escalation fence). */
    public function adminTierKeys(): array
    {
        return Permission::query()->where('group', 'Administration')->pluck('key')->all();
    }

    /** Forum scope only: copy this forum's group overrides onto every forum in its category (one transaction). */
    public function applyToCategory(GroupPermissionEditor $editor): void
    {
        abort_unless($this->scopeType === 'forum', 403);
        $this->authorizeScope();
        // The bulk write touches every group at once, so it requires a full admin (who outranks all groups) —
        // a non-admin permission manager cannot sweep the whole board.
        abort_unless(auth()->user()?->isAdmin(), 403);

        $count = $editor->copyForumToCategory(Forum::findOrFail($this->scopeId), $this->visibleKeys());
        $this->flash = (string) __('admin.perms.bulk_done', ['count' => $count]);
    }

    // ── view data ───────────────────────────────────────────────────────────────────────────────────────

    /** @return \Illuminate\Support\Collection<int,Group> */
    public function groups()
    {
        return Group::query()->orderByDesc('priority')->orderBy('name')->get();
    }

    /** @return \Illuminate\Support\Collection<int,Permission> */
    public function visiblePermissions()
    {
        // Global shows every key; forum/club show the forum-scoped keys (the ones that govern a forum/club).
        return Permission::query()
            ->when($this->scopeType !== 'global', fn ($q) => $q->where('scope_kind', 'forum'))
            ->orderBy('group')->orderBy('label')->get();
    }

    /** @return list<string> */
    public function visibleKeys(): array
    {
        return $this->visiblePermissions()->pluck('key')->all();
    }

    public function matrix(GroupPermissionEditor $editor): array
    {
        return $editor->matrix($this->scope());
    }

    public function inheritedMatrix(GroupPermissionEditor $editor): array
    {
        return $this->scopeType === 'global' ? [] : $editor->matrix(Scope::global());
    }

    /** Whether the current actor may edit this group (no abort — for UI disabling). */
    public function canEdit(Group $group): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }
        if ($this->scopeType === 'club') {
            return true; // club.manage already enforced; club-scoped grants don't escalate global privilege
        }

        return $user->isAdmin() || $user->rankPriority() > (int) $group->priority;
    }

    public function showBulk(GroupPermissionEditor $editor): bool
    {
        return $this->scopeType === 'forum'
            && (bool) auth()->user()?->isAdmin()
            && $editor->nearestCategory(Forum::findOrFail($this->scopeId)) !== null;
    }

    // ── guards ──────────────────────────────────────────────────────────────────────────────────────────

    private function authorizeScope(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        if ($this->scopeType === 'club') {
            abort_unless(Club::findOrFail($this->scopeId)->isManageableBy($user), 403);

            return;
        }

        // Global / forum (the admin homes): the ACP gate + staff-2FA + the manage-permissions capability.
        abort_unless($user->canDo('admin.access', Scope::global()), 403);
        abort_if($user->isStaff() && $user->two_factor_confirmed_at === null, 403);
        abort_unless($user->canDo('permissions.manage', Scope::global()), 403);
    }

    private function authorizeEdit(Group $group): void
    {
        $this->authorizeScope();
        abort_unless($this->canEdit($group), 403);
    }

    private function assertKeyVisible(string $key): void
    {
        abort_unless(in_array($key, $this->visibleKeys(), true), 422);
    }
}; ?>

<div class="space-y-4">
    @php
        $editor = app(\App\Permissions\GroupPermissionEditor::class);
        $matrix = $this->matrix($editor);
        $inherited = $this->inheritedMatrix($editor);
        $permGroups = $this->visiblePermissions()->groupBy('group');
        $states = ['yes', 'no', 'never'];
        $isAdmin = (bool) auth()->user()?->isAdmin();
        $adminTier = $this->adminTierKeys();
    @endphp

    <p class="text-sm text-ink-muted">{{ __('admin.perms.intro_'.$scopeType) }}</p>

    @if ($flash)
        <div class="rounded-md border border-accent bg-accent-soft px-3 py-2 text-sm text-accent-soft-ink" role="status" aria-live="polite">
            {{ $flash }}
        </div>
    @endif

    @if ($this->showBulk($editor))
        <div class="flex flex-wrap items-center gap-3 rounded-lg border border-line bg-surface-raised p-3">
            <button type="button" wire:click="applyToCategory" wire:confirm="{{ __('admin.perms.bulk_help') }}"
                    class="inline-flex items-center gap-1.5 min-h-11 px-3 rounded-md border border-line text-sm font-medium text-ink-muted hover:bg-surface-sunken hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                <x-ui.icon name="folder" class="h-4 w-4" /> {{ __('admin.perms.bulk_apply') }}
            </button>
            <span class="text-xs text-ink-subtle">{{ __('admin.perms.bulk_help') }}</span>
        </div>
    @endif

    @forelse ($this->groups() as $group)
        @php $editable = $this->canEdit($group); @endphp
        <section x-data="{ open: true }" class="rounded-lg border border-line bg-surface-raised">
            <h3>
                <button type="button" @click="open = ! open" :aria-expanded="open.toString()"
                        class="flex w-full items-center gap-2 px-4 py-3 text-left text-sm font-semibold text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                    <x-ui.icon name="chevron-down" class="h-4 w-4 shrink-0 transition" x-bind:class="open || 'rotate-[-90deg]'" />
                    @if ($group->color)
                        <span class="inline-block h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $group->color }}"></span>
                    @endif
                    <span class="flex-1">{{ $group->name }}</span>
                    @unless ($editable)
                        <span class="inline-flex items-center gap-1 text-xs font-normal text-ink-subtle" title="{{ __('admin.perms.locked_rank') }}">
                            <x-ui.icon name="lock" class="h-3.5 w-3.5" />
                        </span>
                    @endunless
                </button>
            </h3>

            <div x-show="open" x-collapse class="border-t border-line">
                @foreach ($permGroups as $groupLabel => $perms)
                    <p class="px-4 pt-3 pb-1 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ $groupLabel }}</p>
                    <ul class="divide-y divide-line">
                        @foreach ($perms as $perm)
                            @php
                                $state = \App\Permissions\GroupPermissionEditor::stateForValue($matrix[$group->id][$perm->key] ?? null);
                                $inheritState = $scopeType === 'global' ? null : \App\Permissions\GroupPermissionEditor::stateForValue($inherited[$group->id][$perm->key] ?? null);
                                // Mirror the server fences in the UI: a non-admin can't touch admin-tier keys, and
                                // the admins group's own recovery keys can't be set to No/Never at global scope.
                                $rowEditable = $editable && ($isAdmin || ! in_array($perm->key, $adminTier, true));
                                $isRecovery = $scopeType === 'global' && $group->slug === 'admins' && in_array($perm->key, ['admin.access', 'permissions.manage'], true);
                            @endphp
                            <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5">
                                <div class="min-w-0">
                                    <span class="text-sm text-ink">{{ $perm->label }}</span>
                                    @if ($inheritState !== null && $state === 'no')
                                        <span class="ml-1 text-xs text-ink-subtle">({{ __('admin.perms.inherits') }}: {{ __('admin.perms.state.'.$inheritState) }})</span>
                                    @endif
                                </div>
                                <div role="group" aria-label="{{ $group->name }} — {{ $perm->label }}" class="inline-flex shrink-0 overflow-hidden rounded-md border border-line">
                                    @foreach ($states as $opt)
                                        @php $btnEnabled = $rowEditable && ! ($isRecovery && $opt !== 'yes'); @endphp
                                        <button type="button"
                                                @if ($btnEnabled) wire:click="setState({{ $group->id }}, '{{ $perm->key }}', '{{ $opt }}')" wire:key="set-{{ $group->id }}-{{ $perm->key }}-{{ $opt }}" @else disabled @endif
                                                title="{{ __('admin.perms.state_help.'.$opt) }}"
                                                aria-pressed="{{ $state === $opt ? 'true' : 'false' }}"
                                                @class([
                                                    'min-h-9 px-3 text-xs font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent',
                                                    'border-l border-line' => ! $loop->first,
                                                    'bg-accent-soft text-accent-soft-ink' => $state === $opt,
                                                    'text-ink-muted hover:bg-surface-sunken hover:text-ink' => $state !== $opt && $btnEnabled,
                                                    'text-ink-subtle cursor-not-allowed' => ! $btnEnabled,
                                                ])>
                                            {{ __('admin.perms.state.'.$opt) }}
                                        </button>
                                    @endforeach
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </div>
        </section>
    @empty
        <div class="rounded-lg border border-line bg-surface-raised p-6 text-sm text-ink-muted">{{ __('admin.perms.empty_groups') }}</div>
    @endforelse
</div>
