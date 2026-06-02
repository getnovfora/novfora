<?php
// SPDX-License-Identifier: Apache-2.0
use Livewire\Component;
use App\Support\CanonicalRenderer;

new class extends Component
{
    public string $title = '';
    public array $canonicalJson = ['type' => 'doc', 'content' => []];
    public string $previewHtml = '';
    public int $clicks = 0;

    // Sibling re-render with NO validation — exercises criterion #1a (editor must survive a morph).
    public function ping(): void
    {
        $this->clicks++;
    }

    // Validation path — an empty title fails, re-rendering the component; the wire:ignore'd
    // editor (and its synced canonicalJson) must survive. Then render canonical -> safe HTML.
    public function save(CanonicalRenderer $renderer): void
    {
        $this->validate([
            'title' => ['required', 'min:3'],
            'canonicalJson' => ['required', 'array'],
        ]);

        $this->previewHtml = $renderer->toSafeHtml($this->canonicalJson);
    }
};
?>

<div>
    <div class="field">
        <label for="title">Title</label>
        <input id="title" type="text" wire:model="title" placeholder="Title (min 3 chars)">
        @error('title') <p data-error="title" class="err">{{ $message }}</p> @enderror
    </div>

    {{-- The editor mounts inside wire:ignore so Livewire never morphs TipTap's DOM (the spike's mechanism). --}}
    <div wire:ignore x-data="hearthEditor(@js($canonicalJson))">
        <div x-ref="mount" class="editor" data-editor aria-label="Post editor" role="textbox" aria-multiline="true"></div>
        {{-- Picker shares the same uploadAndInsert path as drag-drop + paste (criterion #2). --}}
        <input type="file" accept="image/*" data-upload hidden x-on:change="upload($event.target.files[0]); $event.target.value=''">
    </div>

    <div class="actions">
        <button type="button" wire:click="ping" data-action="ping">Sibling re-render (<span data-clicks>{{ $clicks }}</span>)</button>
        <button type="button" wire:click="save" data-action="save">Save</button>
    </div>

    @if ($previewHtml)
        <div data-preview class="preview">{!! $previewHtml !!}</div>
    @endif

    {{-- Server's view of the synced canonical JSON (updates after a round-trip) — used by the browser test. --}}
    <pre data-canonical hidden>{{ json_encode($canonicalJson) }}</pre>
</div>
