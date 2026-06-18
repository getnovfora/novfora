<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Permissions\PermissionValue;
use App\Permissions\RoleException;
use App\Permissions\RoleManager;
use App\Permissions\Scope;
use Livewire\Component;

/**
 * ACP v3 · v3-d — the custom role builder (Groups → Roles). Create / edit / delete `type=custom` roles as a
 * name + three-state values (Yes = ALLOW / No = unset / Never = NEVER) over the permission catalog, grouped into
 * clusters by the catalog `group` field. A built role is applyable as a CUSTOM group's permission baseline,
 * expanding into that group's acl_entries via RoleManager → RoleExpander; editing a role CONVERGES on every
 * assigned holder. System presets (admin / mod / member / guest) are READ-ONLY.
 *
 * All correctness lives in RoleManager (the escalation fence, the actor ceiling, the self-lockout guard, the
 * convergence). Like every admin SFC, authorization is re-asserted in mount() AND every action (Livewire actions
 * reach the component via livewire/update with no route middleware); the admin-tier fence is also pre-checked
 * here for a clean 403, with the service throwing as the actor-independent backstop.
 */
new class extends Component
{
    public bool $showForm = false;

    public ?int $formId = null; // null = creating a new custom role

    public bool $editingPreset = false; // a read-only system preset is open for viewing

    public string $name = '';

    public string $description = '';

    /** @var array<string,string> permission key => 'yes'|'no'|'never' (flat — keys carry dots, so NOT wire:model'd). */
    public array $values = [];

    public ?int $assignGroupId = null; // the group chosen in a role's inline "assign" control

    public ?int $assignForRoleId = null; // which role's assign control is open

    public ?int $deleteId = null;

    public ?string $message = null;

    public string $messageVariant = 'info';

    public function mount(): void
    {
        $this->ensureManager();
        $this->values = $this->blankValues();
    }

    public function newRole(): void
    {
        $this->ensureManager();
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->ensureManager();
        $role = Role::findOrFail($id);
        $this->formId = (int) $role->id;
        $this->name = (string) $role->name;
        $this->description = (string) ($role->description ?? '');
        $this->editingPreset = (bool) $role->is_preset;
        $this->values = $this->blankValues();
        foreach (app(RoleManager::class)->valueMap($role) as $key => $value) {
            $this->values[$key] = match ((int) $value) {
                PermissionValue::Allow->value => 'yes',
                PermissionValue::Never->value => 'never',
                default => 'no',
            };
        }
        $this->deleteId = null;
        $this->assignForRoleId = null;
        $this->showForm = true;
    }

    /** Set one permission's three-state value (key is an ARG, not a wire:model path — permission keys carry dots). */
    public function setValue(string $key, string $state): void
    {
        $this->ensureManager();
        if (! in_array($state, ['yes', 'no', 'never'], true) || ! in_array($key, $this->catalogKeys(), true)) {
            return;
        }
        // Admin-tier fence (UI + crafted-request defense): only a full admin may touch an Administration-cluster key.
        if (in_array($key, $this->adminTierKeys(), true) && ! (bool) auth()->user()?->isAdmin()) {
            abort(403);
        }
        $this->values[$key] = $state;
    }

    public function save(RoleManager $manager): void
    {
        $this->ensureManager();
        if ($this->editingPreset) {
            abort(403); // a system preset is read-only
        }
        $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        // Admin-tier fence, pre-checked for a clean 403 (the service re-checks + enforces the ceiling/lockout).
        if (! $user->isAdmin()) {
            foreach ($this->values as $key => $state) {
                if ($state !== 'no' && in_array($key, $this->adminTierKeys(), true)) {
                    abort(403);
                }
            }
        }

        try {
            $role = $this->formId !== null ? Role::findOrFail($this->formId) : null;
            $saved = $manager->save($role, $this->name, $this->values, $user, $this->description);
            $this->flash("Saved role “{$saved->name}”.", 'success');
            $this->cancelForm();
        } catch (RoleException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    public function openAssign(int $roleId): void
    {
        $this->ensureManager();
        $this->assignForRoleId = $roleId;
        $this->assignGroupId = null;
        $this->message = null;
    }

    public function closeAssign(): void
    {
        $this->assignForRoleId = null;
        $this->assignGroupId = null;
    }

    public function assign(RoleManager $manager): void
    {
        $this->ensureManager();
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        if ($this->assignForRoleId === null || $this->assignGroupId === null) {
            return;
        }
        $role = Role::findOrFail($this->assignForRoleId);
        $group = Group::findOrFail($this->assignGroupId);

        // Admin-tier fence on the role's CURRENT keys (a non-admin must not assign an admin-built admin-tier role).
        $this->assertNotAdminTierRole($role);

        try {
            $manager->assignToGroup($role, $group, $user);
            $this->flash("Assigned “{$role->name}” to “{$group->name}”.", 'success');
            $this->closeAssign();
        } catch (RoleException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    public function unassign(int $roleId, int $groupId, RoleManager $manager): void
    {
        $this->ensureManager();
        $role = Role::findOrFail($roleId);
        $group = Group::findOrFail($groupId);
        $this->assertNotAdminTierRole($role); // a non-admin must not unassign an admin-tier role baseline

        try {
            $manager->unassignFromGroup($role, $group);
            $this->flash("Removed “{$role->name}” from “{$group->name}”.", 'success');
        } catch (RoleException $e) {
            $this->flash($e->getMessage(), 'danger'); // e.g. the admins self-lockout backstop
        }
    }

    public function askDelete(int $id): void
    {
        $this->ensureManager();
        $this->deleteId = $id;
        $this->showForm = false;
        $this->message = null;
    }

    public function cancelDelete(): void
    {
        $this->deleteId = null;
    }

    public function delete(RoleManager $manager): void
    {
        $this->ensureManager();
        if ($this->deleteId === null) {
            return;
        }
        $role = Role::findOrFail($this->deleteId);
        $this->assertNotAdminTierRole($role); // a non-admin must not delete an admin-tier role

        try {
            $manager->delete($role);
            $this->flash("Deleted role “{$role->name}”.", 'success');
            $this->deleteId = null;
        } catch (RoleException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    // ── view data ───────────────────────────────────────────────────────────────────────────────────────

    /** @return list<array{role:Role,keys:int,groups:list<array{id:int,name:string}>}> custom roles. */
    public function customRows(): array
    {
        $this->ensureManager();
        $manager = app(RoleManager::class);
        $groupNames = Group::query()->pluck('name', 'id');

        return Role::query()->where('is_preset', false)->orderBy('name')->get()
            ->map(fn (Role $r): array => [
                'role' => $r,
                'keys' => $r->permissions()->count(),
                'groups' => collect($manager->assignedGroupIds($r))
                    ->map(fn (int $id): array => ['id' => $id, 'name' => (string) ($groupNames[$id] ?? "#{$id}")])
                    ->all(),
            ])->all();
    }

    /** @return list<array{role:Role,keys:int}> read-only system presets. */
    public function presetRows(): array
    {
        return Role::query()->where('is_preset', true)->orderByDesc('id')->get()
            ->map(fn (Role $r): array => ['role' => $r, 'keys' => $r->permissions()->count()])->all();
    }

    /** Catalog permissions grouped by cluster (the `group` field), ordered. @return \Illuminate\Support\Collection<string,\Illuminate\Support\Collection<int,Permission>> */
    public function clusters()
    {
        return Permission::query()->orderBy('group')->orderBy('label')->get()->groupBy('group');
    }

    /** Custom (non-system) groups a role baseline may be assigned to. @return list<array{id:int,name:string}> */
    public function assignableGroups(): array
    {
        return Group::query()->where('is_system', false)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Group $g): array => ['id' => (int) $g->id, 'name' => (string) $g->name])->all();
    }

    /** @return list<string> */
    public function adminTierKeys(): array
    {
        return app(RoleManager::class)->adminTierKeys();
    }

    public function isAdmin(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string,string> every catalog key initialised to 'no'. */
    private function blankValues(): array
    {
        return array_fill_keys($this->catalogKeys(), 'no');
    }

    /** @return list<string> */
    private function catalogKeys(): array
    {
        return Permission::query()->pluck('key')->all();
    }

    private function resetForm(): void
    {
        $this->formId = null;
        $this->editingPreset = false;
        $this->name = '';
        $this->description = '';
        $this->values = $this->blankValues();
        $this->resetErrorBag();
    }

    private function flash(string $message, string $variant = 'info'): void
    {
        $this->message = $message;
        $this->messageVariant = $variant;
    }

    private function ensureManager(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canDo('admin.access', Scope::global()), 403);
        abort_if($user->isStaff() && $user->two_factor_confirmed_at === null, 403);
        abort_unless($user->canDo('permissions.manage', Scope::global()), 403);
    }

    /**
     * A non-admin must not assign / unassign / delete a role that carries an Administration-tier key (the same
     * escalation fence as create — a clean 403; RoleManager is the actor-independent backstop for the lockout).
     */
    private function assertNotAdminTierRole(Role $role): void
    {
        if ((bool) auth()->user()?->isAdmin()) {
            return;
        }
        $adminTier = $this->adminTierKeys();
        foreach (array_keys(app(RoleManager::class)->valueMap($role)) as $key) {
            if (in_array($key, $adminTier, true)) {
                abort(403);
            }
        }
    }
};
?>

<div class="space-y-5" dusk="acp-roles">
    @if ($message)
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-ink-muted max-w-2xl">
            Build <strong>custom roles</strong> — reusable bundles of Yes / No / Never permissions — and apply one
            as a custom group's baseline. <strong>System presets</strong> (Administrator, Moderator, Member, Guest)
            are read-only: they seed the engine and define the staff groups.
        </p>
        <x-ui.button type="button" size="sm" wire:click="newRole" dusk="acp-new-role">
            <x-ui.icon name="plus" class="h-4 w-4" /> New role
        </x-ui.button>
    </div>

    {{-- Create / edit form (or a read-only preset view). --}}
    @if ($showForm)
        <x-ui.card>
            <form wire:submit="save" class="space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-ink">
                        {{ $editingPreset ? 'System preset (read-only)' : ($formId ? 'Edit role' : 'New custom role') }}
                    </h2>
                    @if ($editingPreset)
                        <x-ui.badge variant="neutral">Read-only</x-ui.badge>
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input label="Name" name="name" wire:model="name" required maxlength="60"
                                :disabled="$editingPreset" dusk="acp-role-name" />
                </div>
                <x-ui.textarea label="Description" name="description" wire:model="description" rows="2"
                               hint="Optional. Shown in the role list." maxlength="255" :disabled="$editingPreset" />

                @php($adminTier = $this->adminTierKeys())
                @php($isAdmin = $this->isAdmin())
                @php($states = ['yes' => 'Yes', 'no' => 'No', 'never' => 'Never'])

                <div class="space-y-4">
                    @foreach ($this->clusters() as $cluster => $perms)
                        <div class="rounded-md border border-line bg-surface-sunken">
                            <p class="px-4 pt-3 pb-1 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ $cluster }}</p>
                            <ul class="divide-y divide-line">
                                @foreach ($perms as $perm)
                                    @php($isAdminTier = in_array($perm->key, $adminTier, true))
                                    @php($rowEditable = ! $editingPreset && ($isAdmin || ! $isAdminTier))
                                    @php($current = $values[$perm->key] ?? 'no')
                                    <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5" wire:key="perm-{{ $perm->key }}">
                                        <div class="min-w-0">
                                            <span class="text-sm text-ink">{{ $perm->label }}</span>
                                            @if ($isAdminTier)
                                                <span class="ml-1 inline-flex items-center gap-1 text-xs text-ink-subtle" title="Administration capability — full admin only">
                                                    <x-ui.icon name="shield" class="h-3.5 w-3.5" />
                                                </span>
                                            @endif
                                            <p class="text-xs text-ink-subtle">{{ $perm->key }}</p>
                                        </div>
                                        <div role="group" aria-label="{{ $perm->label }}" class="inline-flex shrink-0 overflow-hidden rounded-md border border-line">
                                            @foreach ($states as $opt => $optLabel)
                                                <button type="button"
                                                        @if ($rowEditable) wire:click="setValue('{{ $perm->key }}', '{{ $opt }}')" wire:key="set-{{ $perm->key }}-{{ $opt }}" @else disabled @endif
                                                        aria-pressed="{{ $current === $opt ? 'true' : 'false' }}"
                                                        @class([
                                                            'min-h-9 px-3 text-xs font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent',
                                                            'border-l border-line' => ! $loop->first,
                                                            'bg-accent-soft text-accent-soft-ink' => $current === $opt,
                                                            'text-ink-muted hover:bg-surface-sunken hover:text-ink' => $current !== $opt && $rowEditable,
                                                            'text-ink-subtle cursor-not-allowed' => ! $rowEditable,
                                                        ])>
                                                    {{ $optLabel }}
                                                </button>
                                            @endforeach
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @unless ($editingPreset)
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save" dusk="acp-role-save">
                            <span wire:loading.remove wire:target="save">{{ $formId ? 'Save changes' : 'Create role' }}</span>
                            <span wire:loading wire:target="save">Saving…</span>
                        </x-ui.button>
                    @endunless
                    <x-ui.button type="button" variant="ghost" wire:click="cancelForm">{{ $editingPreset ? 'Close' : 'Cancel' }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    {{-- Custom roles. --}}
    <x-ui.card flush>
        <div class="px-4 py-2.5 sm:px-5 border-b border-line bg-surface-sunken text-xs font-semibold uppercase tracking-wide text-ink-subtle">
            Custom roles
        </div>
        <ul class="divide-y divide-line">
            @forelse ($this->customRows() as $row)
                @php($r = $row['role'])
                <li>
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-5 text-sm">
                        <div class="min-w-0">
                            <span class="font-medium text-ink">{{ $r->name }}</span>
                            <span class="ml-2 text-xs text-ink-subtle nums">{{ $row['keys'] }} permission(s)</span>
                            @if ($r->description)
                                <p class="mt-0.5 text-xs text-ink-subtle truncate">{{ $r->description }}</p>
                            @endif
                            @if (! empty($row['groups']))
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @foreach ($row['groups'] as $g)
                                        <span class="inline-flex items-center gap-1 rounded bg-surface-sunken px-1.5 py-0.5 text-xs text-ink-muted">
                                            {{ $g['name'] }}
                                            <button type="button" wire:click="unassign({{ $r->id }}, {{ $g['id'] }})" title="Remove baseline" class="text-ink-subtle hover:text-danger">&times;</button>
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-center gap-1">
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="openAssign({{ $r->id }})" dusk="acp-role-assign-{{ $r->id }}">Assign</x-ui.button>
                            <x-ui.button type="button" variant="ghost" size="sm" icon wire:click="edit({{ $r->id }})" title="Edit" dusk="acp-role-edit-{{ $r->id }}">
                                <x-ui.icon name="pencil" class="h-4 w-4" />
                            </x-ui.button>
                            <x-ui.button type="button" variant="danger-ghost" size="sm" icon wire:click="askDelete({{ $r->id }})" title="Delete">
                                <x-ui.icon name="trash" class="h-4 w-4" />
                            </x-ui.button>
                        </div>
                    </div>

                    {{-- Inline assign control. --}}
                    @if ($assignForRoleId === $r->id)
                        <div class="border-t border-line bg-surface-sunken px-4 py-3 sm:px-5">
                            <div class="flex flex-wrap items-end gap-2">
                                <x-ui.select label="Apply as the baseline of" name="assignGroupId" wire:model="assignGroupId" class="max-w-xs">
                                    <option value="">— Choose a custom group —</option>
                                    @foreach ($this->assignableGroups() as $opt)
                                        <option value="{{ $opt['id'] }}">{{ $opt['name'] }}</option>
                                    @endforeach
                                </x-ui.select>
                                <x-ui.button type="button" size="sm" wire:click="assign" :disabled="! $assignGroupId" dusk="acp-role-assign-confirm">Assign</x-ui.button>
                                <x-ui.button type="button" size="sm" variant="ghost" wire:click="closeAssign">Cancel</x-ui.button>
                            </div>
                            <p class="mt-2 text-xs text-ink-subtle">Replaces the group's current role baseline. Editing this role afterwards updates every assigned group.</p>
                        </div>
                    @endif

                    {{-- Inline delete confirm. --}}
                    @if ($deleteId === $r->id)
                        <div class="border-t border-line bg-surface-sunken px-4 py-4 sm:px-5">
                            <x-ui.alert variant="warn" class="mb-3">
                                Delete “{{ $r->name }}”? Its permissions are removed from every group it's applied to.
                            </x-ui.alert>
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.button type="button" variant="danger" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">Delete role</x-ui.button>
                                <x-ui.button type="button" variant="ghost" wire:click="cancelDelete">Cancel</x-ui.button>
                            </div>
                        </div>
                    @endif
                </li>
            @empty
                <li class="px-4 py-6 sm:px-5 text-sm text-ink-subtle">No custom roles yet. Create one to bundle permissions for a group.</li>
            @endforelse
        </ul>
    </x-ui.card>

    {{-- System presets (read-only). --}}
    <x-ui.card flush>
        <div class="px-4 py-2.5 sm:px-5 border-b border-line bg-surface-sunken text-xs font-semibold uppercase tracking-wide text-ink-subtle">
            System presets <span class="font-normal normal-case text-ink-subtle">— read-only</span>
        </div>
        <ul class="divide-y divide-line">
            @foreach ($this->presetRows() as $row)
                @php($r = $row['role'])
                <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-5 text-sm">
                    <div class="min-w-0">
                        <span class="font-medium text-ink">{{ $r->name }}</span>
                        <span class="ml-2 text-xs text-ink-subtle nums">{{ $row['keys'] }} permission(s)</span>
                    </div>
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="edit({{ $r->id }})" title="View">View</x-ui.button>
                </li>
            @endforeach
        </ul>
    </x-ui.card>
</div>
