{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{--
    Reusable WYSIWYG editor island (Spike-0 pattern). Mounts TipTap inside `wire:ignore` so Livewire never
    morphs the editor DOM; the island syncs CANONICAL JSON to the host component's `$model` property via a
    deferred $wire.set. Any Livewire form embeds this and reads the synced property on save.

    Toolbar (Pillar 4): grouped marks + a "Text style" menu (paragraph / H1-H3 / quote) + an "Insert" menu
    (link / image / table / embed / spoiler / rule / attach) + an emoji picker, with a … overflow for
    secondary actions on narrow screens. ARIA roving-tabindex via x-data="novforaToolbar()". Every control
    has a visible `title` tooltip on top of its `aria-label`. The Attach item FEATURE-DETECTS the Slice-2
    upload endpoint (`attachUrl`) and stays hidden when absent — no hard dependency on attachments.
--}}
@props([
    'model' => 'canonicalJson',
    'initial' => ['type' => 'doc', 'content' => []],
    'uploadUrl' => null,
    // Slice-2 attachment endpoint. The "Attach files" control is rendered only when this is present, so the
    // toolbar works identically before the attachment subsystem lands (progressive, no hard dependency).
    'attachUrl' => null,
    'mentionUrl' => null,
    'placeholder' => 'Write something…',
    // When true, the island debounces a $wire.saveDraft(json) network autosave (P2-M1). The host component
    // must expose a saveDraft action (e.g. via the ManagesDrafts trait). The immediate deferred sync is
    // unaffected (Spike #3).
    'draft' => false,
    'draftDebounce' => 1500,
])

@php
    // Reaction-set seed + a fuller common set (program §Pillar 4: "reuse the 6-type reaction set + a fuller set").
    $emojis = ['👍', '❤️', '💡', '🧠', '😄', '👎', '🎉', '🔥', '✅', '❌', '⭐', '🚀',
        '💯', '👀', '🙏', '🙌', '😅', '😂', '😍', '🤔', '😎', '😢', '😮', '👏'];
    $maxBytes = (int) config('novfora.attachments.max_bytes', 5_242_880);
@endphp

<div
    wire:ignore
    x-data="novforaEditor({
        model: @js($model),
        content: @js($initial),
        uploadUrl: @js($uploadUrl),
        mentionUrl: @js($mentionUrl),
        placeholder: @js($placeholder),
        draft: @js((bool) $draft),
        draftDebounce: @js((int) $draftDebounce),
        maxBytes: @js($maxBytes),
    })"
    class="novfora-editor"
