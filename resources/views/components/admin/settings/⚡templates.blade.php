<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\SiteTemplate;
use App\Models\User;
use App\Permissions\Scope;
use App\Theme\Sandbox\SandboxException;
use App\Theme\Sandbox\TemplateContract;
use App\Theme\Sandbox\TemplateService;
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
    }

    /** @return list<array{key:string,label:string,description:string,overridden:bool,enabled:bool}> */
    public function rows(): array
    {
        $this->ensureAdmin();
        $out = [];
        foreach (TemplateContract::templates() as $key => $meta) {
            $row = SiteTemplate::query()->where('template_key', $key)->first();
            $out[] = [
                'key' => $key, 'label' => $meta['label'], 'description' => $meta['description'],
                'overridden' => $row !== null, 'enabled' => (bool) ($row->is_enabled ?? false),
            ];
        }

        return $out;
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
                        </div>
                        <p class="truncate text-xs text-ink-subtle">{{ $row['description'] }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1">
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
</div>
