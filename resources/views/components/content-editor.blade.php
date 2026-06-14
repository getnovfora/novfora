{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{--
    Reusable WYSIWYG editor island (Spike-0 pattern). Mounts TipTap inside `wire:ignore` so Livewire never
    morphs the editor DOM; the island syncs CANONICAL JSON to the host component's `$model` property via a
    deferred $wire.set. Any Livewire form embeds this and reads the synced property on save.
--}}
@props([
    'model' => 'canonicalJson',
    'initial' => ['type' => 'doc', 'content' => []],
    'uploadUrl' => null,
    'mentionUrl' => null,
    'placeholder' => 'Write something…',
    // When true, the island debounces a $wire.saveDraft(json) network autosave (P2-M1). The host component
    // must expose a saveDraft action (e.g. via the ManagesDrafts trait). The immediate deferred sync is
    // unaffected (Spike #3).
    'draft' => false,
    'draftDebounce' => 1500,
])

<div
    wire:ignore
    x-data="nevoEditor({
        model: @js($model),
        content: @js($initial),
        uploadUrl: @js($uploadUrl),
        mentionUrl: @js($mentionUrl),
        placeholder: @js($placeholder),
        draft: @js((bool) $draft),
        draftDebounce: @js((int) $draftDebounce),
    })"
    class="novfora-editor"
>
    <div class="novfora-toolbar" role="toolbar" aria-label="Formatting">
        <button type="button" x-on:click="cmd('bold')" :class="{ 'is-active': isActive('bold') }" aria-label="Bold"><b>B</b></button>
        <button type="button" x-on:click="cmd('italic')" :class="{ 'is-active': isActive('italic') }" aria-label="Italic"><i>I</i></button>
        <button type="button" x-on:click="cmd('strike')" :class="{ 'is-active': isActive('strike') }" aria-label="Strikethrough"><s>S</s></button>
        <span class="novfora-sep" aria-hidden="true"></span>
        <button type="button" x-on:click="cmd('h2')" :class="{ 'is-active': isActive('heading', { level: 2 }) }" aria-label="Heading">H</button>
        <button type="button" x-on:click="cmd('bulletList')" :class="{ 'is-active': isActive('bulletList') }" aria-label="Bullet list">&bull;</button>
        <button type="button" x-on:click="cmd('orderedList')" :class="{ 'is-active': isActive('orderedList') }" aria-label="Numbered list">1.</button>
        <button type="button" x-on:click="cmd('blockquote')" :class="{ 'is-active': isActive('blockquote') }" aria-label="Quote">&ldquo;</button>
        <button type="button" x-on:click="cmd('codeBlock')" :class="{ 'is-active': isActive('codeBlock') }" aria-label="Code block">&lt;/&gt;</button>
        <button type="button" x-on:click="cmd('link')" aria-label="Insert link">&#128279;</button>
        <button type="button" x-on:click="cmd('spoiler')" aria-label="Spoiler / content warning">&#9888;</button>
        @if ($uploadUrl)
            <button type="button" x-on:click="$refs.file.click()" aria-label="Upload image">&#128247;</button>
        @endif
        <span class="novfora-hint" aria-hidden="true">Type <kbd>/</kbd> for commands, <kbd>@</kbd> to mention</span>
    </div>

    <div x-ref="mount" class="novfora-mount"></div>

    @if ($uploadUrl)
        <input type="file" accept="image/*" x-ref="file" hidden
               x-on:change="upload($event.target.files[0]); $event.target.value = ''">
    @endif
</div>
