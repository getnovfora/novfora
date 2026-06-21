<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\Club;
use App\Models\Forum;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\PermissionInspector;
use App\Permissions\Scope;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component
{
    public string $userRef = '';

    public string $permission = '';

    public string $scopeRef = 'global';

    /** @var array<string,mixed>|null */
    public ?array $report = null;

    public ?string $error = null;

    /** Pre-fill the scope (and a sensible default permission) from a ?scope= link — e.g. the structure
     *  manager's per-board "Permissions" action lands here scoped to that node. */
    public function mount(): void
    {
        $scope = request()->query('scope');
        if (is_string($scope) && $scope !== '') {
            $this->scopeRef = $scope;
            $this->permission = 'forum.view';
        }
    }

    public function inspect(): void
    {
        $this->report = null;
        $this->error = null;

        $user = is_numeric($this->userRef)
            ? User::find((int) $this->userRef)
            : User::where('email', $this->userRef)->orWhere('username', $this->userRef)->first();

        if (! $user) {
            $this->error = "No user matched [{$this->userRef}].";

            return;
        }

        try {
            $scope = Scope::parse($this->scopeRef !== '' ? $this->scopeRef : 'global');
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->report = app(PermissionInspector::class)->inspect($user, trim($this->permission), $scope);
    }

    /**
     * The plain-language layer over the resolver trace (polish R3). Pure, read-only presentation: it maps
     * the report's reason code, scope, and holder refs to a faithful human sentence + named facts, WITHOUT
     * touching the resolver or the inspector core. Returns null until a report exists. Every dynamic value
     * is escaped by Blade on output (no {!! !!}), so arbitrary usernames / titles can never inject markup.
     *
     * @return array{verdict:string, granted:bool, sentence:string, permission_label:string,
     *               permission_description:?string, scope_name:string, decided_by_name:string}|null
     */
    public function explanation(): ?array
    {
        $r = $this->report;
        if ($r === null) {
            return null;
        }

        $reason = (string) $r['reason'];
        $granted = (bool) $r['granted'];

        // Permission catalog label + description (read-only). Fall back to the raw key when the key has no
        // catalog row (a typo, or a not-yet-seeded permission) — explain, never error.
        $perm = Permission::query()->where('key', (string) $r['permission'])->first();
        $label = (string) ($perm?->label ?: $r['permission']);
        $description = $perm?->description ?: null;

        // The deciding scope. 'default' records no decided scope, so we name the inspected one. The facts strip
        // gets a bare label ("site-wide", "the General Discussion forum"); the sentence gets a prepositional
        // phrase ("site-wide", "in the General Discussion forum") so global reads as an adverb, not "in …".
        $scopeKey = (string) ($r['decided_at_scope'] ?? $r['scope']);
        $scopeLabel = $this->scopeLabel($scopeKey);

        // The holder phrase used inside the sentence ("the Moderators group", "duskadmin", …) and the short
        // name shown in the facts strip. Only group_allow / never interpolate :holder into the sentence.
        [$holderPhrase, $decidedByName] = $this->decidedBy($r);

        $sentence = (string) __("admin.inspector.reason.{$reason}", [
            'user' => (string) $r['user']['label'],
            'permission' => Str::lcfirst($label),
            'scope' => $this->scopePhrase($scopeKey),
            'holder' => $holderPhrase,
        ]);

        // A grant decided below the global scope overrides the broader default (mirrors most-specific-first).
        $decidedScope = $r['decided_at_scope'] !== null ? (string) $r['decided_at_scope'] : null;
        if (in_array($reason, ['user_allow', 'group_allow'], true)
            && $decidedScope !== null && ! str_starts_with($decidedScope, 'global')) {
            $sentence .= (string) __('admin.inspector.override');
        }

        return [
            'verdict' => (string) __('admin.inspector.verdict.'.($granted ? 'allowed' : 'denied')),
            'granted' => $granted,
            'sentence' => $sentence,
            'permission_label' => $label,
            'permission_description' => $description !== null ? (string) $description : null,
            'scope_name' => $scopeLabel,
            'decided_by_name' => $decidedByName,
        ];
    }

    /** A scope key ("global:*", "forum:2", …) → a bare human label for the facts strip: "site-wide", "the
     *  General Discussion forum". Orphaned scopes degrade to "a forum (#2)" — never a raw "forum:2" code. */
    private function scopeLabel(string $key): string
    {
        [$type, $id] = array_pad(explode(':', $key, 2), 2, null);

        if ($type === 'global') {
            return (string) __('admin.inspector.scope.global');
        }

        $level = (string) trans("admin.inspector.scope.level.{$type}");
        $level = str_starts_with($level, 'admin.inspector.') ? (string) $type : $level;

        $name = match ($type) {
            'forum', 'category' => Forum::query()->whereKey($id)->value('title'),
            'club' => Club::query()->whereKey($id)->value('name'),
            'thread' => Topic::query()->whereKey($id)->value('title'),
            default => null,
        };

        return ($name === null || $name === '')
            ? (string) __('admin.inspector.scope.unknown', ['level' => $level, 'id' => (string) $id])
            : (string) __('admin.inspector.scope.named', ['name' => (string) $name, 'level' => $level]);
    }

    /** The scope as the sentence's prepositional :scope slot: "site-wide" (global, an adverb) or "in the
     *  General Discussion forum" (a named/orphaned scope). Keeps every reason template preposition-free. */
    private function scopePhrase(string $key): string
    {
        return str_starts_with($key, 'global')
            ? (string) __('admin.inspector.scope.global')
            : (string) __('admin.inspector.scope.in', ['place' => $this->scopeLabel($key)]);
    }

    /**
     * The deciding holder, as [sentence phrase, facts-strip name]. group_allow names the granting group(s)
     * from the trace (the resolver records the literal 'group'); never reads decided_by; the rest need no
     * holder in the sentence ('' — the template omits :holder).
     *
     * @param  array<string,mixed>  $r
     * @return array{0:string,1:string}
     */
    private function decidedBy(array $r): array
    {
        $reason = (string) $r['reason'];

        if ($reason === 'group_allow') {
            $names = $this->grantingGroupNames($r);
            $phrase = match (count($names)) {
                0 => (string) __('admin.inspector.holder.some_group'),
                1 => (string) __('admin.inspector.holder.group_one', ['name' => $names[0]]),
                default => (string) __('admin.inspector.holder.group_many', ['names' => $this->joinNames($names)]),
            };
            $facts = $names === [] ? (string) __('admin.inspector.holder.some_group') : $this->joinNames($names);

            return [$phrase, $facts];
        }

        if ($reason === 'never') {
            $ref = (string) ($r['decided_by'] ?? '');

            return [$this->holderPhrase($ref), $this->holderShortName($ref)];
        }

        if ($reason === 'user_allow') {
            return ['', (string) $r['user']['label']];
        }

        if ($reason === 'banned') {
            return ['', (string) __('admin.inspector.holder.ban')];
        }

        return ['', (string) __('admin.inspector.holder.none')];
    }

    /** The group(s) whose ALLOW carried the decision: trace rows at the deciding scope, group holders, ALLOW.
     *  @param  array<string,mixed>  $r
     *  @return list<string> */
    private function grantingGroupNames(array $r): array
    {
        $decided = $r['decided_at_scope'];
        $ids = [];
        foreach ((array) ($r['entries'] ?? []) as $row) {
            if (($row['scope'] ?? null) === $decided
                && str_starts_with((string) ($row['holder'] ?? ''), 'group#')
                && ($row['value'] ?? null) === 'Allow') {
                $ids[] = (int) substr((string) $row['holder'], strlen('group#'));
            }
        }

        return $ids === []
            ? []
            : array_values(Group::query()->whereKey($ids)->orderBy('id')->pluck('name')->map(fn ($n) => (string) $n)->all());
    }

    /** A holder ref ("group#7", "user#3") → a sentence phrase ("the Moderators group", "duskadmin"). */
    private function holderPhrase(string $ref): string
    {
        if (str_starts_with($ref, 'group#')) {
            $id = (int) substr($ref, strlen('group#'));
            $name = Group::query()->whereKey($id)->value('name');

            return $name !== null && $name !== ''
                ? (string) __('admin.inspector.holder.group_one', ['name' => (string) $name])
                : (string) __('admin.inspector.holder.unknown_group', ['id' => (string) $id]);
        }

        if (str_starts_with($ref, 'user#')) {
            $id = (int) substr($ref, strlen('user#'));
            $user = User::find($id);

            return $user !== null
                ? (string) ($user->username ?? $user->name ?? __('admin.inspector.holder.unknown_user', ['id' => (string) $id]))
                : (string) __('admin.inspector.holder.unknown_user', ['id' => (string) $id]);
        }

        return $ref;
    }

    /** The short, label-only holder name for the facts strip ("Moderators", "duskadmin"). */
    private function holderShortName(string $ref): string
    {
        if (str_starts_with($ref, 'group#')) {
            $id = (int) substr($ref, strlen('group#'));

            return (string) (Group::query()->whereKey($id)->value('name') ?? __('admin.inspector.holder.unknown_group', ['id' => (string) $id]));
        }

        if (str_starts_with($ref, 'user#')) {
            $id = (int) substr($ref, strlen('user#'));
            $user = User::find($id);

            if ($user !== null) {
                return (string) ($user->username ?? $user->name ?? __('admin.inspector.holder.unknown_user', ['id' => (string) $id]));
            }

            return (string) __('admin.inspector.holder.unknown_user', ['id' => (string) $id]);
        }

        return $ref;
    }

    /** @param  list<string>  $names */
    private function joinNames(array $names): string
    {
        if (count($names) <= 1) {
            return $names[0] ?? '';
        }

        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }
};
?>

<div class="space-y-5">
    <x-ui.card>
        <form wire:submit="inspect" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
            <div class="space-y-1.5">
                <label for="pi-user" class="block text-sm font-medium text-ink">User (id or email)</label>
                <input id="pi-user" wire:model="userRef" required
                       class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink placeholder:text-ink-subtle border border-line transition-colors focus:border-accent">
            </div>
            <div class="space-y-1.5">
                <label for="pi-permission" class="block text-sm font-medium text-ink">Permission key</label>
                <input id="pi-permission" wire:model="permission" required placeholder="forum.post.create"
                       class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink placeholder:text-ink-subtle border border-line transition-colors focus:border-accent">
            </div>
            <div class="space-y-1.5">
                <label for="pi-scope" class="block text-sm font-medium text-ink">Scope</label>
                <input id="pi-scope" wire:model="scopeRef" placeholder="global | forum:2 | thread:1"
                       class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink placeholder:text-ink-subtle border border-line transition-colors focus:border-accent">
            </div>
            <div class="flex items-center gap-3">
                <x-ui.button type="submit" wire:loading.attr="disabled">Explain</x-ui.button>
                <span wire:loading class="text-xs text-ink-subtle">resolving…</span>
            </div>
        </form>
    </x-ui.card>

    @if ($error)
        <x-ui.alert variant="danger">{{ $error }}</x-ui.alert>
    @endif

    @if ($report)
        @php($granted = $report['granted'])
        @php($exp = $this->explanation())

        {{-- Plain-language explanation (polish R3): the human "why can/can't X do Y" ABOVE the raw trace.
             Read-only — it presents the SAME decision; the machine summary + technical detail + candidate
             entries below are unchanged for power users. All values escaped via {{ }} (no {!! !!}). --}}
        @if ($exp)
            <x-ui.card>
                <div class="space-y-3">
                    <div class="flex items-center gap-2">
                        <x-ui.badge :variant="$exp['granted'] ? 'success' : 'danger'">{{ $exp['verdict'] }}</x-ui.badge>
                        <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ __('admin.inspector.heading') }}</span>
                    </div>

                    <p class="text-base leading-relaxed text-ink">{{ $exp['sentence'] }}</p>

                    <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="text-ink-subtle">{{ __('admin.inspector.fact_permission') }}</dt>
                            <dd class="font-medium text-ink">{{ $exp['permission_label'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-ink-subtle">{{ __('admin.inspector.fact_decided_by') }}</dt>
                            <dd class="font-medium text-ink">{{ $exp['decided_by_name'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-ink-subtle">{{ __('admin.inspector.fact_scope') }}</dt>
                            <dd class="font-medium text-ink">{{ $exp['scope_name'] }}</dd>
                        </div>
                    </dl>

                    @if ($exp['permission_description'])
                        <p class="text-sm text-ink-muted">
                            <span class="font-medium text-ink-subtle">{{ __('admin.inspector.about_permission') }}:</span>
                            {{ $exp['permission_description'] }}
                        </p>
                    @endif
                </div>
            </x-ui.card>
        @endif

        <h3 class="text-sm font-semibold text-ink">{{ __('admin.inspector.technical_heading') }}</h3>
        <x-ui.alert :variant="$granted ? 'success' : 'danger'" :title="$granted ? 'ALLOWED' : 'DENIED'">
            {{ $report['summary'] }}
        </x-ui.alert>

        {{-- Resolution detail: label/value rows that reflow to stacked on mobile. --}}
        <x-ui.card flush>
            <dl class="divide-y divide-line text-sm">
                <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-[10rem_1fr] sm:gap-3 sm:px-5">
                    <dt class="text-ink-subtle">User</dt>
                    <dd class="text-ink"><strong class="font-semibold">{{ $report['user']['label'] }}</strong> (#{{ $report['user']['id'] }}, {{ $report['user']['status'] }})</dd>
                </div>
                <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-[10rem_1fr] sm:gap-3 sm:px-5">
                    <dt class="text-ink-subtle">Permission</dt>
                    <dd class="text-ink"><code class="font-mono">{{ $report['permission'] }}</code></dd>
                </div>
                <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-[10rem_1fr] sm:gap-3 sm:px-5">
                    <dt class="text-ink-subtle">Scope</dt>
                    <dd class="text-ink"><code class="font-mono">{{ $report['scope'] }}</code></dd>
                </div>
                <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-[10rem_1fr] sm:gap-3 sm:px-5">
                    <dt class="text-ink-subtle">Decisive rule</dt>
                    <dd class="text-ink">
                        <code class="font-mono">{{ $report['reason'] }}</code>@if ($report['decided_by']) <span class="text-ink-subtle">by {{ $report['decided_by'] }} @ {{ $report['decided_at_scope'] ?? '—' }}</span>@endif
                    </dd>
                </div>
                <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-[10rem_1fr] sm:gap-3 sm:px-5">
                    <dt class="text-ink-subtle">Scope chain</dt>
                    <dd class="text-ink"><code class="font-mono break-words">{{ implode('  →  ', $report['scope_chain']) }}</code></dd>
                </div>
                <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-[10rem_1fr] sm:gap-3 sm:px-5">
                    <dt class="text-ink-subtle">Holders</dt>
                    <dd class="text-ink">{{ implode(', ', $report['holders']) }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <section class="space-y-2">
            <h3 class="text-sm font-semibold text-ink">Candidate ACL entries</h3>
            @if ($report['entries'] === [])
                <x-ui.card>
                    <p class="text-sm text-ink-muted">No entries matched these holders for this permission in this chain — deny-by-default.</p>
                </x-ui.card>
            @else
                <x-ui.card flush>
                    <div class="hidden sm:grid grid-cols-3 gap-3 px-4 py-2.5 sm:px-5 border-b border-line bg-surface-sunken text-xs font-semibold uppercase tracking-wide text-ink-subtle">
                        <span>Holder</span>
                        <span>Scope</span>
                        <span>Value</span>
                    </div>
                    <div class="divide-y divide-line">
                        @foreach ($report['entries'] as $entry)
                            <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-3 sm:items-center sm:gap-3 sm:px-5 text-sm">
                                <span class="text-ink"><code class="font-mono">{{ $entry['holder'] }}</code></span>
                                <span class="text-ink"><code class="font-mono">{{ $entry['scope'] }}</code></span>
                                <span class="text-ink-muted">{{ $entry['value'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif
        </section>
    @endif
</div>
