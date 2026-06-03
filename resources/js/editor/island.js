// SPDX-License-Identifier: Apache-2.0
//
// Alpine island that mounts TipTap inside a `wire:ignore` boundary and syncs CANONICAL JSON to the host
// Livewire component via $wire.set. The heavy editor module is DYNAMICALLY imported so @tiptap/* is
// code-split OUT of the main bundle (Spike-0 criterion #6).
//
// SPIKE FINDING #1 (the #1 editor rule): the TipTap Editor must NOT be a reactive Alpine property. Alpine
// proxies component state; a proxied ProseMirror editor breaks identity checks and throws "Applying a
// mismatched transaction" on programmatic commands. Keep the editor in PER-INSTANCE CLOSURE STATE (the
// `editor` local below), which Alpine never proxies. Typing works either way; programmatic commands
// (image insert, slash) are what break under a proxy.
document.addEventListener('alpine:init', () => {
  window.Alpine.data('hearthEditor', (config = {}) => {
    let editor = null
    let api = null
    return {
      async init() {
        api = await import('./hearth-editor') // <-- lazy chunk (TipTap + extensions)
        editor = api.createHearthEditor({
          element: this.$refs.mount,
          content: config.content ?? { type: 'doc', content: [] },
          placeholder: config.placeholder ?? 'Write something…',
          uploadUrl: config.uploadUrl ?? null,
          mentionUrl: config.mentionUrl ?? null,
          // Deferred set (3rd arg false) is JS-only — no network — so no debounce; the latest value rides
          // the next real Livewire request (SPIKE FINDING #3).
          onUpdate: (json) => { this.$wire?.set(config.model ?? 'canonicalJson', json, false) },
        })
      },
      // Toolbar buttons call this; chained focus keeps the selection (no mismatched-transaction risk
      // because these run from a real user event, not after an await).
      cmd(name, attrs) {
        if (editor) api?.runCommand(editor, name, attrs)
      },
      isActive(name, attrs) {
        return editor ? editor.isActive(name, attrs ?? {}) : false
      },
      async upload(file) {
        if (file && editor) await api?.uploadAndInsert(editor, file, config.uploadUrl)
      },
      destroy() {
        editor?.destroy()
        editor = null
      },
    }
  })
})
