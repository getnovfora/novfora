<?php
// SPDX-License-Identifier: Apache-2.0
use App\Admin\GroupException;
use App\Admin\GroupManager;
use App\Groups\GroupAutoPromoter;
use App\Models\AclEntry;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Permissions\Scope;
use App\Support\GroupColor;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Admin → Members → Groups (ACP v2). The member-group manager: list / create / edit / delete custom groups,
 * manage membership, and set a group's permission preset — all the binding safety lives in GroupManager
 * (system-group protection, delete-with-reassign, the membership boundary). Like every admin SFC the
 * authorization is re-asserted in mount() AND every action, because Livewire actions reach the component via
 * livewire/update with no route middleware.
 */
new class extends Component
{
    public bool $showForm = false;

    public ?int $formId = null; // null = creating a custom group

    public string $name = '';

    public string $description = '';

    public string $color = '';

    public int $priority = 50;

    public ?int $roleId = null;

    // v3-e group config (custom groups only).
    public string $membershipModel = 'admin'; // admin | request | open

    public bool $isPublic = false;

    public string $promoteOp = 'AND'; // AND = match all, OR = match any

    /** @var list<array{criterion:string,gte:int}> the auto-promotion criteria rows (single-level AND/OR builder). */
    public array $promoteRules = [];

    public bool $editingSystem = false;

    public ?int $deleteId = null;

    public ?int $reassignId = null;

    public ?int $membersId = null;

    public string $memberSearch = '';

    public bool $showAllMembers = false; // false = cap the member list at 50; true = list every member

    public string $groupSearch = ''; // filter the group list by name

    public ?string $message = null;

    public string $messageVariant = 'info';

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function newGroup(): void
    {
        $this->ensureAdmin();
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->ensureAdmin();
        $group = Group::findOrFail($id);
        $this->formId = $group->id;
        $this->name = (string) $group->name;
        $this->description = (string) ($group->description ?? '');
        $this->color = (string) ($group->color ?? '');
        $this->priority = (int) $group->priority;
        $this->roleId = optional(RoleAssignment::where('holder_type', 'group')->where('holder_id', $group->id)->first())->role_id;
        $this->editingSystem = (bool) $group->is_system;
        $this->membershipModel = $group->membershipModel();
        $this->isPublic = (bool) $group->is_public;
        $this->hydratePromotion($group);
        $this->deleteId = null;
        $this->membersId = null;
        $this->showForm = true;
    }

    public function save(GroupManager $manager): void
    {
        $this->ensureAdmin();

        // System groups expose only name/colour/description in the form (priority + role preset are their
        // identity and stay hidden). Their seeded priority can exceed the custom-group ceiling — Administrators
        // is 100, Moderators 80 — so validating `priority` with max:99 for them would fail on an invisible
        // field and silently block the save. Those rules therefore apply to CUSTOM groups only; GroupManager
        // already ignores priority/role for system groups regardless.
        $rules = [
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'], // GroupManager enforces the palette
        ];
        if (! $this->editingSystem) {
            $rules['priority'] = ['nullable', 'integer', 'min:1', 'max:99'];
            $rules['roleId'] = ['nullable', 'integer', 'exists:roles,id'];
            $rules['membershipModel'] = ['required', Rule::in(Group::MEMBERSHIP_MODELS)];
            $rules['isPublic'] = ['boolean'];
            $rules['promoteOp'] = ['required', Rule::in(['AND', 'OR'])];
            $rules['promoteRules'] = ['array'];
            $rules['promoteRules.*.criterion'] = ['required', Rule::in(GroupAutoPromoter::CRITERIA)];
            $rules['promoteRules.*.gte'] = ['required', 'integer', 'min:0'];
        }
        $data = $this->validate($rules);

        $payload = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'color' => $data['color'] ?? null,
            'priority' => $data['priority'] ?? $this->priority,
            'role_id' => $data['roleId'] ?? $this->roleId,
        ];

        // v3-e config is custom-group-only; for a system group these keys are simply not sent (GroupManager
        // ignores them on a system group regardless).
        if (! $this->editingSystem) {
            $payload['membership_model'] = $this->membershipModel;
            $payload['is_public'] = $this->isPublic;
            $payload['auto_promotion'] = $this->buildPromotionTree();
        }

        try {
            if ($this->formId === null) {
                $group = $manager->create($payload);
                $this->flash("Created group “{$group->name}”.", 'success');
            } else {
                $group = $manager->update(Group::findOrFail($this->formId), $payload);
                $this->flash("Saved group “{$group->name}”.", 'success');
            }
            $this->cancelForm();
        } catch (GroupException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    public function askDelete(int $id): void
    {
        $this->ensureAdmin();
        $this->deleteId = $id;
        $this->reassignId = null;
        $this->showForm = false;
        $this->membersId = null;
        $this->message = null;
    }

    public function cancelDelete(): void
    {
        $this->deleteId = null;
        $this->reassignId = null;
    }

    public function delete(GroupManager $manager): void
    {
        $this->ensureAdmin();
        if ($this->deleteId === null) {
            return;
        }

        $group = Group::findOrFail($this->deleteId);
        $reassignTo = $this->reassignId ? Group::find($this->reassignId) : null;

        try {
            $moved = $manager->delete($group, $reassignTo);
            $this->flash("Deleted “{$group->name}”.".($moved > 0 ? " Reassigned {$moved} member(s) to “{$reassignTo?->name}”." : ''), 'success');
            $this->deleteId = null;
            $this->reassignId = null;
        } catch (GroupException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    public function clone(int $id, GroupManager $manager): void
    {
        $source = Group::findOrFail($id);
        $this->ensureCanClone($source);

        try {
            $clone = $manager->clone($source);
            $this->flash("Cloned “{$source->name}” → “{$clone->name}”. The copy has no members yet.", 'success');
        } catch (GroupException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    public function manageMembers(int $id): void
    {
        $this->ensureAdmin();
        $this->membersId = $id;
        $this->memberSearch = '';
        $this->showAllMembers = false;
        $this->showForm = false;
        $this->deleteId = null;
        $this->message = null;
    }

    public function closeMembers(): void
    {
        $this->membersId = null;
        $this->memberSearch = '';
        $this->showAllMembers = false;
    }

    /** Lift the 50-row cap on the member list (the panel paginates to "show all" on demand). */
    public function revealAllMembers(): void
    {
        $this->ensureAdmin();
        $this->showAllMembers = true;
    }

    // Arg-first, service-second — the proven Livewire action-injection order (cf. structure's moveUp()).
    public function addMember(int $userId, GroupManager $manager): void
    {
        $this->ensureAdmin();
        if ($this->membersId === null) {
            return;
        }
        try {
            $added = $manager->addMembers(Group::findOrFail($this->membersId), [$userId]);
            $this->memberSearch = '';
            $this->flash($added > 0 ? 'Member added.' : 'Already a member.', $added > 0 ? 'success' : 'info');
        } catch (GroupException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    public function removeMember(int $userId, GroupManager $manager): void
    {
        $this->ensureAdmin();
        if ($this->membersId === null) {
            return;
        }
        try {
            $manager->removeMember(Group::findOrFail($this->membersId), $userId);
            $this->flash('Member removed.', 'success');
        } catch (GroupException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    /** @return list<array{group:Group,members:int,role:?string,membership:bool,system:bool}> */
    public function rows(): array
    {
        $this->ensureAdmin();

        $roleMap = RoleAssignment::query()->where('holder_type', 'group')->with('role')->get()
            ->groupBy('holder_id')->map(fn ($set) => $set->first()->role?->name)->all();
        $manager = app(GroupManager::class);
        $q = trim($this->groupSearch);

        return Group::query()
            ->when($q !== '', fn ($query) => $query->where('name', 'like', '%'.$q.'%'))
            ->withCount('users')->orderByDesc('priority')->orderBy('name')->get()
            ->map(fn (Group $g): array => [
                'group' => $g,
                'members' => (int) $g->users_count,
                'role' => $roleMap[$g->id] ?? null,
                'membership' => $manager->manualMembershipAllowed($g),
                'system' => (bool) $g->is_system,
            ])->all();
    }

    /** @return list<array{id:int,name:string}> */
    public function roleOptions(): array
    {
        return Role::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Role $r): array => ['id' => (int) $r->id, 'name' => (string) $r->name])->all();
    }

    /** Groups a delete can reassign members INTO — excludes the deleted group AND any engine-managed
     *  (trust) or base (Guests/Members) group, mirroring the membership boundary addMembers() enforces. */
    public function reassignOptions(): array
    {
        $manager = app(GroupManager::class);

        return Group::query()->where('id', '!=', (int) $this->deleteId)
            ->orderByDesc('priority')->orderBy('name')->get()
            ->filter(fn (Group $g): bool => $manager->manualMembershipAllowed($g))
            ->map(fn (Group $g): array => ['id' => (int) $g->id, 'name' => (string) $g->name])->values()->all();
    }

    /** Current members of the group being managed (capped at 50 unless "show all" is on). @return list<User> */
    public function memberRows(): array
    {
        if ($this->membersId === null) {
            return [];
        }
        $group = Group::find($this->membersId);
        if (! $group instanceof Group) {
            return [];
        }

        $query = $group->users()->with('groups')->orderBy('username');

        return ($this->showAllMembers ? $query : $query->limit(50))->get()->all();
    }

    /** Total member count of the managed group (so the panel knows when to offer "show all"). */
    public function memberCount(): int
    {
        if ($this->membersId === null) {
            return 0;
        }
        $group = Group::find($this->membersId);

        return $group instanceof Group ? $group->users()->count() : 0;
    }

    public function managedGroup(): ?Group
    {
        return $this->membersId ? Group::find($this->membersId) : null;
    }

    /** Matching users for the member box — INCLUDING existing members (so any member is locatable by name for
     *  removal, regardless of the 50-row list cap). The view shows Add vs Remove via memberIdSet(). @return list<User> */
    public function searchResults(): array
    {
        $q = trim($this->memberSearch);
        if ($this->membersId === null || strlen($q) < 2) {
            return [];
        }

        return User::query()
            ->where(fn ($w) => $w->where('username', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('display_name', 'like', "%{$q}%"))
            ->with('groups')
            ->orderBy('username')->limit(10)->get()->all();
    }

    /** @return list<int> the managed group's current member ids (to pick Add vs Remove in search results). */
    public function memberIdSet(): array
    {
        if ($this->membersId === null) {
            return [];
        }
        $group = Group::find($this->membersId);

        return $group ? $group->users()->pluck('users.id')->map(fn ($id): int => (int) $id)->all() : [];
    }

    public function colorOptions(): array
    {
        return GroupColor::PALETTE;
    }

    public function inspectorUrl(): string
    {
        return route('admin.security.permissions');
    }

    /** Add a blank auto-promotion criterion row (the AND/OR builder). */
    public function addRule(): void
    {
        $this->ensureAdmin();
        $this->promoteRules[] = ['criterion' => 'posts', 'gte' => 0];
    }

    public function removeRule(int $i): void
    {
        $this->ensureAdmin();
        unset($this->promoteRules[$i]);
        $this->promoteRules = array_values($this->promoteRules);
    }

    /** The closed criterion vocabulary for the builder select (key => human label). */
    public function criterionOptions(): array
    {
        return [
            'posts' => 'Post count',
            'tenure_days' => 'Account age (days)',
            'trust' => 'Trust level',
            'reputation' => 'Reputation points',
        ];
    }

    /** Hydrate the single-level builder from a group's stored auto_promotion tree (top-level leaves only). */
    private function hydratePromotion(Group $group): void
    {
        $tree = app(GroupAutoPromoter::class)->normalize($group->auto_promotion);
        $this->promoteOp = $tree['op'] ?? 'AND';
        $this->promoteRules = [];
        foreach (($tree['rules'] ?? []) as $rule) {
            // The builder edits a flat list of leaves; a nested node (only reachable via import) is skipped here.
            if (isset($rule['criterion'])) {
                $this->promoteRules[] = ['criterion' => (string) $rule['criterion'], 'gte' => (int) ($rule['gte'] ?? 0)];
            }
        }
    }

    /** Assemble the builder state into an {op, rules} tree, or null when no criteria are set. */
    private function buildPromotionTree(): ?array
    {
        $rules = [];
        foreach ($this->promoteRules as $row) {
            if (isset($row['criterion'])) {
                $rules[] = ['criterion' => (string) $row['criterion'], 'gte' => (int) ($row['gte'] ?? 0)];
            }
        }

        return $rules === [] ? null : ['op' => $this->promoteOp, 'rules' => $rules];
    }

    private function resetForm(): void
    {
        $this->reset(['formId', 'name', 'description', 'color', 'priority', 'roleId', 'editingSystem', 'membershipModel', 'isPublic', 'promoteOp', 'promoteRules']);
        $this->priority = 50;
        $this->membershipModel = 'admin';
        $this->promoteOp = 'AND';
        $this->promoteRules = [];
        $this->resetErrorBag();
    }

    private function flash(string $message, string $variant = 'info'): void
    {
        $this->message = $message;
        $this->messageVariant = $variant;
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canDo('admin.access', Scope::global()), 403);
        abort_if($user->isStaff() && $user->two_factor_confirmed_at === null, 403);
    }

    /**
     * Cloning WRITES acl_entries, so it is a permission operation: reuse the card editor's full guard set — the
     * manage-permissions capability, the rank guard (you can't clone a group you couldn't edit), and the
     * admin-tier fence (a non-admin must not mint an Administration-tier grant onto a new group). Only custom
     * groups are cloneable. Pre-checks for a clean 403; GroupManager::clone() is the actor-independent backstop.
     */
    private function ensureCanClone(Group $source): void
    {
        $this->ensureAdmin(); // admin.access + staff-2FA
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canDo('permissions.manage', Scope::global()), 403);
        abort_unless($source->type === 'custom' && ! $source->is_system, 403);          // only custom groups
        abort_unless($user->isAdmin() || $user->rankPriority() > (int) $source->priority, 403); // rank guard
        if (! $user->isAdmin() && $this->sourceHoldsAdminTierKey($source)) {
            abort(403); // admin-tier fence
        }
    }

    /** Whether the source group carries any Administration-tier acl_entries key (full-admin-only to clone). */
    private function sourceHoldsAdminTierKey(Group $source): bool
    {
        $adminTier = Permission::query()->where('group', 'Administration')->pluck('key')->all();
        if ($adminTier === []) {
            return false;
        }

        return AclEntry::query()
            ->where('holder_type', 'group')->where('holder_id', $source->getKey())
            ->whereIn('permission_key', $adminTier)
            ->exists();
    }
};
?>

<div class="space-y-5" dusk="acp-groups">
    @if ($message)
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-ink-muted max-w-2xl">
            Member groups carry permissions (via a role preset) and a name colour. <strong>System groups</strong>
            (Guests, Members, the trust levels, and the staff roles) are protected — you can recolour and relabel
            them, but not delete or re-type them. Trust-level membership is managed automatically.
        </p>
        <x-ui.button type="button" size="sm" wire:click="newGroup" dusk="acp-new-group">
            <x-ui.icon name="plus" class="h-4 w-4" /> New group
        </x-ui.button>
    </div>

    {{-- Create / edit form. --}}
    @if ($showForm)
        <x-ui.card>
            <form wire:submit="save" class="space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-ink">{{ $formId ? 'Edit group' : 'New custom group' }}</h2>
                    @if ($editingSystem)
                        <x-ui.badge variant="neutral">System group — label &amp; colour only</x-ui.badge>
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input label="Name" name="name" wire:model="name" required maxlength="60" dusk="acp-group-name" />
                    <x-ui.select label="Name colour" name="color" wire:model.live="color" hint="Shown wherever this group's members' names appear.">
                        <option value="">— No colour —</option>
                        @foreach ($this->colorOptions() as $key => $meta)
                            <option value="{{ $key }}">{{ $meta[0] }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                @php($previewColor = \App\Support\GroupColor::cssVar($color))
                @if ($previewColor)
                    <p class="text-sm text-ink-muted">
                        Preview: <span style="color: {{ $previewColor }};" class="font-semibold">{{ $name !== '' ? $name : 'Sample name' }}</span>
                    </p>
                @endif

                <x-ui.textarea label="Description" name="description" wire:model="description" rows="2"
                               hint="Optional. Shown in the group list." maxlength="255" />

                @unless ($editingSystem)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-ui.select label="Permission preset (role)" name="roleId" wire:model.live="roleId"
                                         hint="Grants this group a role's permissions through the engine. Leave blank for none.">
                                <option value="">— None —</option>
                                @foreach ($this->roleOptions() as $opt)
                                    <option value="{{ $opt['id'] }}">{{ $opt['name'] }}</option>
                                @endforeach
                            </x-ui.select>
                            @if ($roleId)
                                <a href="{{ route('admin.groups.roles') }}" class="mt-1 inline-block text-xs text-accent hover:underline" dusk="acp-edit-role-link">Edit roles →</a>
                            @endif
                        </div>
                        <x-ui.input label="Rank priority" name="priority" type="number" min="1" max="99" wire:model="priority"
                                    hint="1–99. Higher wins when a member is in several coloured groups." />
                    </div>

                    {{-- v3-e: how members join + public visibility. --}}
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.select label="How members join" name="membershipModel" wire:model="membershipModel"
                                     hint="Auto-promotion (below) can add members on top of any model.">
                            <option value="admin">Admin-assigned only</option>
                            <option value="request">Request &amp; approval</option>
                            <option value="open">Open join (public Join button)</option>
                        </x-ui.select>
                        <label class="flex items-start gap-2 pt-7 text-sm text-ink">
                            <input type="checkbox" wire:model="isPublic" class="mt-0.5 rounded border-line text-accent focus:ring-accent" dusk="acp-group-public" />
                            <span>List on the public <strong>Groups</strong> page <span class="text-ink-subtle">(off by default)</span></span>
                        </label>
                    </div>

                    {{-- v3-e: AND/OR auto-promotion builder. --}}
                    <div class="rounded-md border border-line bg-surface-sunken p-4 space-y-3" dusk="acp-autopromote">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-ink">Auto-promotion</h3>
                            <p class="text-xs text-ink-subtle">The system promotes members who meet the criteria. Promotion-only — never removes anyone.</p>
                        </div>

                        @if (! empty($promoteRules))
                            <div class="flex items-center gap-2 text-sm">
                                <span class="text-ink-muted">Match</span>
                                <x-ui.select name="promoteOp" wire:model="promoteOp" class="w-auto">
                                    <option value="AND">ALL of</option>
                                    <option value="OR">ANY of</option>
                                </x-ui.select>
                                <span class="text-ink-muted">these criteria:</span>
                            </div>
                        @endif

                        <div class="space-y-2">
                            @foreach ($promoteRules as $i => $rule)
                                <div class="flex flex-wrap items-end gap-2" wire:key="rule-{{ $i }}">
                                    <x-ui.select :label="$i === 0 ? 'Criterion' : null" name="promoteRules.{{ $i }}.criterion" wire:model="promoteRules.{{ $i }}.criterion" class="w-48">
                                        @foreach ($this->criterionOptions() as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </x-ui.select>
                                    <span class="pb-2.5 text-sm text-ink-muted">at least</span>
                                    <x-ui.input :label="$i === 0 ? 'Threshold' : null" name="promoteRules.{{ $i }}.gte" type="number" min="0" wire:model="promoteRules.{{ $i }}.gte" class="w-28" />
                                    <x-ui.button type="button" variant="danger-ghost" size="sm" icon wire:click="removeRule({{ $i }})" title="Remove criterion" class="mb-0.5">
                                        <x-ui.icon name="trash" class="h-4 w-4" />
                                    </x-ui.button>
                                </div>
                                @error("promoteRules.{$i}.gte") <p class="text-xs text-danger">{{ $message }}</p> @enderror
                            @endforeach
                        </div>

                        @if (empty($promoteRules))
                            <p class="text-sm text-ink-subtle">No auto-promotion — members are added only by the join model above (or manually).</p>
                        @endif

                        <x-ui.button type="button" variant="subtle" size="sm" wire:click="addRule" dusk="acp-add-rule">
                            <x-ui.icon name="plus" class="h-4 w-4" /> Add criterion
                        </x-ui.button>
                    </div>
                @endunless

                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save" dusk="acp-group-save">
                        <span wire:loading.remove wire:target="save">{{ $formId ? 'Save changes' : 'Create group' }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="cancelForm">Cancel</x-ui.button>
                    <a href="{{ $this->inspectorUrl() }}" class="text-sm text-accent hover:underline">Open the permission inspector →</a>
                </div>
            </form>
        </x-ui.card>
    @endif

    {{-- Group list. --}}
    <div class="max-w-sm">
        <x-ui.input name="groupSearch" wire:model.live.debounce.300ms="groupSearch"
                    placeholder="Filter groups by name…" dusk="acp-group-search" />
    </div>
    <x-ui.card flush>
        <div class="hidden sm:grid grid-cols-[1fr_8rem_7rem_5rem_9rem] gap-3 px-4 py-2.5 sm:px-5 border-b border-line bg-surface-sunken text-xs font-semibold uppercase tracking-wide text-ink-subtle">
            <span>Group</span>
            <span>Type</span>
            <span>Role</span>
            <span class="text-right">Members</span>
            <span class="text-right">Actions</span>
        </div>
        <ul class="divide-y divide-line">
            @foreach ($this->rows() as $row)
                @php($g = $row['group'])
                <li>
                    <div class="grid grid-cols-1 gap-2 px-4 py-3 sm:grid-cols-[1fr_8rem_7rem_5rem_9rem] sm:items-center sm:gap-3 sm:px-5 text-sm">
                        <div class="min-w-0">
                            @php($gc = \App\Support\GroupColor::cssVar($g->color))
                            <div class="flex items-center gap-2">
                                @if ($gc)
                                    <span class="inline-block h-3 w-3 shrink-0 rounded-full" style="background: {{ $gc }};" aria-hidden="true"></span>
                                @endif
                                <span class="font-medium truncate" @if ($gc) style="color: {{ $gc }};" @endif>{{ $g->name }}</span>
                            </div>
                            @if ($g->description)
                                <p class="mt-0.5 text-xs text-ink-subtle truncate">{{ $g->description }}</p>
                            @endif
                        </div>
                        <div>
                            <x-ui.badge :variant="$row['system'] ? 'neutral' : 'accent'">{{ ucfirst($g->type) }}</x-ui.badge>
                        </div>
                        <div class="text-ink-muted truncate">{{ $row['role'] ?? '—' }}</div>
                        <div class="text-ink-muted sm:text-right nums">{{ number_format($row['members']) }}</div>
                        <div class="flex flex-wrap items-center gap-1 sm:justify-end">
                            @if ($row['membership'])
                                @if ($g->slug === 'admins')
                                    {{-- Discoverability: adding a FULL admin is "join the Administrators group", so the
                                         admins row gets a clear labelled affordance rather than a bare icon. --}}
                                    <x-ui.button type="button" variant="subtle" size="sm" wire:click="manageMembers({{ $g->id }})" dusk="acp-admins-members" title="Add or remove administrators">
                                        <x-ui.icon name="users" class="h-4 w-4" /> Add / manage members
                                    </x-ui.button>
                                @else
                                    <x-ui.button type="button" variant="ghost" size="sm" icon wire:click="manageMembers({{ $g->id }})" title="Members">
                                        <x-ui.icon name="users" class="h-4 w-4" />
                                    </x-ui.button>
                                @endif
                            @endif
                            <x-ui.button type="button" variant="ghost" size="sm" icon wire:click="edit({{ $g->id }})" title="Edit" dusk="acp-group-edit-{{ $g->id }}">
                                <x-ui.icon name="pencil" class="h-4 w-4" />
                            </x-ui.button>
                            @if ($g->type === 'custom')
                                {{-- Clone duplicates this group's permissions into a new, member-less group (custom groups only). --}}
                                <x-ui.button type="button" variant="ghost" size="sm" wire:click="clone({{ $g->id }})" title="Clone this group's permissions into a new group" dusk="acp-group-clone-{{ $g->id }}">Clone</x-ui.button>
                            @endif
                            @unless ($row['system'])
                                <x-ui.button type="button" variant="danger-ghost" size="sm" icon wire:click="askDelete({{ $g->id }})" title="Delete">
                                    <x-ui.icon name="trash" class="h-4 w-4" />
                                </x-ui.button>
                            @endunless
                        </div>
                    </div>

                    {{-- Inline delete-safety panel (custom groups only). --}}
                    @if ($deleteId === $g->id)
                        <div class="border-t border-line bg-surface-sunken px-4 py-4 sm:px-5">
                            <x-ui.alert variant="warn" class="mb-3">
                                Delete “{{ $g->name }}”?
                                @if ($row['members'] > 0)
                                    It has <strong class="nums">{{ number_format($row['members']) }}</strong> member(s) —
                                    choose a group to reassign them into (no membership is lost).
                                @else
                                    It has no members, so this is safe.
                                @endif
                            </x-ui.alert>

                            @if ($row['members'] > 0)
                                <x-ui.select label="Reassign members to" name="reassignId" wire:model="reassignId" class="mb-3 max-w-md">
                                    <option value="">— Choose a group —</option>
                                    @foreach ($this->reassignOptions() as $opt)
                                        <option value="{{ $opt['id'] }}">{{ $opt['name'] }}</option>
                                    @endforeach
                                </x-ui.select>
                            @endif

                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.button type="button" variant="danger" wire:click="delete"
                                             wire:loading.attr="disabled" wire:target="delete"
                                             :disabled="$row['members'] > 0 && ! $reassignId">
                                    <span wire:loading.remove wire:target="delete">{{ $row['members'] > 0 ? 'Reassign & delete' : 'Delete' }}</span>
                                    <span wire:loading wire:target="delete">Working…</span>
                                </x-ui.button>
                                <x-ui.button type="button" variant="ghost" wire:click="cancelDelete">Cancel</x-ui.button>
                            </div>
                        </div>
                    @endif

                    {{-- Inline membership panel. --}}
                    @if ($membersId === $g->id)
                        <div class="border-t border-line bg-surface-sunken px-4 py-4 sm:px-5 space-y-4" dusk="acp-members-panel">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-ink">Members of “{{ $g->name }}”</h3>
                                <x-ui.button type="button" variant="ghost" size="sm" wire:click="closeMembers">Close</x-ui.button>
                            </div>

                            <div>
                                <x-ui.input label="Add a member" name="memberSearch" wire:model.live.debounce.300ms="memberSearch"
                                            placeholder="Search by username or email" dusk="acp-member-search" />
                                @php($results = $this->searchResults())
                                @php($memberIds = $this->memberIdSet())
                                @if (! empty($results))
                                    <ul class="mt-2 divide-y divide-line rounded-md border border-line bg-surface-raised">
                                        @foreach ($results as $u)
                                            @php($isMember = in_array((int) $u->id, $memberIds, true))
                                            <li class="flex items-center justify-between gap-3 px-3 py-2">
                                                <span class="min-w-0 truncate text-sm text-ink">
                                                    <x-ui.user-name :user="$u" /> <span class="text-ink-subtle">@ {{ $u->username }}</span>
                                                </span>
                                                @if ($isMember)
                                                    <x-ui.button type="button" size="sm" variant="danger-ghost" wire:click="removeMember({{ $u->id }})">Remove</x-ui.button>
                                                @else
                                                    <x-ui.button type="button" size="sm" variant="subtle" wire:click="addMember({{ $u->id }})">Add</x-ui.button>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @elseif (strlen(trim($memberSearch)) >= 2)
                                    <p class="mt-2 text-sm text-ink-subtle">No matching users.</p>
                                @endif
                            </div>

                            @php($members = $this->memberRows())
                            @if (empty($members))
                                <p class="text-sm text-ink-subtle">No members yet.</p>
                            @else
                                <ul class="divide-y divide-line rounded-md border border-line bg-surface-raised">
                                    @foreach ($members as $u)
                                        <li class="flex items-center justify-between gap-3 px-3 py-2">
                                            <span class="min-w-0 truncate text-sm">
                                                <x-ui.user-name :user="$u" /> <span class="text-ink-subtle">@ {{ $u->username }}</span>
                                            </span>
                                            <x-ui.button type="button" size="sm" variant="danger-ghost" wire:click="removeMember({{ $u->id }})">Remove</x-ui.button>
                                        </li>
                                    @endforeach
                                </ul>
                                @php($total = $this->memberCount())
                                @if (! $showAllMembers && $total > count($members))
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-xs text-ink-subtle">Showing {{ number_format(count($members)) }} of {{ number_format($total) }} members.</p>
                                        <x-ui.button type="button" variant="subtle" size="sm" wire:click="revealAllMembers" dusk="acp-show-all-members">Show all {{ number_format($total) }}</x-ui.button>
                                    </div>
                                @else
                                    <p class="text-xs text-ink-subtle">{{ number_format($total) }} member{{ $total === 1 ? '' : 's' }}.</p>
                                @endif
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-ui.card>
</div>
