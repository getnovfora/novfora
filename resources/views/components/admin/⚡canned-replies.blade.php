<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\CannedReply;
use App\Models\User;
use App\Permissions\Scope;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Admin → Moderation → Canned replies (T1). CRUD over stock moderator replies (title + plain-text body stored
 * as a canonical doc + active toggle). Self-guards admin.access + bans.manage + staff-2FA on mount + every
 * action (canned replies are a moderation tool — the same capability that gates warnings/reports). The body is
 * a textarea (one paragraph per line) so the editor's wire:ignore island isn't needed in a record-switching
 * CRUD form; it's converted to/from a canonical doc via CannedReply::textToDoc/docToText.
 */
new class extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $title = '';

    public string $body = '';

    public bool $isActive = true;

    public ?string $flash = null;

    public function mount(): void
    {
        $this->ensureCanManage();
    }

    public function create(): void
    {
        $this->ensureCanManage();
        $this->reset(['editingId', 'title', 'body']);
        $this->isActive = true;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->ensureCanManage();
        $reply = CannedReply::findOrFail($id);
        $this->editingId = $reply->id;
        $this->title = (string) $reply->title;
        $this->body = CannedReply::docToText((array) $reply->body_canonical);
        $this->isActive = (bool) $reply->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->ensureCanManage();
        $this->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $attrs = [
            'title' => $this->title,
            'body_canonical' => CannedReply::textToDoc($this->body),
            'is_active' => $this->isActive,
        ];

        if ($this->editingId !== null) {
            CannedReply::findOrFail($this->editingId)->update($attrs);
            $this->flash = 'Canned reply updated.';
        } else {
            CannedReply::create($attrs);
            $this->flash = 'Canned reply created.';
        }

        $this->showForm = false;
        $this->reset(['editingId', 'title', 'body']);
    }

    public function toggleActive(int $id): void
    {
        $this->ensureCanManage();
        $reply = CannedReply::findOrFail($id);
        $reply->update(['is_active' => ! $reply->is_active]);
    }

    public function delete(int $id): void
    {
        $this->ensureCanManage();
        CannedReply::whereKey($id)->delete();
        $this->flash = 'Canned reply deleted.';
    }

    /** @return Collection<int, CannedReply> */
    public function replies(): Collection
    {
        $this->ensureCanManage();

        return CannedReply::orderByDesc('is_active')->orderBy('title')->get();
    }

    private function ensureCanManage(): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('admin.access', Scope::global()), 403);
        abort_unless($u->canDo('bans.manage', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-4">
    @if ($flash)
        <div class="rounded-md border border-success/40 bg-success-soft px-4 py-2.5 text-sm text-success-ink" dusk="cr-flash">{{ $flash }}</div>
    @endif

    <div class="flex items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-ink">Canned replies</h2>
            <p class="text-sm text-ink-muted">Reusable stock replies a moderator can drop into the reply composer.</p>
        </div>
        <x-ui.button size="sm" wire:click="create" dusk="cr-create">New reply</x-ui.button>
    </div>

    @if ($showForm)
        <x-ui.card>
            <div class="space-y-3">
                <div>
                    <label for="cr-title" class="block text-sm font-medium text-ink mb-1.5">Title</label>
                    <input id="cr-title" wire:model="title" maxlength="120"
                           class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                    @error('title') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="cr-body" class="block text-sm font-medium text-ink mb-1.5">Body</label>
                    <textarea id="cr-body" wire:model="body" rows="5" maxlength="5000"
                              class="w-full px-3 py-2 rounded-md bg-surface border border-line text-ink focus:border-accent"
                              placeholder="One paragraph per line — formatting can be added after inserting."></textarea>
                    @error('body') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-center gap-2">
                    <input id="cr-active" type="checkbox" wire:model="isActive" class="rounded border-line text-accent">
                    <label for="cr-active" class="text-sm text-ink">Active</label>
                </div>
                <div class="flex gap-2">
                    <x-ui.button size="sm" wire:click="save" dusk="cr-save">{{ $editingId ? 'Save changes' : 'Create reply' }}</x-ui.button>
                    <x-ui.button size="sm" variant="ghost" wire:click="$set('showForm', false)">Cancel</x-ui.button>
                </div>
            </div>
        </x-ui.card>
    @endif

    <x-ui.table label="Canned replies">
        <x-slot:head>
            <tr><th>Title</th><th>Preview</th><th>Status</th><th class="text-right">Manage</th></tr>
        </x-slot:head>
        @forelse ($this->replies() as $reply)
            <tr wire:key="cr-{{ $reply->id }}">
                <td class="text-ink font-medium">{{ $reply->title }}</td>
                <td class="text-ink-muted">{{ \Illuminate\Support\Str::limit(\App\Models\CannedReply::docToText((array) $reply->body_canonical), 80) }}</td>
                <td><x-ui.badge :variant="$reply->is_active ? 'success' : 'neutral'">{{ $reply->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
                <td class="text-right whitespace-nowrap">
                    <x-ui.button size="sm" variant="ghost" wire:click="toggleActive({{ $reply->id }})">{{ $reply->is_active ? 'Deactivate' : 'Activate' }}</x-ui.button>
                    <x-ui.button size="sm" variant="ghost" icon wire:click="edit({{ $reply->id }})" title="Edit" dusk="cr-edit-{{ $reply->id }}"><x-ui.icon name="pencil" class="h-4 w-4" /></x-ui.button>
                    <x-ui.button size="sm" variant="danger-ghost" icon wire:click="delete({{ $reply->id }})" title="Delete" wire:confirm="Delete this canned reply?" dusk="cr-delete-{{ $reply->id }}"><x-ui.icon name="trash" class="h-4 w-4" /></x-ui.button>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="px-3 py-8 text-center text-sm text-ink-subtle">No canned replies yet.</td></tr>
        @endforelse
    </x-ui.table>
</div>