>
    <div
        class="novfora-toolbar"
        role="toolbar"
        aria-label="Formatting"
        x-data="novforaToolbar()"
        x-on:keydown.arrow-right="onArrow($event, 1)"
        x-on:keydown.arrow-left="onArrow($event, -1)"
        x-on:keydown.home="onHome($event)"
        x-on:keydown.end="onEnd($event)"
        x-on:keydown.escape="closeMenus()"
        x-on:click.outside="closeMenus()"
    >
        {{-- ── marks ─────────────────────────────────────────────────────────────────────────────────── --}}
        <button type="button" data-tb-item title="Bold (Ctrl+B)" aria-label="Bold"
                x-on:click="cmd('bold')" :class="{ 'is-active': isActive('bold') }"><b>B</b></button>
        <button type="button" data-tb-item title="Italic (Ctrl+I)" aria-label="Italic"
                x-on:click="cmd('italic')" :class="{ 'is-active': isActive('italic') }"><i>I</i></button>
        <button type="button" data-tb-item title="Strikethrough" aria-label="Strikethrough"
                class="hidden sm:inline-flex"
                x-on:click="cmd('strike')" :class="{ 'is-active': isActive('strike') }"><s>S</s></button>
        <button type="button" data-tb-item title="Inline code" aria-label="Inline code"
                class="hidden sm:inline-flex"
                x-on:click="cmd('code')" :class="{ 'is-active': isActive('code') }">&lt;&gt;</button>

        <span class="novfora-sep" aria-hidden="true"></span>

        {{-- ── text-style menu (paragraph / H1-H3 / quote) ──────────────────────────────────────────────── --}}
        <button type="button" data-tb-item title="Text style" aria-label="Text style"
                aria-haspopup="true" :aria-expanded="menu === 'style'"
                x-on:click="openMenu('style')">¶ <span aria-hidden="true">▾</span></button>
        <div class="novfora-pop" role="group" aria-label="Text style" x-show="menu === 'style'" x-cloak x-transition.opacity>
            <button type="button" x-on:click="cmd('paragraph'); closeMenus()" :class="{ 'is-active': isActive('paragraph') }">Paragraph</button>
            <button type="button" x-on:click="cmd('heading', { level: 1 }); closeMenus()" :class="{ 'is-active': isActive('heading', { level: 1 }) }">Heading 1</button>
            <button type="button" x-on:click="cmd('heading', { level: 2 }); closeMenus()" :class="{ 'is-active': isActive('heading', { level: 2 }) }">Heading 2</button>
            <button type="button" x-on:click="cmd('heading', { level: 3 }); closeMenus()" :class="{ 'is-active': isActive('heading', { level: 3 }) }">Heading 3</button>
            <button type="button" x-on:click="cmd('blockquote'); closeMenus()" :class="{ 'is-active': isActive('blockquote') }">Quote</button>
        </div>

        {{-- ── lists ─────────────────────────────────────────────────────────────────────────────────── --}}
        <button type="button" data-tb-item title="Bullet list" aria-label="Bullet list"
                x-on:click="cmd('bulletList')" :class="{ 'is-active': isActive('bulletList') }">&bull;</button>
        <button type="button" data-tb-item title="Numbered list" aria-label="Numbered list"
                x-on:click="cmd('orderedList')" :class="{ 'is-active': isActive('orderedList') }">1.</button>

        <span class="novfora-sep" aria-hidden="true"></span>

        {{-- ── link dialog (proper popover, not window.prompt) ─────────────────────────────────────────── --}}
        <button type="button" data-tb-item title="Insert link" aria-label="Insert link"
                aria-haspopup="true" :aria-expanded="menu === 'link'"
                x-on:click="openMenu('link'); $nextTick(() => $refs.linkInput?.focus())">&#128279;</button>
        <div class="novfora-pop novfora-link-pop" role="group" aria-label="Insert link" x-show="menu === 'link'" x-cloak x-transition.opacity>
            <label class="sr-only" for="novfora-link-url">Link URL</label>
            <input id="novfora-link-url" type="url" x-ref="linkInput" x-model="linkHref" placeholder="https://example.com"
                   x-on:keydown.enter.prevent="cmd('setLink', { href: linkHref }); linkHref = ''; closeMenus()">
            <div class="novfora-link-actions">
                <button type="button" x-on:click="cmd('setLink', { href: linkHref }); linkHref = ''; closeMenus()">Insert</button>
                <button type="button" x-on:click="cmd('unsetLink'); closeMenus()">Remove</button>
            </div>
        </div>

        {{-- ── insert menu (image / table / embed / spoiler / rule / attach) ────────────────────────────── --}}
        <button type="button" data-tb-item title="Insert" aria-label="Insert"
                aria-haspopup="true" :aria-expanded="menu === 'insert'"
                x-on:click="openMenu('insert')">Insert <span aria-hidden="true">▾</span></button>
        <div class="novfora-pop" role="group" aria-label="Insert" x-show="menu === 'insert'" x-cloak x-transition.opacity>
            @if ($uploadUrl)
                <button type="button" x-on:click="$refs.file.click(); closeMenus()">&#128247; Image</button>
            @endif
            @if ($attachUrl)
                {{-- Feature-detected: present only once the Slice-2 attachment endpoint is wired. --}}
                <button type="button" x-on:click="$refs.attach?.click(); closeMenus()" data-attach-trigger>&#128206; Attach files</button>
            @endif
            <button type="button" x-on:click="cmd('table'); closeMenus()">&#9638; Table</button>
            <button type="button" x-on:click="cmd('embed'); closeMenus()">&#9654; Embed (video / link)</button>
            <button type="button" x-on:click="cmd('spoiler'); closeMenus()">&#9888; Spoiler / content warning</button>
            <button type="button" x-on:click="cmd('hr'); closeMenus()">&#8212; Horizontal rule</button>
        </div>

        {{-- ── emoji picker ──────────────────────────────────────────────────────────────────────────── --}}
        <button type="button" data-tb-item title="Emoji" aria-label="Insert emoji"
                aria-haspopup="true" :aria-expanded="menu === 'emoji'"
                x-on:click="openMenu('emoji')">&#128578;</button>
        <div class="novfora-pop novfora-emoji-pop" role="group" aria-label="Insert emoji" x-show="menu === 'emoji'" x-cloak x-transition.opacity>
            @foreach ($emojis as $e)
                <button type="button" title="{{ $e }}" aria-label="Emoji {{ $e }}"
                        x-on:click="cmd('emoji', { ch: @js($e) }); closeMenus()">{{ $e }}</button>
            @endforeach
        </div>

        {{-- ── … overflow: secondary actions (also the home for marks hidden on mobile) ─────────────────── --}}
        <button type="button" data-tb-item title="More" aria-label="More formatting"
                aria-haspopup="true" :aria-expanded="menu === 'more'"
                x-on:click="openMenu('more')">&hellip;</button>
        <div class="novfora-pop" role="group" aria-label="More formatting" x-show="menu === 'more'" x-cloak x-transition.opacity>
            <button type="button" class="sm:hidden" x-on:click="cmd('strike'); closeMenus()">Strikethrough</button>
            <button type="button" class="sm:hidden" x-on:click="cmd('code'); closeMenus()">Inline code</button>
            <button type="button" x-on:click="cmd('codeBlock'); closeMenus()">Code block</button>
            <button type="button" x-on:click="cmd('hr'); closeMenus()">Horizontal rule</button>
        </div>

        <span class="novfora-hint" aria-hidden="true">Type <kbd>/</kbd> for commands, <kbd>@</kbd> to mention</span>
    </div>

    <div x-ref="mount" class="novfora-mount"></div>

    @if ($uploadUrl)
        {{-- Drag-and-drop multi-file attach zone (ADR-0094). The editable area also accepts drop/paste; this
             is the explicit, discoverable affordance with a max-size readout + a click-to-browse fallback. --}}
        @php($maxHuman = rtrim(rtrim(number_format($maxBytes / 1048576, 1), '0'), '.').' MB')
        <div class="novfora-attach"
             x-on:dragover.prevent="$el.classList.add('is-drag')"
             x-on:dragleave.prevent="$el.classList.remove('is-drag')"
             x-on:drop.prevent="$el.classList.remove('is-drag'); uploadFiles($event.dataTransfer.files)">
            <div class="novfora-attach-prompt">
                <span><span aria-hidden="true">📎</span> Drag files here or
                    <button type="button" class="novfora-attach-browse" x-on:click="$refs.attachInput.click()">browse</button>
                </span>
                <span class="novfora-attach-max">Max {{ $maxHuman }} per file</span>
            </div>
            <input type="file" multiple x-ref="attachInput" hidden
                   x-on:change="uploadFiles($event.target.files); $event.target.value = ''">
            <ul class="novfora-attach-list" x-show="uploads.length" x-cloak>
                <template x-for="(u, i) in uploads" :key="i">
                    <li class="novfora-attach-item" :class="'is-' + u.status">
                        <span class="novfora-attach-name" x-text="u.name"></span>
                        <span class="novfora-attach-status" x-text="u.status === 'uploading' ? 'Uploading…' : (u.status === 'done' ? 'Added' : 'Failed')"></span>
                        <button type="button" class="novfora-attach-remove" x-on:click="removeUpload(i)" aria-label="Remove from list">&times;</button>
                    </li>
                </template>
            </ul>
        </div>

        {{-- The toolbar image button still uses a single image picker. --}}
        <input type="file" accept="image/*" x-ref="file" hidden
               x-on:change="upload($event.target.files[0]); $event.target.value = ''">
    @endif
    @if ($attachUrl)
        {{-- Hidden multi-file input; the Slice-2 drop zone + its handler land on the attachments branch. --}}
        <input type="file" multiple x-ref="attach" hidden data-attach-input>
    @endif
</div>
