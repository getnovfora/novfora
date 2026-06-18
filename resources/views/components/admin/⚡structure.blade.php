<?php
// SPDX-License-Identifier: Apache-2.0
use App\Forum\StructureException;
use App\Forum\StructureService;
use App\Models\Forum;
use App\Models\User;
use App\Permissions\Scope;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Admin → Content → Forums & structure (ACP v1, PART 2). The owner's #1 ask: a tree of categories →
 * boards → sub-boards with create / edit / reorder, and the binding DELETE-SAFETY flow — a board with
 * topics can only be removed by choosing a destination board to move them into (never silent loss). All
 * domain logic lives in StructureService; this component is the UI + the self-guard. Like the other admin
 * SFCs, authorization is re-asserted in mount() AND every action, because Livewire actions reach the
 * component via livewire/update, which carries no route middleware.
 */
new class extends Component
{
    public bool $showForm = false;

    public ?int $formId = null; // null = creating

    public string $formType = 'forum';

    public string $title = '';

    public string $description = '';

    public ?int $parentId = null;

    public ?int $deleteId = null;

    public ?int $destinationId = null;

    public ?string $message = null;

    public string $messageVariant = 'info';

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function newCategory(): void
    {
        $this->ensureAdmin();
        $this->resetForm();
        $this->formType = 'category';
        $this->showForm = true;
    }

    public function newBoard(?int $parentId = null): void
    {
        $this->ensureAdmin();
        $this->resetForm();
        $this->formType = 'forum';
        $this->parentId = $parentId;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->ensureAdmin();
        $forum = Forum::findOrFail($id);
        $this->formId = $forum->id;
        $this->formType = $forum->type;
        $this->title = (string) $forum->title;
        $this->description = (string) ($forum->description ?? '');
        $this->parentId = $forum->parent_id;
        $this->deleteId = null;
        $this->showForm = true;
    }

    public function save(StructureService $structure): void
    {
        $this->ensureAdmin();
        $data = $this->validate([
            'title' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'formType' => ['required', 'in:category,forum'],
            'parentId' => ['nullable', 'integer', 'exists:forums,id'],
        ]);

        try {
            if ($this->formId === null) {
                $forum = $structure->create([
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'type' => $data['formType'],
                    'parent_id' => $data['parentId'] ?? null,
                ]);
                $this->flash("Created “{$forum->title}”.");
            } else {
                $forum = $structure->update(Forum::findOrFail($this->formId), [
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'parent_id' => $data['parentId'] ?? null,
                ]);
                $this->flash("Saved “{$forum->title}”.");
            }
            $this->cancelForm();
        } catch (StructureException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    public function moveUp(int $id, StructureService $structure): void
    {
        $this->ensureAdmin();
        $structure->reorder(Forum::findOrFail($id), 'up');
    }

    public function moveDown(int $id, StructureService $structure): void
    {
        $this->ensureAdmin();
        $structure->reorder(Forum::findOrFail($id), 'down');
    }

    public function askDelete(int $id): void
    {
        $this->ensureAdmin();
        $this->deleteId = $id;
        $this->destinationId = null;
        $this->showForm = false;
        $this->message = null;
    }

    public function cancelDelete(): void
    {
        $this->deleteId = null;
        $this->destinationId = null;
    }

    public function delete(StructureService $structure): void
    {
        $this->ensureAdmin();
        if ($this->deleteId === null) {
            return;
        }

        $forum = Forum::findOrFail($this->deleteId);
        $destination = $this->destinationId ? Forum::find($this->destinationId) : null;

        try {
            $moved = $structure->delete($forum, $destination);
            $this->flash("Deleted “{$forum->title}”.".($moved > 0 ? " Moved {$moved} topic(s)." : ''));
            $this->deleteId = null;
            $this->destinationId = null;
        } catch (StructureException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    /** Ordered flat tree (depth-tagged), built once from a single load. */
    public function rows(): array
    {
        $byParent = Forum::query()->orderBy('position')->orderBy('id')->get()
            ->groupBy(fn (Forum $n): int => $n->parent_id === null ? 0 : (int) $n->parent_id);

        $out = [];
        $walk = function (int $parentKey, int $depth) use (&$walk, $byParent, &$out): void {
            /** @var Collection<int,Forum> $children */
            $children = $byParent[$parentKey] ?? collect();
            foreach ($children->sortBy([['position', 'asc'], ['id', 'asc']]) as $node) {
                $out[] = ['node' => $node, 'depth' => $depth];
                $walk((int) $node->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    /** Indented parent options for the create/edit form (a node can't parent into itself/its subtree). */
    public function parentOptions(): array
    {
        $opts = [];
        foreach ($this->rows() as $row) {
            $node = $row['node'];
            if ($this->formId !== null && str_contains((string) $node->path, '/'.$this->formId.'/')) {
                continue; // self or descendant
            }
            $opts[] = ['id' => $node->id, 'label' => str_repeat('— ', $row['depth']).$node->title];
        }

        return $opts;
    }

    /** Boards (not categories) a delete can move topics INTO — excludes the node being deleted. */
    public function destinationOptions(): array
    {
        $opts = [];
        foreach ($this->rows() as $row) {
            $node = $row['node'];
            if ($node->type !== 'forum' || (int) $node->id === (int) $this->deleteId) {
                continue;
            }
            $opts[] = ['id' => $node->id, 'label' => str_repeat('— ', $row['depth']).$node->title];
        }

        return $opts;
    }

    public function inspectorUrl(Forum $node): string
    {
        return route('admin.security.permissions', ['scope' => $node->permissionScope()->key()]);
    }

    private function resetForm(): void
    {
        $this->reset(['formId', 'title', 'description', 'parentId']);
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
};
?>

<div class="space-y-5">
    @if ($message)
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    <div class="flex flex-wrap items-center gap-2">
        <x-ui.button type="button" size="sm" wire:click="newCategory">
            <x-ui.icon name="plus" class="h-4 w-4" /> New category
        </x-ui.button>
        <x-ui.button type="button" size="sm" variant="subtle" wire:click="newBoard">
            <x-ui.icon name="plus" class="h-4 w-4" /> New board
        </x-ui.button>
    </div>

    {{-- Create / edit form. --}}
    @if ($showForm)
        <x-ui.card>
            <form wire:submit="save" class="space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-ink">
                        {{ $formId ? 'Edit' : 'New' }} {{ $formType === 'category' ? 'category' : 'board' }}
                    </h2>
                    @if (! $formId)
                        <span class="text-xs text-ink-subtle">Type is fixed at creation.</span>
                    @endif
                </div>

                <x-ui.input label="Name" name="title" wire:model="title" required maxlength="100" dusk="acp-board-name" />
                <x-ui.textarea label="Description" name="description" wire:model="description" rows="2"
                               hint="Optional. Shown under the board name." />

                @if ($formType === 'forum')
                    <x-ui.select label="Parent" name="parentId" wire:model="parentId"
                                 hint="Leave as “Top level” for a board directly under no category.">
                        <option value="">— Top level —</option>
                        @foreach ($this->parentOptions() as $opt)
                            <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                        @endforeach
                    </x-ui.select>
                @endif

                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">{{ $formId ? 'Save changes' : 'Create' }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="cancelForm">Cancel</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    {{-- The tree. --}}
    @php($rows = $this->rows())
    @if (empty($rows))
        <x-ui.empty title="No forums yet">
            Create a category, then add boards under it. New boards inherit the default role permissions, so
            they're usable immediately.
        </x-ui.empty>
    @else
        <x-ui.card flush>
            <ul class="divide-y divide-line">
                @foreach ($rows as $row)
                    @php($node = $row['node'])
                    <li>
                        <div class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5" style="padding-inline-start: calc({{ $row['depth'] }} * 1.25rem + 1rem)">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <x-ui.icon :name="$node->isCategory() ? 'folder' : 'message'" class="h-4 w-4 shrink-0 text-ink-subtle" />
                                    <span class="font-medium text-ink truncate">{{ $node->title }}</span>
                                    @if ($node->isCategory())
                                        <x-ui.badge variant="neutral">Category</x-ui.badge>
                                    @endif
                                </div>
                                @if (! $node->isCategory())
                                    <p class="mt-0.5 text-xs text-ink-subtle">
                                        <span class="nums">{{ number_format($node->topic_count) }}</span> topics ·
                                        <span class="nums">{{ number_format($node->post_count) }}</span> posts
                                        @if ($node->description) · <span class="text-ink-muted">{{ \Illuminate\Support\Str::limit($node->description, 60) }}</span>@endif
                                    </p>
                                @endif
                            </div>

                            <div class="flex flex-wrap items-center gap-1">
                                <x-ui.button type="button" variant="ghost" size="sm" icon wire:click="moveUp({{ $node->id }})" title="Move up">
                                    <x-ui.icon name="arrow-up" class="h-4 w-4" />
                                </x-ui.button>
                                <x-ui.button type="button" variant="ghost" size="sm" icon wire:click="moveDown({{ $node->id }})" title="Move down">
                                    <x-ui.icon name="arrow-down" class="h-4 w-4" />
                                </x-ui.button>
                                @unless ($node->isCategory())
                                    <x-ui.button variant="ghost" size="sm" icon :href="$this->inspectorUrl($node)" title="Permissions">
                                        <x-ui.icon name="shield" class="h-4 w-4" />
                                    </x-ui.button>
                                @endunless
                                <x-ui.button type="button" variant="ghost" size="sm" icon wire:click="edit({{ $node->id }})" title="Edit">
                                    <x-ui.icon name="pencil" class="h-4 w-4" />
                                </x-ui.button>
                                <x-ui.button type="button" variant="danger-ghost" size="sm" icon wire:click="askDelete({{ $node->id }})" title="Delete">
                                    <x-ui.icon name="trash" class="h-4 w-4" />
                                </x-ui.button>
                            </div>
                        </div>

                        {{-- Inline delete-safety panel. --}}
                        @if ($deleteId === $node->id)
                            @php($hasChildren = $node->children()->exists())
                            @php($topicCount = \App\Models\Topic::withTrashed()->where('forum_id', $node->id)->count())
                            <div class="border-t border-line bg-surface-sunken px-4 py-4 sm:px-5">
                                @if ($hasChildren)
                                    <x-ui.alert variant="danger">
                                        “{{ $node->title }}” has sub-items. Move or delete them first, then delete this {{ $node->type }}.
                                    </x-ui.alert>
                                    <div class="mt-3">
                                        <x-ui.button type="button" variant="ghost" wire:click="cancelDelete">Close</x-ui.button>
                                    </div>
                                @else
                                    <x-ui.alert variant="warn" class="mb-3">
                                        Delete “{{ $node->title }}”?
                                        @if ($topicCount > 0)
                                            It holds <strong class="nums">{{ number_format($topicCount) }}</strong> topic(s) —
                                            pick a destination board to move them into (nothing is deleted).
                                        @else
                                            It is empty, so this is safe.
                                        @endif
                                    </x-ui.alert>

                                    @if ($topicCount > 0)
                                        <x-ui.select label="Move topics into" name="destinationId" wire:model="destinationId" class="mb-3 max-w-md">
                                            <option value="">— Choose a board —</option>
                                            @foreach ($this->destinationOptions() as $opt)
                                                <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                                            @endforeach
                                        </x-ui.select>
                                    @endif

                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-ui.button type="button" variant="danger" wire:click="delete"
                                                     wire:loading.attr="disabled" wire:target="delete"
                                                     :disabled="$topicCount > 0 && ! $destinationId">
                                            <span wire:loading.remove wire:target="delete">{{ $topicCount > 0 ? 'Move topics & delete' : 'Delete' }}</span>
                                            <span wire:loading wire:target="delete">Working…</span>
                                        </x-ui.button>
                                        <x-ui.button type="button" variant="ghost" wire:click="cancelDelete">Cancel</x-ui.button>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
