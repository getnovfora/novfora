<?php

// SPDX-License-Identifier: Apache-2.0

use App\Models\Club;
use App\Models\Group;
use App\Models\User;
use App\Permissions\CapabilityMap;
use App\Permissions\GroupPermissionEditor;
use App\Permissions\PermissionValue;
use App\Permissions\Scope;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Simple-mode permissions (ADR-0089) — a layman capability-toggle WRITE surface over the SAME data + write
 * primitive as the card editor (⚡group-editor). Per group, one plain-language switch per capability that is
 * applicable at the current scope (CapabilityMap::for). Toggling writes the whole bundle via
 * GroupPermissionEditor::set (ON → 'yes', OFF → 'no'/inherit — NEVER 'never') in ONE transaction + ONE audit
 * entry. NOT an engine change: the resolver/catalog/GroupPermissionEditor are untouched.
 *
 * Scope is #[Locked] (the client can't retarget past the gate). Every mutation re-asserts the gate
 * (manage-permissions for global/forum, club.manage for club) + the rank guard — identical to the card editor.
 * Two correctness fences (ADR-0089): a capability is only writable where EVERY key is settable at the scope
 * (no silently-inert row); and a capability carrying a hard NEVER (a trust gate) is LOCKED — simple mode never
 * lifts a NEVER (the card editor is the only place to change one).
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

    /** The capabilities applicable at this scope (every key settable here). @return list<string> */
    public function capabilities(): array
    {
        return CapabilityMap::for($this->scopeType);
    }

    /** Turn a whole capability on/off for a group at this scope — one transaction, one audit entry. */
    public function setCapability(int $groupId, string $capability, bool $enabled, GroupPermissionEditor $editor): void
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeEdit($group);            // gate + rank guard (the TARGET group's rank)

        // Only a capability applicable at THIS scope may be written — never a silently-inert / out-of-scope row.
        abort_unless(in_array($capability, $this->capabilities(), true), 422);

        $keys = CapabilityMap::keys($capability);

        // Backstop: simple mode must never lift a hard NEVER (a trust gate). Refuse if the group carries a NEVER
        // for any key in the bundle at this scope or at global (the UI locks the toggle; this guards a crafted
        // Livewire call). The card editor is the only place to change a NEVER.
        $matrix = $editor->matrix($this->scope());
        $globalMatrix = $this->scopeType === 'global' ? $matrix : $editor->matrix(Scope::global());
        abort_if($this->restricted($groupId, $keys, $matrix, $globalMatrix), 403, (string) __('admin.perms.restricted_note'));

        $state = $enabled ? 'yes' : 'no';
        $changed = DB::transaction(function () use ($group, $keys, $state, $editor): bool {
            $any = false;
            foreach ($keys as $key) {
                // audit:false — the bundle audits ONCE below (one operator action = one log line).
                $any = $editor->set($group, $key, $this->scope(), $state, audit: false) || $any;
            }

            return $any;
        });

        if ($changed) {
            Audit::log('acl.group.capability_set', $group, [
                'capability' => $capability,
                'scope' => $this->scope()->key(),
                'enabled' => $enabled,
                'keys' => $keys,
            ]);
            $this->flash = (string) __('admin.perms.saved');
        }
    }

    // ── view data ─────────────────────────────────────────────────────────────────────────────────────────

    /** @return \Illuminate\Support\Collection<int,Group> */
    public function groups()
    {
        return Group::query()->orderByDesc('priority')->orderBy('name')->get();
    }

    /** A capability is "on" iff every key is an explicit ALLOW for the group at this scope (clean round-trip). */
    public function isOn(int $groupId, array $keys, array $matrix): bool
    {
        foreach ($keys as $key) {
            if (($matrix[$groupId][$key] ?? null) !== PermissionValue::Allow->value) {
                return false;
            }
        }

        return true;
    }

    /** Restricted = a hard NEVER (this scope, or global for a sub-scope) blocks a key — the trust-gate lock. */
    public function restricted(int $groupId, array $keys, array $matrix, array $globalMatrix): bool
    {
        foreach ($keys as $key) {
            if (($matrix[$groupId][$key] ?? null) === PermissionValue::Never->value
                || ($globalMatrix[$groupId][$key] ?? null) === PermissionValue::Never->value) {
                return true;
            }
        }

        return false;
    }

    /** Whether the current actor may edit this group (rank guard; club.manage already enforced at club scope). */
    public function canEdit(Group $group): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }
        if ($this->scopeType === 'club') {
            return true;
        }

        return $user->isAdmin() || $user->rankPriority() > (int) $group->priority;
    }

    // ── guards (identical to the card editor) ─────────────────────────────────────────────────────────────

    private function authorizeScope(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        if ($this->scopeType === 'club') {
            abort_unless(Club::findOrFail($this->scopeId)->isManageableBy($user), 403);

            return;
        }

        abort_unless($user->canDo('admin.access', Scope::global()), 403);
        abort_if($user->isStaff() && $user->two_factor_confirmed_at === null, 403);
        abort_unless($user->canDo('permissions.manage', Scope::global()), 403);
    }

    private function authorizeEdit(Group $group): void
    {
        $this->authorizeScope();
        abort_unless($this->canEdit($group), 403);
    }
}; ?>

