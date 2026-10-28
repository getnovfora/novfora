<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\SiteTemplate;
use App\Models\SiteTemplateHook;
use App\Models\User;
use App\Permissions\Scope;
use App\Theme\Sandbox\SandboxException;
use App\Theme\Sandbox\TemplateContract;
use App\Theme\Sandbox\TemplateService;
use App\Theme\Sandbox\TemplateSync;
use Livewire\Component;

/**
 * Admin → Settings → Templates (the sandboxed template editor, ADR-0038). Edit an OVERRIDABLE template in the
 * restricted sandbox language — NOT PHP/Blade — with live validation, a default to diff against, and revert.
 * A template renders on the site only once enabled. Authorisation is re-asserted in mount() AND every action
 * (Livewire actions bypass route middleware). Flagged for dedicated human security review.
 */
new class extends Component
{
    public ?string $editKey = null;

    public string $source = '';

    public ?string $validationError = null;

    public ?string $message = null;

    public string $messageVariant = 'info';

    public function mount(): void
    {
        $this->ensureAdmin();

        // U11: lazy reconcile — if a code deploy changed shipped defaults and the upgrade-path sync didn't
        // run (or failed best-effort), opening this page catches up. Idempotent; never blocks the page.
        try {
            app(TemplateSync::class)->sync();
        } catch (\Throwable) {
            // surfaced by the per-row state badges instead
        }
    }

    /** @return list<array{key:string,label:string,description:string,overridden:bool,enabled:bool,merge_state:string}> */
    public function rows(): array
    {
        $this->ensureAdmin();
        $out = [];
        foreach (TemplateContract::templates() as $key => $meta) {
            $row = SiteTemplate::query()->where('template_key', $key)->first();
            $out[] = [
                'key' => $key, 'label' => $meta['label'], 'description' => $meta['description'],
                'overridden' => $row !== null, 'enabled' => (bool) ($row->is_enabled ?? false),
                'merge_state' => (string) ($row->merge_state ?? 'current'),
            ];
        }

        return $out;
    }

    // ── U11: merge review (a release changed a default under an admin override) ──

    public ?string $reviewKey = null;

    public function review(string $key): void
    {
        $this->ensureAdmin();
        if (TemplateContract::has($key)) {
            $this->reviewKey = $key;
            $this->editKey = null;
            $this->message = null;
        }
    }

    public function closeReview(): void
    {
        $this->reviewKey = null;
    }

    /** The admin's current source + the new shipped default, for the side-by-side review panel.
     *  @return array{ours:string,theirs:string,state:string} */
    public function reviewData(): array
    {
        if ($this->reviewKey === null) {
            return ['ours' => '', 'theirs' => '', 'state' => 'current'];
        }
        $row = SiteTemplate::query()->where('template_key', $this->reviewKey)->first();

        return [
            'ours' => (string) ($row->source ?? ''),
            'theirs' => TemplateContract::default($this->reviewKey),
            'state' => (string) ($row->merge_state ?? 'current'),
        ];
    }

    /** Keep my override (accept the drift / acknowledge an auto-merge) — the base advances. */
    public function keepMine(): void
    {
        $this->ensureAdmin();
        if ($this->reviewKey === null) {
            return;
        }
        app(TemplateSync::class)->resolveKeepMine($this->reviewKey);
        $this->flash('Kept your version. It stays live.', 'success');
        $this->reviewKey = null;
    }

    /** Discard my override and take the new shipped default. */
    public function takeDefault(): void
    {
        $this->ensureAdmin();
        if ($this->reviewKey === null) {
            return;
        }
        app(TemplateSync::class)->resolveTakeDefault($this->reviewKey);
        $this->flash('Took the new default.', 'success');
        $this->reviewKey = null;
    }

    // ── U11: template-hook fragments (upgrade-safe targeted mods on named anchors) ──

    public bool $showHookForm = false;

    public ?int $hookEditId = null;

    public string $hookKey = '';

    public string $hookName = '';

    public string $hookSource = '';

    public int $hookPosition = 0;

    public ?string $hookValidationError = null;

    /** @return list<array{id:int,hook_key:string,anchor:string,name:string,enabled:bool}> */
    public function hookRows(): array
    {
        $this->ensureAdmin();
        $anchors = TemplateContract::hooks();

        return SiteTemplateHook::query()->orderBy('hook_key')->orderBy('position')->orderBy('id')->get()
            ->map(fn (SiteTemplateHook $h): array => [
                'id' => (int) $h->id,
                'hook_key' => (string) $h->hook_key,
                'anchor' => (string) ($anchors[$h->hook_key]['label'] ?? $h->hook_key),
                'name' => (string) $h->name,
                'enabled' => (bool) $h->is_enabled,
            ])->all();
    }

    public function newHook(): void
    {
        $this->ensureAdmin();
        $this->reset(['hookEditId', 'hookName', 'hookSource', 'hookPosition', 'hookValidationError']);
        $this->hookKey = array_key_first(TemplateContract::hooks()) ?? '';
        $this->showHookForm = true;
        $this->editKey = null;
        $this->reviewKey = null;
    }

    public function editHook(int $id): void
    {
        $this->ensureAdmin();
        $row = SiteTemplateHook::query()->findOrFail($id);
        $this->hookEditId = (int) $row->id;
        $this->hookKey = (string) $row->hook_key;
        $this->hookName = (string) $row->name;
        $this->hookSource = (string) $row->source;
        $this->hookPosition = (int) $row->position;
        $this->revalidateHook();
        $this->showHookForm = true;
        $this->editKey = null;
        $this->reviewKey = null;
    }

    public function updatedHookSource(): void
    {
        $this->revalidateHook();
    }

    public function saveHook(): void
    {
        $this->ensureAdmin();
        try {
            app(TemplateService::class)->saveHook($this->hookEditId, $this->hookKey, $this->hookName, $this->hookSource, $this->hookPosition);
            $this->flash('Fragment saved. It’s live at its anchor now.', 'success');
            $this->showHookForm = false;
        } catch (SandboxException $e) {
            $this->hookValidationError = $e->getMessage();
            $this->flash('Could not save — fix the highlighted error first.', 'danger');
        } catch (\InvalidArgumentException $e) {
            $this->hookValidationError = $e->getMessage();
        }
    }

    public function toggleHook(int $id, bool $enabled): void
    {
        $this->ensureAdmin();
        app(TemplateService::class)->setHookEnabled($id, $enabled);
        $this->flash($enabled ? 'Fragment enabled.' : 'Fragment disabled.', 'success');
    }

    public function deleteHook(int $id): void
    {
        $this->ensureAdmin();
        app(TemplateService::class)->removeHook($id);
        if ($this->hookEditId === $id) {
            $this->showHookForm = false;
        }
        $this->flash('Fragment removed.', 'success');
    }

    public function cancelHook(): void
    {
        $this->showHookForm = false;
    }

    /** @return array<string,string> hook key => label, for the anchor select */
    public function hookAnchorOptions(): array
    {
        $out = [];
        foreach (TemplateContract::hooks() as $key => $meta) {
            $out[$key] = $meta['label'];
        }

        return $out;
    }

    /** @return array<string,string> the selected anchor's variables, for the hint panel */
    public function hookVariables(): array
    {
        return TemplateContract::hooks()[$this->hookKey]['variables'] ?? [];
    }

    private function revalidateHook(): void
    {
        try {
            app(TemplateService::class)->lint($this->hookSource);
            $this->hookValidationError = null;
        } catch (SandboxException $e) {
            $this->hookValidationError = $e->getMessage();
        }
    }

    public function edit(string $key): void
    {
        $this->ensureAdmin();
        if (! TemplateContract::has($key)) {
            return;
        }
        $this->editKey = $key;
        $this->source = app(TemplateService::class)->source($key);
        $this->revalidate();
        $this->message = null;
        $this->reviewKey = null;
        $this->showHookForm = false;
    }

    public function updatedSource(): void
    {
        $this->revalidate();
    }

    public function save(): void
    {
        $this->ensureAdmin();
        if ($this->editKey === null) {
            return;
        }
        try {
            app(TemplateService::class)->save($this->editKey, $this->source);
            $this->validationError = null;
            $this->flash('Saved and enabled. It’s live now.', 'success');
        } catch (SandboxException $e) {
            $this->validationError = $e->getMessage();
            $this->flash('Could not save — fix the highlighted error first.', 'danger');
        }
    }

    public function customize(string $key): void
    {
        $this->ensureAdmin();
        if (! TemplateContract::has($key)) {
            return;
        }
        app(TemplateService::class)->save($key, TemplateContract::default($key));
        $this->edit($key);
        $this->flash('Enabled from the default — tweak it and save.', 'success');
    }

    public function revert(): void
    {
        $this->ensureAdmin();
        if ($this->editKey === null) {
            return;
        }
        app(TemplateService::class)->revert($this->editKey);
        $this->source = app(TemplateService::class)->source($this->editKey);
        $this->revalidate();
        $this->flash('Reverted to the shipped default.', 'success');
    }

    public function setEnabled(string $key, bool $enabled): void
    {
        $this->ensureAdmin();
        app(TemplateService::class)->setEnabled($key, $enabled);
        $this->flash($enabled ? 'Enabled.' : 'Disabled.', 'success');
    }

    public function remove(string $key): void
    {
        $this->ensureAdmin();
        app(TemplateService::class)->remove($key);
        if ($this->editKey === $key) {
            $this->editKey = null;
        }
        $this->flash('Removed — back to the stock layout.', 'success');
    }

    public function close(): void
    {
        $this->editKey = null;
        $this->message = null;
    }

    public function defaultSource(): string
    {
        return $this->editKey !== null ? TemplateContract::default($this->editKey) : '';
    }

    /** @return array<string,string> */
    public function variables(): array
    {
        return $this->editKey !== null ? (TemplateContract::templates()[$this->editKey]['variables'] ?? []) : [];
    }

    public function isModified(): bool
    {
        return $this->editKey !== null && trim($this->source) !== trim($this->defaultSource());
    }

    private function revalidate(): void
    {
        try {
            app(TemplateService::class)->lint($this->source);
            $this->validationError = null;
        } catch (SandboxException $e) {
            $this->validationError = $e->getMessage();
        }
    }

    private function flash(string $message, string $variant = 'info'): void
    {
        $this->message = $message;
        $this->messageVariant = $variant;
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('admin.access', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-5" dusk="acp-templates">
    @if ($message)
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    <p class="text-sm text-ink-muted max-w-2xl">
        Customise parts of the site with a small, safe template language — variables, <code class="text-xs">if</code>
        and <code class="text-xs">for</code>, and a few helpers. <strong>No PHP, no scripts</strong>: values are
        auto-escaped and the renderer is sandboxed. Each template renders only once you enable it.
    </p>

    {{-- The overridable templates. --}}
    <x-ui.card flush>
        <ul class="divide-y divide-line">
            @foreach ($this->rows() as $row)
                <li class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5 text-sm">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-ink">{{ $row['label'] }}</span>
                            @if ($row['overridden'] && $row['enabled'])
                                <x-ui.badge variant="accent">Enabled</x-ui.badge>
                            @elseif ($row['overridden'])
                                <x-ui.badge>Disabled</x-ui.badge>
                            @endif
                            @if ($row['merge_state'] === 'conflict')
                                <x-ui.badge variant="warn" dusk="acp-tpl-conflict-{{ $row['key'] }}">Update conflict</x-ui.badge>
                            @elseif ($row['merge_state'] === 'merged')
                                <x-ui.badge variant="neutral" dusk="acp-tpl-merged-{{ $row['key'] }}">Auto-merged</x-ui.badge>
                            @endif
                        </div>
                        <p class="truncate text-xs text-ink-subtle">{{ $row['description'] }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1">
                        @if ($row['merge_state'] !== 'current')
                            <x-ui.button type="button" size="sm" wire:click="review('{{ $row['key'] }}')" dusk="acp-tpl-review-{{ $row['key'] }}">Review</x-ui.button>
                        @endif
                        @if ($row['overridden'])
                            <x-ui.button type="button" variant="subtle" size="sm" wire:click="edit('{{ $row['key'] }}')" dusk="acp-tpl-edit-{{ $row['key'] }}">Edit</x-ui.button>
                            @if ($row['enabled'])
                                <x-ui.button type="button" variant="ghost" size="sm" wire:click="setEnabled('{{ $row['key'] }}', false)">Disable</x-ui.button>
                            @else
                                <x-ui.button type="button" variant="ghost" size="sm" wire:click="setEnabled('{{ $row['key'] }}', true)">Enable</x-ui.button>
                            @endif
                            <x-ui.button type="button" variant="danger-ghost" size="sm" wire:click="remove('{{ $row['key'] }}')">Remove</x-ui.button>
                        @else
                            <x-ui.button type="button" size="sm" wire:click="customize('{{ $row['key'] }}')" dusk="acp-tpl-customize-{{ $row['key'] }}">Customise</x-ui.button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </x-ui.card>

    {{-- U11: merge-review panel — a release changed this template's shipped default under your override. --}}
    @if ($reviewKey !== null)
        @php($rv = $this->reviewData())
        <x-ui.card dusk="acp-tpl-review-panel">
            <div class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-ink">Review: {{ $reviewKey }}</h2>
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="closeReview">Close</x-ui.button>
                </div>
                @if ($rv['state'] === 'conflict')
                    <x-ui.alert variant="warn">
                        This release changed the shipped default in a way that overlaps your edit. <strong>Your
                        version is still live</strong> — nothing broke. Pick a resolution, or edit it by hand.
                    </x-ui.alert>
                @else
                    <x-ui.alert variant="info">
                        This release changed the shipped default and your edit was merged automatically. Check
                        the result — “Keep” accepts it as-is.
                    </x-ui.alert>
                @endif
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <p class="mb-1 text-xs font-semibold text-ink-muted">{{ $rv['state'] === 'conflict' ? 'Your version (live)' : 'Merged result (live)' }}</p>
                        <pre class="max-h-72 overflow-auto rounded-md border border-line bg-surface-sunken p-2 text-xs whitespace-pre-wrap break-words">{{ $rv['ours'] }}</pre>
                    </div>
                    <div>
                        <p class="mb-1 text-xs font-semibold text-ink-muted">New shipped default</p>
                        <pre class="max-h-72 overflow-auto rounded-md border border-line bg-surface-sunken p-2 text-xs whitespace-pre-wrap break-words">{{ $rv['theirs'] }}</pre>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button type="button" wire:click="keepMine" dusk="acp-tpl-keep-mine">{{ $rv['state'] === 'conflict' ? 'Keep my version' : 'Keep the merge' }}</x-ui.button>
                    <x-ui.button type="button" variant="subtle" wire:click="takeDefault" dusk="acp-tpl-take-default">Take the new default</x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="edit('{{ $reviewKey }}')">Edit by hand</x-ui.button>
                </div>
            </div>
        </x-ui.card>
    @endif

    {{-- Editor panel. --}}
    @if ($editKey !== null)
        <x-ui.card>
            <div class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-ink">Editing: {{ $editKey }}
                        @if ($this->isModified()) <span class="ml-1 text-xs font-normal text-ink-subtle">(modified from default)</span> @endif
                    </h2>
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="close">Close</x-ui.button>
                </div>

                {{-- Available variables. --}}
                <div class="rounded-md border border-line bg-surface-sunken p-3">
                    <p class="mb-1 text-xs font-semibold text-ink-muted">Available variables</p>
                    <dl class="grid gap-x-4 gap-y-0.5 text-xs sm:grid-cols-2">
                        @foreach ($this->variables() as $name => $desc)
                            <div class="flex gap-2"><dt class="font-mono text-accent">{{ $name }}</dt><dd class="text-ink-subtle">— {{ $desc }}</dd></div>
                        @endforeach
                    </dl>
                </div>

                <div>
                    <label for="tpl-source" class="block text-xs font-medium text-ink-muted">Template source</label>
                    <textarea id="tpl-source" wire:model.live.debounce.400ms="source" rows="10" spellcheck="false"
                              class="mt-1 w-full rounded-md border bg-surface px-2 py-1.5 font-mono text-xs text-ink {{ $validationError ? 'border-danger' : 'border-line' }}"></textarea>
                    @if ($validationError)
                        <p class="mt-1 text-xs text-danger" dusk="acp-tpl-error">⚠ {{ $validationError }}</p>
                    @else
                        <p class="mt-1 text-xs text-success">✓ Valid template.</p>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button type="button" wire:click="save" dusk="acp-tpl-save">Save &amp; enable</x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="revert">Revert to default</x-ui.button>
                </div>

                {{-- Diff-vs-default: the shipped default, read-only, to compare against. --}}
                <details class="rounded-md border border-line p-3" @if ($this->isModified()) open @endif>
                    <summary class="cursor-pointer text-xs font-semibold text-ink-muted">Shipped default (compare / reference)</summary>
                    <pre class="mt-2 overflow-x-auto whitespace-pre-wrap break-words text-xs text-ink-subtle">{{ $this->defaultSource() }}</pre>
                </details>
            </div>
        </x-ui.card>
    @endif

    {{-- U11: template-hook fragments — targeted, upgrade-safe additions at named anchors. --}}
    <div class="flex flex-wrap items-center justify-between gap-2 pt-2">
        <p class="text-sm text-ink-muted max-w-2xl">
            <strong>Hook fragments</strong> attach a snippet (same safe template language) to a <em>named
            anchor</em> — below the site header, at every post’s foot, and so on. Because they anchor by name,
            core updates never disturb them: the upgrade-safe way to make targeted changes.
        </p>
        <x-ui.button type="button" size="sm" wire:click="newHook" dusk="acp-hook-new">
            <x-ui.icon name="plus" class="h-4 w-4" /> New fragment
        </x-ui.button>
    </div>

    @if ($showHookForm)
        <x-ui.card dusk="acp-hook-form">
            <div class="space-y-4">
                <h2 class="text-sm font-semibold text-ink">{{ $hookEditId ? 'Edit fragment' : 'New fragment' }}</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="hook-anchor" class="block text-xs font-medium text-ink-muted">Anchor</label>
                        <select id="hook-anchor" wire:model.live="hookKey"
                                class="mt-1 w-full rounded-md border border-line bg-surface px-2 py-1.5 text-sm text-ink">
                            @foreach ($this->hookAnchorOptions() as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="hook-name" class="block text-xs font-medium text-ink-muted">Name (for you)</label>
                        <input id="hook-name" type="text" wire:model="hookName" maxlength="100"
                               class="mt-1 w-full rounded-md border border-line bg-surface px-2 py-1.5 text-sm text-ink" />
                    </div>
                    <div>
                        <label for="hook-position" class="block text-xs font-medium text-ink-muted">Position (sort)</label>
                        <input id="hook-position" type="number" wire:model="hookPosition" min="0" max="999"
                               class="mt-1 w-full rounded-md border border-line bg-surface px-2 py-1.5 text-sm text-ink" />
                    </div>
                </div>

                <div class="rounded-md border border-line bg-surface-sunken p-3">
                    <p class="mb-1 text-xs font-semibold text-ink-muted">Variables at this anchor</p>
                    <dl class="grid gap-x-4 gap-y-0.5 text-xs sm:grid-cols-2">
                        @foreach ($this->hookVariables() as $name => $desc)
                            <div class="flex gap-2"><dt class="font-mono text-accent">{{ $name }}</dt><dd class="text-ink-subtle">— {{ $desc }}</dd></div>
                        @endforeach
                    </dl>
                </div>

                <div>
                    <label for="hook-source" class="block text-xs font-medium text-ink-muted">Fragment source</label>
                    <textarea id="hook-source" wire:model.live.debounce.400ms="hookSource" rows="6" spellcheck="false"
                              class="mt-1 w-full rounded-md border bg-surface px-2 py-1.5 font-mono text-xs text-ink {{ $hookValidationError ? 'border-danger' : 'border-line' }}"></textarea>
                    @if ($hookValidationError)
                        <p class="mt-1 text-xs text-danger" dusk="acp-hook-error">⚠ {{ $hookValidationError }}</p>
                    @else
                        <p class="mt-1 text-xs text-success">✓ Valid fragment.</p>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button type="button" wire:click="saveHook" dusk="acp-hook-save">Save fragment</x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="cancelHook">Cancel</x-ui.button>
                </div>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card flush>
        <ul class="divide-y divide-line">
            @forelse ($this->hookRows() as $hook)
                <li class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5 text-sm">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-ink">{{ $hook['name'] }}</span>
                            @unless ($hook['enabled'])
                                <x-ui.badge>Disabled</x-ui.badge>
                            @endunless
                        </div>
                        <p class="truncate text-xs text-ink-subtle">{{ $hook['anchor'] }} <span class="font-mono">({{ $hook['hook_key'] }})</span></p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1">
                        <x-ui.button type="button" variant="subtle" size="sm" wire:click="editHook({{ $hook['id'] }})" dusk="acp-hook-edit-{{ $hook['id'] }}">Edit</x-ui.button>
                        @if ($hook['enabled'])
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="toggleHook({{ $hook['id'] }}, false)">Disable</x-ui.button>
                        @else
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="toggleHook({{ $hook['id'] }}, true)">Enable</x-ui.button>
                        @endif
                        <x-ui.button type="button" variant="danger-ghost" size="sm" wire:click="deleteHook({{ $hook['id'] }})">Remove</x-ui.button>
                    </div>
                </li>
            @empty
                <li class="px-4 py-6 sm:px-5 text-sm text-ink-subtle">No hook fragments yet. They’re the upgrade-safe way to add content at fixed points across the site.</li>
            @endforelse
        </ul>
    </x-ui.card>
</div>
