<?php
// SPDX-License-Identifier: Apache-2.0
use App\Admin\AdminBundleException;
use App\Admin\AdminBundleService;
use App\Models\AclEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Permissions\PermissionValue;
use App\Permissions\RoleException;
use App\Permissions\Scope;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * ACP v3 · v3-a — Admin Manager pane (Security → Admin Accounts). Lets a co-owner grant an individual user a
 * SUBSET of ACP sections (a "restricted admin") via a bundle preset or per-section toggles. Only users who are
 * NOT in the admins group are eligible; full admins already inherit every section via the administrator preset.
 * Authorization is re-asserted in mount() AND every action (Livewire actions bypass route middleware).
 */
new class extends Component
{
    public ?int $manageUserId = null;

    public string $assignBundleSlug = '';

    public string $newUserQuery = '';

    public ?string $message = null;

    public string $messageVariant = 'info';

    public function mount(): void
    {
        $this->ensureManager();
    }

    public function applyBundle(int $userId, string $bundleSlug): void
    {
        $this->ensureManager();
        $target = User::findOrFail($userId);
        $role = Role::query()->where('slug', $bundleSlug)->where('is_preset', true)->firstOrFail();

        try {
            app(AdminBundleService::class)->assign(auth()->user(), $target, $role);
            $this->flash("Bundle “{$role->name}” applied to “{$target->username}”.", 'success');
        } catch (RoleException|AdminBundleException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    public function toggleSection(int $userId, string $sectionKey): void
    {
        $this->ensureManager();
        $target = User::findOrFail($userId);
        $svc = app(AdminBundleService::class);
        $current = in_array($sectionKey, $svc->grantedSections($target), true);

        try {
            $svc->setSectionAccess(auth()->user(), $target, $sectionKey, ! $current);
            $label = $current ? 'Revoked' : 'Granted';
            $this->flash("{$label} “{$sectionKey}” for “{$target->username}”.", 'success');
        } catch (RoleException|AdminBundleException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    public function revokeAdmin(int $userId): void
    {
        $this->ensureManager();
        $target = User::findOrFail($userId);

        try {
            app(AdminBundleService::class)->revoke(auth()->user(), $target);
            if ($this->manageUserId === $userId) {
                $this->manageUserId = null;
            }
            $this->flash("“{$target->username}” no longer has restricted-admin access.", 'success');
        } catch (AdminBundleException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    public function manage(int $userId): void
    {
        $this->ensureManager();
        $this->manageUserId = $userId;
        $this->assignBundleSlug = '';
        $this->message = null;
    }

    public function closeManage(): void
    {
        $this->manageUserId = null;
        $this->assignBundleSlug = '';
    }

    // ── view data ───────────────────────────────────────────────────────────────────────────────────────

    /**
     * Users who hold a per-user admin.access grant but are NOT full admins.
     *
     * @return list<array{user:User,sections:list<string>,count:int}>
     */
    public function restrictedRows(): array
    {
        $svc = app(AdminBundleService::class);

        $candidates = User::query()
            ->whereIn('id',
                AclEntry::query()
                    ->where('permission_key', AdminBundleService::ACCESS_KEY)
                    ->where('holder_type', 'user')
                    ->where('scope_type', 'global')
                    ->whereNull('scope_id')
                    ->where('value', PermissionValue::Allow->value)
                    ->pluck('holder_id'),
            )
            ->orderBy('username')
            ->get();

        $rows = [];
        foreach ($candidates as $u) {
            if (! $svc->isRestrictedAdmin($u)) {
                continue;
            }
            $sections = $svc->grantedSections($u);
            $rows[] = ['user' => $u, 'sections' => $sections, 'count' => count($sections)];
        }

        return $rows;
    }

    /** @return Collection<int,Role> */
    public function bundles()
    {
        return Role::query()
            ->where('is_preset', true)
            ->where('slug', 'like', 'admin-bundle-%')
            ->orderBy('name')
            ->get();
    }

    /** @return list<array{key:string,label:string}> the 9 assignable section permissions */
    public function sections(): array
    {
        return Permission::query()
            ->where('group', 'Administration')
            ->where('key', 'like', 'admin.%.access')
            ->where('key', '!=', 'admin.security.access')
            ->orderBy('label')
            ->get(['key', 'label'])
            ->map(fn (Permission $p): array => ['key' => (string) $p->key, 'label' => (string) $p->label])
            ->all();
    }

    /**
     * Candidate users for new restricted-admin promotion: not a full admin, not already restricted.
     *
     * @return Collection<int,User>
     */
    public function candidateUsers()
    {
        $q = trim($this->newUserQuery);
        if (strlen($q) < 2) {
            return collect();
        }

        $svc = app(AdminBundleService::class);
        $existingIds = AclEntry::query()
            ->where('permission_key', AdminBundleService::ACCESS_KEY)
            ->where('holder_type', 'user')
            ->where('scope_type', 'global')
            ->whereNull('scope_id')
            ->where('value', PermissionValue::Allow->value)
            ->pluck('holder_id');

        return User::query()
            ->where('username', 'like', "%{$q}%")
            ->whereDoesntHave('groups', fn ($gq) => $gq->where('slug', 'admins'))
            ->whereNotIn('id', $existingIds)
            ->orderBy('username')
            ->limit(10)
            ->get();
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────────

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
        abort_unless($user->canDo('admin.security.access', Scope::global()), 403);
    }
};
?>

<div class="space-y-5" dusk="acp-admin-accounts">
    @if ($message)
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    <p class="max-w-2xl text-sm text-ink-muted">
        A <strong>restricted admin</strong> can access only the ACP sections you grant — they do not appear in
        the Administrators group. To give someone full admin access, add them to the Administrators group via
        <strong>Groups</strong>. Co-owners are managed on the Co-owners tab above.
    </p>

    {{-- Current restricted admins --}}
    <x-ui.card flush>
        <div class="border-b border-line bg-surface-sunken px-4 py-2.5 sm:px-5 text-xs font-semibold uppercase tracking-wide text-ink-subtle">
            Restricted admins
        </div>
        <ul class="divide-y divide-line">
            @forelse ($this->restrictedRows() as $row)
                @php($u = $row['user'])
                <li wire:key="restricted-{{ $u->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-5">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-ui.avatar :user="$u" size="sm" class="shrink-0" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">
                                    <x-ui.user-name :user="$u" />
                                </p>
                                <p class="text-xs text-ink-subtle">
                                    {{ $u->username }} ·
                                    @if ($row['count'] === 0)
                                        no sections granted
                                    @else
                                        {{ $row['count'] }} section{{ $row['count'] === 1 ? '' : 's' }}
                                    @endif
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-1">
                            <x-ui.button type="button" variant="ghost" size="sm"
                                         wire:click="manage({{ $u->id }})"
                                         dusk="acp-admin-manage-{{ $u->id }}">
                                Manage
                            </x-ui.button>
                            <x-ui.button type="button" variant="danger-ghost" size="sm"
                                         wire:click="revokeAdmin({{ $u->id }})"
                                         wire:confirm="Remove all restricted-admin access from {{ $u->username }}?"
                                         dusk="acp-admin-revoke-{{ $u->id }}">
                                Remove admin
                            </x-ui.button>
                        </div>
                    </div>

                    {{-- Section chips (collapsed view) --}}
                    @if (! empty($row['sections']) && $manageUserId !== $u->id)
                        <div class="flex flex-wrap gap-1 px-4 pb-3 sm:px-5">
                            @foreach ($row['sections'] as $key)
                                <span class="inline-flex items-center rounded bg-surface-sunken px-1.5 py-0.5 text-xs text-ink-muted">
                                    {{ $key }}
                                </span>
                            @endforeach
                        </div>
                    @endif

                    {{-- Per-user editor panel --}}
                    @if ($manageUserId === $u->id)
                        @php($grantedSections = app(AdminBundleService::class)->grantedSections($u))
                        <div class="border-t border-line bg-surface-sunken px-4 py-4 sm:px-5 space-y-4">
                            {{-- Apply a bundle --}}
                            <div>
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-subtle">Apply a bundle preset</p>
                                <div class="flex flex-wrap items-end gap-2">
                                    <x-ui.select name="assignBundleSlug" wire:model="assignBundleSlug" class="max-w-xs">
                                        <option value="">— Choose a bundle —</option>
                                        @foreach ($this->bundles() as $bundle)
                                            <option value="{{ $bundle->slug }}">{{ $bundle->name }}</option>
                                        @endforeach
                                    </x-ui.select>
                                    <x-ui.button type="button" size="sm"
                                                 :disabled="! $assignBundleSlug"
                                                 wire:click="applyBundle({{ $u->id }}, '{{ $assignBundleSlug }}')"
                                                 wire:loading.attr="disabled"
                                                 wire:target="applyBundle">
                                        <span wire:loading.remove wire:target="applyBundle">Apply bundle</span>
                                        <span wire:loading wire:target="applyBundle">Applying…</span>
                                    </x-ui.button>
                                </div>
                                <p class="mt-1 text-xs text-ink-subtle">Applying a bundle replaces this user's current sections.</p>
                            </div>

                            {{-- Per-section toggles --}}
                            <div>
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-subtle">Section access</p>
                                <ul class="divide-y divide-line rounded-lg border border-line bg-surface-raised">
                                    @foreach ($this->sections() as $section)
                                        @php($granted = in_array($section['key'], $grantedSections, true))
                                        <li wire:key="section-{{ $u->id }}-{{ $section['key'] }}"
                                            class="flex items-center justify-between gap-3 px-3 py-2.5">
                                            <span class="text-sm text-ink">{{ $section['label'] }}</span>
                                            <button type="button"
                                                    wire:click="toggleSection({{ $u->id }}, '{{ $section['key'] }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="toggleSection({{ $u->id }}, '{{ $section['key'] }}')"
                                                    aria-pressed="{{ $granted ? 'true' : 'false' }}"
                                                    @class([
                                                        'inline-flex h-6 w-10 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent',
                                                        'bg-accent' => $granted,
                                                        'bg-surface-sunken' => ! $granted,
                                                    ])>
                                                <span @class([
                                                    'pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow transition-transform',
                                                    'translate-x-4' => $granted,
                                                    'translate-x-0' => ! $granted,
                                                ])></span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>

                            <div>
                                <x-ui.button type="button" variant="ghost" size="sm" wire:click="closeManage">
                                    Close
                                </x-ui.button>
                            </div>
                        </div>
                    @endif
                </li>
            @empty
                <li class="px-4 py-6 sm:px-5 text-sm text-ink-subtle">
                    No restricted admins yet. Use the form below to grant a member limited ACP access.
                </li>
            @endforelse
        </ul>
    </x-ui.card>

    {{-- Add a new restricted admin --}}
    <x-ui.card>
        <div class="space-y-4">
            <div>
                <h2 class="text-sm font-semibold text-ink">Make a restricted admin</h2>
                <p class="mt-0.5 text-xs text-ink-muted">
                    Search for a member (not already an admin), choose a bundle, then apply.
                </p>
            </div>

            <x-ui.input label="Search by username" name="newUserQuery" wire:model.live.debounce.300ms="newUserQuery"
                        placeholder="Start typing a username…" dusk="acp-admin-search" />

            @php($found = $this->candidateUsers())
            @if ($newUserQuery !== '' && $found->isNotEmpty())
                <ul class="rounded-lg border border-line divide-y divide-line">
                    @foreach ($found as $candidate)
                        <li wire:key="cand-{{ $candidate->id }}"
                            class="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5">
                            <div class="flex min-w-0 items-center gap-3">
                                <x-ui.avatar :user="$candidate" size="sm" class="shrink-0" />
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-ink">
                                        <x-ui.user-name :user="$candidate" />
                                    </p>
                                    <p class="text-xs text-ink-subtle">{{ $candidate->username }}</p>
                                </div>
                            </div>
                            <div class="flex flex-wrap items-end gap-2">
                                <x-ui.select name="assignBundleSlug_{{ $candidate->id }}"
                                             wire:model="assignBundleSlug"
                                             class="max-w-[12rem]">
                                    <option value="">— Bundle —</option>
                                    @foreach ($this->bundles() as $bundle)
                                        <option value="{{ $bundle->slug }}">{{ $bundle->name }}</option>
                                    @endforeach
                                </x-ui.select>
                                <x-ui.button type="button" size="sm"
                                             :disabled="! $assignBundleSlug"
                                             wire:click="applyBundle({{ $candidate->id }}, '{{ $assignBundleSlug }}')"
                                             wire:loading.attr="disabled"
                                             wire:target="applyBundle({{ $candidate->id }}, '{{ $assignBundleSlug }}')"
                                             dusk="acp-admin-apply-{{ $candidate->id }}">
                                    <span wire:loading.remove wire:target="applyBundle({{ $candidate->id }}, '{{ $assignBundleSlug }}')">Grant access</span>
                                    <span wire:loading wire:target="applyBundle({{ $candidate->id }}, '{{ $assignBundleSlug }}')">Granting…</span>
                                </x-ui.button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @elseif (strlen(trim($newUserQuery)) >= 2 && $found->isEmpty())
                <p class="text-sm text-ink-subtle">No matching members found (already an admin, or no match).</p>
            @endif
        </div>
    </x-ui.card>
</div>