<div class="space-y-4">
    @php
        $editor = app(\App\Permissions\GroupPermissionEditor::class);
        $matrix = $editor->matrix($this->scope());
        $globalMatrix = $scopeType === 'global' ? $matrix : $editor->matrix(\App\Permissions\Scope::global());
        $caps = $this->capabilities();
    @endphp

    <p class="text-sm text-ink-muted">{{ __('admin.perms.simple_intro') }}</p>

    @if ($flash)
        <div class="rounded-md border border-accent bg-accent-soft px-3 py-2 text-sm text-accent-soft-ink" role="status" aria-live="polite">
            {{ $flash }}
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
                <ul class="divide-y divide-line">
                    @foreach ($caps as $cap)
                        @php
                            $keys = \App\Permissions\CapabilityMap::keys($cap);
                            $on = $this->isOn($group->id, $keys, $matrix);
                            $restricted = $this->restricted($group->id, $keys, $matrix, $globalMatrix);
                            $toggleable = $editable && ! $restricted;
                        @endphp
                        <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-ink">{{ __('admin.perms.capabilities.'.$cap.'.label') }}</p>
                                <p class="text-xs text-ink-subtle">{{ __('admin.perms.capabilities.'.$cap.'.subtitle') }}</p>
                                @if ($restricted)
                                    <p class="mt-1 inline-flex items-center gap-1 text-xs text-warn-ink" dusk="cap-restricted-{{ $group->id }}-{{ $cap }}">
                                        <x-ui.icon name="lock" class="h-3 w-3" /> {{ __('admin.perms.restricted_note') }}
                                    </p>
                                @endif
                            </div>
                            <button type="button"
                                    @if ($toggleable) wire:click="setCapability({{ $group->id }}, '{{ $cap }}', {{ $on ? 'false' : 'true' }})" wire:key="cap-{{ $group->id }}-{{ $cap }}" @else disabled @endif
                                    role="switch" aria-checked="{{ $on ? 'true' : 'false' }}"
                                    aria-label="{{ $group->name }} — {{ __('admin.perms.capabilities.'.$cap.'.label') }}"
                                    dusk="cap-toggle-{{ $group->id }}-{{ $cap }}"
                                    @class([
                                        'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface',
                                        'bg-accent' => $on && $toggleable,
                                        'bg-accent-soft' => $on && ! $toggleable,
                                        'bg-line-strong' => ! $on && $toggleable,
                                        'bg-surface-sunken' => ! $on && ! $toggleable,
                                        'cursor-not-allowed opacity-70' => ! $toggleable,
                                    ])>
                                <span @class([
                                    'inline-block h-5 w-5 transform rounded-full bg-white shadow-sm transition-transform',
                                    'translate-x-5' => $on,
                                    'translate-x-0.5' => ! $on,
                                ])></span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @empty
        <div class="rounded-lg border border-line bg-surface-raised p-6 text-sm text-ink-muted">{{ __('admin.perms.empty_groups') }}</div>
    @endforelse
</div>
