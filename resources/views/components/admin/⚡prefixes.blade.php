<?php
// SPDX-License-Identifier: Apache-2.0
use App\Forum\PrefixException;
use App\Forum\PrefixManager;
use App\Models\Forum;
use App\Models\Prefix;
use App\Models\User;
use App\Permissions\Scope;
use App\Support\GroupColor;
use Livewire\Component;

/**
 * Admin → Prefixes — the topic-prefix manager: list / create / edit / delete prefixes, and set their
 * label, colour, forum scope, and position. Like every admin SFC the authorization is re-asserted in
 * mount() AND every action, because Livewire actions reach the component via livewire/update with no
 * route middleware.
 */
new class extends Component
{
    public bool $showForm = false;

    public ?int $formId = null; // null = creating

    public string $label = '';

    public string $colorToken = '';

    public string $forumId = ''; // '' = global

    public int $position = 0;

    public ?int $deleteId = null;

    public ?string $message = null;

    public string $messageVariant = 'info';

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function newPrefix(): void
    {
        $this->ensureAdmin();
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->ensureAdmin();
        $prefix = Prefix::findOrFail($id);
        $this->formId = $prefix->id;
        $this->label = (string) $prefix->label;
        $this->colorToken = (string) ($prefix->color_token ?? '');
        $this->forumId = $prefix->forum_id !== null ? (string) $prefix->forum_id : '';
        $this->position = (int) $prefix->position;
        $this->deleteId = null;
        $this->showForm = true;
    }

    public function save(PrefixManager $manager): void
    {
        $this->ensureAdmin();
        $data = $this->validate([
            'label' => ['required', 'string', 'max:60'],
            'colorToken' => ['nullable', 'string', 'max:20'],
            'forumId' => ['nullable', 'string'],
            'position' => ['integer', 'min:0'],
        ]);

        $payload = [
            'label' => $data['label'],
            'color_token' => $data['colorToken'] ?? null,
            'forum_id' => ($data['forumId'] ?? '') !== '' ? $data['forumId'] : null,
            'position' => $data['position'] ?? 0,
        ];

        try {
            if ($this->formId === null) {
                $prefix = $manager->create($payload);
                $this->flash('Created prefix “'.$prefix->label.'”.', 'success');
            } else {
                $prefix = $manager->update(Prefix::findOrFail($this->formId), $payload);
                $this->flash('Saved prefix “'.$prefix->label.'”.', 'success');
            }
            $this->cancelForm();
        } catch (PrefixException $e) {
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
        $this->showForm = false;
        $this->message = null;
    }

    public function cancelDelete(): void
    {
        $this->deleteId = null;
    }

    public function delete(PrefixManager $manager): void
    {
        $this->ensureAdmin();
        if ($this->deleteId === null) {
            return;
        }

        $prefix = Prefix::findOrFail($this->deleteId);

        try {
            $manager->delete($prefix);
            $this->flash('Deleted "'.$prefix->label.'".', 'success');
            $this->deleteId = null;
        } catch (PrefixException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    /** @return list<array{prefix:Prefix,forumName:string}> */
    public function rows(): array
    {
        $this->ensureAdmin();

        return Prefix::query()
            ->with('forum')
            ->orderBy('forum_id')
            ->orderBy('position')
            ->orderBy('label')
            ->get()
            ->map(fn (Prefix $p): array => [
                'prefix' => $p,
                'forumName' => $p->forum?->title ?? 'Global',
            ])->all();
    }

    /** Forums available for the scope select (null = global). @return list<array{id:int,title:string}> */
    public function forumOptions(): array
    {
        return Forum::query()
            ->where('type', '!=', 'category')
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(fn (Forum $f): array => ['id' => (int) $f->id, 'title' => (string) $f->title])
            ->all();
    }

    public function colorOptions(): array
    {
        return GroupColor::PALETTE;
    }

    private function resetForm(): void
    {
        $this->reset(['formId', 'label', 'colorToken', 'forumId', 'position']);
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
        abort_unless($user->canDo('prefix.manage', Scope::global()), 403);
    }
};
?>

<div class="space-y-5" dusk="acp-prefixes">
    @if ($message)
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-ink-muted max-w-2xl">
            Topic prefixes are short coloured labels (e.g. "Guide", "Question", "Solved") that authors can attach
            to a topic. A prefix with <strong>Global</strong> scope is available in every forum; a forum-specific
            prefix appears only in that forum.
        </p>
        <x-ui.button type="button" size="sm" wire:click="newPrefix" dusk="acp-new-prefix">
            <x-ui.icon name="plus" class="h-4 w-4" /> New prefix
        </x-ui.button>
    </div>

    {{-- Create / edit form. --}}
    @if ($showForm)
        <x-ui.card>
            <form wire:submit="save" class="space-y-4">
                <h2 class="text-sm font-semibold text-ink">{{ $formId ? 'Edit prefix' : 'New prefix' }}</h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input label="Label" name="label" wire:model="label" required maxlength="60" dusk="acp-prefix-label" />
                    <x-ui.select label="Colour" name="colorToken" wire:model.live="colorToken">
                        <option value="">— No colour —</option>
                        @foreach ($this->colorOptions() as $key => $meta)
                            <option value="{{ $key }}">{{ $meta[0] }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                @php($previewColor = \App\Support\GroupColor::cssVar($colorToken))
                @if ($previewColor)
                    <p class="text-sm text-ink-muted">
                        Preview: <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
                                       style="background: {{ $previewColor }}20; color: {{ $previewColor }};">{{ $label !== '' ? $label : 'Sample' }}</span>
                    </p>
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select label="Forum scope" name="forumId" wire:model="forumId"
                                 hint="Leave blank to make this prefix available in every forum (global).">
                        <option value="">— Global —</option>
                        @foreach ($this->forumOptions() as $opt)
                            <option value="{{ $opt['id'] }}">{{ $opt['title'] }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Position" name="position" type="number" min="0" wire:model="position"
                                hint="Lower numbers appear first in the prefix picker." />
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save" dusk="acp-prefix-save">
                        <span wire:loading.remove wire:target="save">{{ $formId ? 'Save changes' : 'Create prefix' }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="cancelForm">Cancel</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    {{-- Prefix list. --}}
    <x-ui.card flush>
        <div class="hidden sm:grid grid-cols-[1fr_8rem_6rem_9rem] gap-3 px-4 py-2.5 sm:px-5 border-b border-line bg-surface-sunken text-xs font-semibold uppercase tracking-wide text-ink-subtle">
            <span>Prefix</span>
            <span>Scope</span>
            <span class="text-right">Position</span>
            <span class="text-right">Actions</span>
        </div>
        @php($rows = $this->rows())
        @if (empty($rows))
            <x-ui.empty title="No prefixes yet">
                <x-slot:icon><x-ui.icon name="tag" class="h-6 w-6" /></x-slot:icon>
                Create your first prefix above to let authors label their topics.
            </x-ui.empty>
        @else
            <ul class="divide-y divide-line">
                @foreach ($rows as $row)
                    @php($p = $row['prefix'])
                    <li>
                        <div class="grid grid-cols-1 gap-2 px-4 py-3 sm:grid-cols-[1fr_8rem_6rem_9rem] sm:items-center sm:gap-3 sm:px-5 text-sm">
                            <div class="min-w-0">
                                @php($pc = \App\Support\GroupColor::cssVar($p->color_token))
                                <div class="flex items-center gap-2">
                                    @if ($pc)
                                        <span class="inline-block h-3 w-3 shrink-0 rounded-full" style="background: {{ $pc }};" aria-hidden="true"></span>
                                    @endif
                                    <span class="font-medium truncate" @if ($pc) style="color: {{ $pc }};" @endif>{{ $p->label }}</span>
                                </div>
                            </div>
                            <div>
                                <x-ui.badge variant="{{ $row['forumName'] === 'Global' ? 'accent' : 'neutral' }}">
                                    {{ $row['forumName'] }}
                                </x-ui.badge>
                            </div>
                            <div class="text-ink-muted sm:text-right nums">{{ $p->position }}</div>
                            <div class="flex flex-wrap items-center gap-1 sm:justify-end">
                                <x-ui.button type="button" variant="ghost" size="sm" icon wire:click="edit({{ $p->id }})" title="Edit" dusk="acp-prefix-edit-{{ $p->id }}">
                                    <x-ui.icon name="pencil" class="h-4 w-4" />
                                </x-ui.button>
                                <x-ui.button type="button" variant="danger-ghost" size="sm" icon wire:click="askDelete({{ $p->id }})" title="Delete" dusk="acp-prefix-delete-{{ $p->id }}">
                                    <x-ui.icon name="trash" class="h-4 w-4" />
                                </x-ui.button>
                            </div>
                        </div>

                        {{-- Inline delete-safety panel. --}}
                        @if ($deleteId === $p->id)
                            <div class="border-t border-line bg-surface-sunken px-4 py-4 sm:px-5">
                                <x-ui.alert variant="warn" class="mb-3">
                                    Delete "{{ $p->label }}"? Topics using this prefix will have their prefix cleared.
                                </x-ui.alert>
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ui.button type="button" variant="danger" wire:click="delete"
                                                 wire:loading.attr="disabled" wire:target="delete"
                                                 dusk="acp-prefix-confirm-delete-{{ $p->id }}">
                                        <span wire:loading.remove wire:target="delete">Delete</span>
                                        <span wire:loading wire:target="delete">Working…</span>
                                    </x-ui.button>
                                    <x-ui.button type="button" variant="ghost" wire:click="cancelDelete">Cancel</x-ui.button>
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
