<?php

// SPDX-License-Identifier: Apache-2.0

use App\Models\Forum;
use App\Models\Group;
use App\Models\ModeratorAssignment;
use App\Models\Role;
use App\Models\User;
use App\Permissions\ForumModeratorProjector;
use App\Permissions\RoleException;
use App\Permissions\RoleManager;
use App\Permissions\Scope;
use Database\Seeders\ModeratorBundleSeeder;
use Livewire\Component;

/**
 * ACP v3 · v3-b — the global "Moderators" pane (Moderation → Moderators). One screen to see every per-forum
 * moderator assignment grouped by forum, and to add/remove across forums. Same engine path + fences as the
 * per-forum tab (the {@see ForumModeratorProjector} is the actor-independent backstop); self-guards in
 * mount()/every action (admin.access + permissions.manage + staff-2FA). Reads verdicts only through the engine.
 */
new class extends Component
{
    public ?int $forumId = null;

    public string $holderType = 'user';

    public string $username = '';

    public ?int $groupId = null;

    public string $source = 'bundle';

    public ?string $bundle = 'forum-mod-full';

    public ?int $customRoleId = null;

    public ?string $message = null;

    public string $messageVariant = 'info';

    public function mount(): void
    {
        $this->ensureManager();
    }

    public function assign(ForumModeratorProjector $projector): void
    {
        $this->ensureManager();

        $forum = $this->forumId !== null ? Forum::find($this->forumId) : null;
        if (! $forum instanceof Forum) {
            $this->flash('Choose a forum.', 'danger');

            return;
        }

        [$holderId, $holderError] = $this->resolveHolder();
        if ($holderError !== null) {
            $this->flash($holderError, 'danger');

            return;
        }

        $role = $this->resolveRole();
        if (! $role instanceof Role) {
            $this->flash('Choose a capability set (a preset bundle or a custom role).', 'danger');

            return;
        }

        try {
            $projector->assign(auth()->user(), $this->holderType, $holderId, (int) $forum->id, $role);
            $this->flash('Moderator assigned to '.$forum->title.'.', 'success');
            $this->username = '';
            $this->groupId = null;
            $this->customRoleId = null;
        } catch (RoleException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    public function revoke(string $holderType, int $holderId, int $forumId, ForumModeratorProjector $projector): void
    {
        $this->ensureManager();
        $projector->revoke($holderType, $holderId, $forumId);
        $this->flash('Moderator removed.', 'success');
    }

    // ── view data ───────────────────────────────────────────────────────────────────────────────────────

    /** @return list<array{forum_id:int, forum:string, rows:list<array{holder_type:string,holder_id:int,holder:string,capability:string}>}> */
    public function byForum(): array
    {
        $assignments = ModeratorAssignment::query()->with('forum')->orderBy('forum_id')->orderBy('id')->get();
        $grouped = [];

        foreach ($assignments as $a) {
            $fid = (int) $a->forum_id;
            $grouped[$fid] ??= [
                'forum_id' => $fid,
                'forum' => (string) ($a->forum?->title ?? ('forum#'.$fid)),
                'rows' => [],
            ];
            $grouped[$fid]['rows'][] = [
                'holder_type' => (string) $a->holder_type,
                'holder_id' => (int) $a->holder_id,
                'holder' => $this->holderLabel($a),
                'capability' => $this->capabilityLabel($a),
            ];
        }

        return array_values($grouped);
    }

    /** @return list<array{id:int,name:string}> board forums (no categories, no club forums). */
    public function forumOptions(): array
    {
        return Forum::query()->where('type', 'forum')->whereNull('club_id')->orderBy('title')->get(['id', 'title'])
            ->map(fn (Forum $f): array => ['id' => (int) $f->id, 'name' => (string) $f->title])->all();
    }

    /** @return array<string,string> */
    public function bundleOptions(): array
    {
        $out = [];
        foreach (ModeratorBundleSeeder::bundles() as $slug => $data) {
            $out[$slug] = $data['name'];
        }

        return $out;
    }

    /** @return list<array{id:int,name:string}> */
    public function customRoles(): array
    {
        return app(RoleManager::class)->customRoles()
            ->map(fn (Role $r): array => ['id' => (int) $r->id, 'name' => (string) $r->name])->all();
    }

    /** @return list<array{id:int,name:string}> */
    public function groupOptions(): array
    {
        return Group::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Group $g): array => ['id' => (int) $g->id, 'name' => (string) $g->name])->all();
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{0:int,1:?string} */
    private function resolveHolder(): array
    {
        if ($this->holderType === 'group') {
            $group = $this->groupId !== null ? Group::find($this->groupId) : null;

            return $group instanceof Group ? [(int) $group->id, null] : [0, 'Choose a group to assign.'];
        }

        $needle = trim($this->username);
        if ($needle === '') {
            return [0, 'Enter the username or email of the user to assign.'];
        }
        $user = User::query()->where('username', $needle)->orWhere('email', $needle)->first();

        return $user instanceof User ? [(int) $user->id, null] : [0, "No user matches “{$needle}”."];
    }

    private function resolveRole(): ?Role
    {
        if ($this->source === 'custom') {
            return $this->customRoleId !== null
                ? Role::query()->whereKey($this->customRoleId)->where('is_preset', false)->first()
                : null;
        }

        return $this->bundle !== null
            ? Role::query()->where('slug', $this->bundle)->where('is_preset', true)->first()
            : null;
    }

    private function holderLabel(ModeratorAssignment $a): string
    {
        $holder = $a->holder();
        if ($holder instanceof User) {
            return (string) ($holder->username ?? $holder->name ?? ('user#'.$a->holder_id));
        }
        if ($holder instanceof Group) {
            return (string) $holder->name.' (group)';
        }

        return $a->holder_type.'#'.$a->holder_id.' (removed)';
    }

    private function capabilityLabel(ModeratorAssignment $a): string
    {
        if ($a->bundle !== null) {
            return ModeratorBundleSeeder::bundles()[$a->bundle]['name'] ?? $a->bundle;
        }
        $role = $a->role_id !== null ? Role::find($a->role_id) : null;

        return $role instanceof Role ? (string) $role->name : 'custom role';
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
};
?>

<div class="space-y-5" dusk="acp-moderators">
    @if ($message)
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    <p class="max-w-2xl text-sm text-ink-muted">
        Assign and review <strong>per-forum moderators</strong> across the board. Powers apply only to the chosen
        forum; build a custom capability set in the <a href="{{ route('admin.groups.roles') }}" class="text-accent underline">role builder</a>.
    </p>

    {{-- Assign form (any forum). --}}
    <x-ui.card>
        <form wire:submit="assign" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select label="Forum" name="forumId" wire:model="forumId">
                    <option value="">Choose a forum…</option>
                    @foreach ($this->forumOptions() as $f)
                        <option value="{{ $f['id'] }}">{{ $f['name'] }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.select label="Assign a" name="holderType" wire:model.live="holderType">
                    <option value="user">User</option>
                    <option value="group">Group</option>
                </x-ui.select>

                @if ($holderType === 'group')
                    <x-ui.select label="Group" name="groupId" wire:model="groupId">
                        <option value="">Choose a group…</option>
                        @foreach ($this->groupOptions() as $g)
                            <option value="{{ $g['id'] }}">{{ $g['name'] }}</option>
                        @endforeach
                    </x-ui.select>
                @else
                    <x-ui.input label="User (username or email)" name="username" wire:model="username"
                                placeholder="e.g. jane or jane@example.com" dusk="acp-mods-username" />
                @endif

                @if ($source === 'custom')
                    <x-ui.select label="Custom role" name="customRoleId" wire:model="customRoleId">
                        <option value="">Choose a custom role…</option>
                        @foreach ($this->customRoles() as $r)
                            <option value="{{ $r['id'] }}">{{ $r['name'] }}</option>
                        @endforeach
                    </x-ui.select>
                @else
                    <x-ui.select label="Bundle" name="bundle" wire:model="bundle">
                        @foreach ($this->bundleOptions() as $slug => $name)
                            <option value="{{ $slug }}">{{ $name }}</option>
                        @endforeach
                    </x-ui.select>
                @endif

                <x-ui.select label="Capability set" name="source" wire:model.live="source">
                    <option value="bundle">Preset bundle</option>
                    <option value="custom">Custom role</option>
                </x-ui.select>
            </div>

            <x-ui.button type="submit" size="sm" wire:loading.attr="disabled" wire:target="assign" dusk="acp-mods-assign">
                <span wire:loading.remove wire:target="assign">Assign moderator</span>
                <span wire:loading wire:target="assign">Assigning…</span>
            </x-ui.button>
        </form>
    </x-ui.card>

    {{-- Every assignment, grouped by forum. --}}
    @forelse ($this->byForum() as $group)
        <x-ui.card flush>
            <div class="border-b border-line px-4 py-3">
                <h2 class="text-sm font-semibold text-ink">{{ $group['forum'] }}</h2>
            </div>
            @foreach ($group['rows'] as $a)
                <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3 last:border-0">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-ink">{{ $a['holder'] }}</p>
                        <p class="text-xs text-ink-subtle">{{ $a['capability'] }}</p>
                    </div>
                    <x-ui.button type="button" variant="danger-ghost" size="sm"
                                 wire:click="revoke('{{ $a['holder_type'] }}', {{ $a['holder_id'] }}, {{ $group['forum_id'] }})"
                                 wire:confirm="Remove this moderator from {{ $group['forum'] }}?">
                        Remove
                    </x-ui.button>
                </div>
            @endforeach
        </x-ui.card>
    @empty
        <x-ui.empty title="No forum moderators yet">Assign a user or group above, or use a forum’s Moderators tab.</x-ui.empty>
    @endforelse
</div>
