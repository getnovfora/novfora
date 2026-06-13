<?php

// SPDX-License-Identifier: Apache-2.0

use App\Models\StaffNote;
use App\Models\User;
use App\Moderation\StaffNotes;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Private staff-only notes about a member (A1). Embedded on the profile page; the parent gates rendering with
 * StaffNotes::visibleTo(), and this self-guards in mount() AND every action — a Livewire action arrives via
 * livewire/update with NO route middleware, so the parent's @if alone is not a security boundary. The guard
 * re-asserts the full "staff-only, never the subject" predicate every time. Any staff member may add a note;
 * only its author or an admin may edit/delete it (StaffNotes::canManage). Every write is audited.
 */
new class extends Component
{
    #[Locked]
    public int $subjectId;

    public string $body = '';

    public ?int $editingId = null;

    public string $editBody = '';

    public function mount(int $subjectId): void
    {
        $this->subjectId = $subjectId;
        $this->guard();
    }

    public function add(): void
    {
        $viewer = $this->guard();
        $data = $this->validate(['body' => ['required', 'string', 'max:5000']]);

        $note = StaffNote::create([
            'user_id' => $this->subjectId,
            'author_id' => (int) $viewer->getKey(),
            'body' => $data['body'],
        ]);
        Audit::log('staff_note.created', $note, ['subject_id' => $this->subjectId]);

        $this->reset('body');
    }

    public function startEdit(int $id): void
    {
        $this->guard();
        $note = $this->manageableNoteOr403($id);
        $this->editingId = (int) $note->getKey();
        $this->editBody = (string) $note->body;
    }

    public function cancelEdit(): void
    {
        $this->guard();
        $this->reset('editingId', 'editBody');
    }

    public function saveEdit(): void
    {
        $this->guard();
        abort_if($this->editingId === null, 404);
        $note = $this->manageableNoteOr403($this->editingId);
        $data = $this->validate(['editBody' => ['required', 'string', 'max:5000']]);

        $note->update(['body' => $data['editBody']]);
        Audit::log('staff_note.updated', $note, ['subject_id' => $this->subjectId]);

        $this->reset('editingId', 'editBody');
    }

    public function delete(int $id): void
    {
        $this->guard();
        $note = $this->manageableNoteOr403($id);
        $note->delete();
        Audit::log('staff_note.deleted', $note, ['subject_id' => $this->subjectId]);
    }

    /** @return Collection<int,StaffNote> */
    public function notes(): Collection
    {
        $this->guard();

        return StaffNote::where('user_id', $this->subjectId)
            ->with('author')
            ->latest()
            ->limit(100)
            ->get();
    }

    private function subject(): User
    {
        return User::findOrFail($this->subjectId);
    }

    /** Re-assert the staff-only-never-the-subject gate; returns the (now-narrowed) viewer for convenience. */
    private function guard(): User
    {
        $viewer = auth()->user();
        abort_unless($viewer instanceof User, 403);
        abort_unless(StaffNotes::visibleTo($viewer, $this->subject()), 403);

        return $viewer;
    }

    /** A note that belongs to THIS subject and that the viewer may modify; 404/403 otherwise. */
    private function manageableNoteOr403(int $id): StaffNote
    {
        $note = StaffNote::where('user_id', $this->subjectId)->findOrFail($id);
        abort_unless(StaffNotes::canManage(auth()->user(), $note), 403);

        return $note;
    }
};
?>

<x-ui.card class="space-y-4" dusk="staff-notes">
    <div class="flex items-center justify-between gap-3">
        <h2 class="text-sm font-semibold text-ink">{{ __('Staff notes') }}</h2>
        <x-ui.badge variant="neutral">{{ __('Private · staff only') }}</x-ui.badge>
    </div>
    <p class="text-sm text-ink-subtle">{{ __('Visible only to staff. The member can never see these notes.') }}</p>

    <form wire:submit="add" class="space-y-2">
        <label for="staff-note-body" class="sr-only">{{ __('Add a staff note') }}</label>
        <textarea id="staff-note-body" wire:model="body" rows="3" dusk="staff-note-body"
                  placeholder="{{ __('Add a note about this member…') }}"
                  class="w-full rounded-md bg-surface border border-line text-ink placeholder:text-ink-subtle px-3 py-2 focus:border-accent"></textarea>
        @error('body') <p class="text-xs text-danger">{{ $message }}</p> @enderror
        <div>
            <x-ui.button type="submit" variant="primary" size="sm" wire:loading.attr="disabled" wire:target="add" dusk="staff-note-add">
                {{ __('Add note') }}
            </x-ui.button>
        </div>
    </form>

    @php($notes = $this->notes())
    @if ($notes->isEmpty())
        <p class="text-sm text-ink-subtle" dusk="staff-notes-empty">{{ __('No staff notes yet.') }}</p>
    @else
        <ul class="space-y-3" dusk="staff-notes-list">
            @foreach ($notes as $note)
                <li class="rounded-lg border border-line bg-surface-sunken p-3" dusk="staff-note-{{ $note->id }}">
                    <div class="flex items-center justify-between gap-2 text-xs text-ink-subtle">
                        <span>
                            @if ($note->author)
                                <x-ui.user-name :user="$note->author" />
                            @else
                                <span class="italic">{{ __('[Deleted]') }}</span>
                            @endif
                            · {{ $note->created_at?->diffForHumans() }}
                        </span>
                        @if (\App\Moderation\StaffNotes::canManage(auth()->user(), $note))
                            <span class="flex items-center gap-2">
                                <button type="button" wire:click="startEdit({{ $note->id }})"
                                        class="text-accent hover:text-accent-hover" dusk="staff-note-edit-{{ $note->id }}">{{ __('Edit') }}</button>
                                <button type="button" wire:click="delete({{ $note->id }})"
                                        class="text-danger hover:underline" dusk="staff-note-delete-{{ $note->id }}">{{ __('Delete') }}</button>
                            </span>
                        @endif
                    </div>

                    @if ($editingId === $note->id)
                        <form wire:submit="saveEdit" class="mt-2 space-y-2">
                            <textarea wire:model="editBody" rows="3" dusk="staff-note-edit-body"
                                      class="w-full rounded-md bg-surface border border-line text-ink px-3 py-2 focus:border-accent"></textarea>
                            @error('editBody') <p class="text-xs text-danger">{{ $message }}</p> @enderror
                            <div class="flex items-center gap-2">
                                <x-ui.button type="submit" variant="primary" size="sm" dusk="staff-note-save">{{ __('Save') }}</x-ui.button>
                                <x-ui.button type="button" variant="subtle" size="sm" wire:click="cancelEdit">{{ __('Cancel') }}</x-ui.button>
                            </div>
                        </form>
                    @else
                        <p class="mt-1.5 whitespace-pre-wrap break-words text-sm text-ink">{{ $note->body }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.card>
