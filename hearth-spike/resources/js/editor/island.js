// SPDX-License-Identifier: Apache-2.0
// Alpine island that mounts TipTap inside a `wire:ignore` boundary and syncs CANONICAL JSON to the
// Livewire component via $wire.set. The heavy editor module is DYNAMICALLY imported so @tiptap/* is
// code-split OUT of the main bundle (criterion #6).
//
// SPIKE FINDING — the TipTap Editor must NOT be a reactive Alpine property. Alpine proxies component
// state; a proxied ProseMirror editor breaks identity checks and throws "Applying a mismatched
// transaction" on programmatic commands (e.g. inserting an uploaded image). Typing still works
// because ProseMirror's own DOM handlers hold a raw reference. Keep the editor in PER-INSTANCE
// CLOSURE STATE (Alpine calls this factory once per component), which Alpine never proxies.
document.addEventListener('alpine:init', () => {
  window.Alpine.data('hearthEditor', (initialJson) => {
    let editor = null
    let uploadAndInsert = null
    return {
      async init() {
        const mod = await import('./hearth-editor') // <-- lazy chunk (TipTap)
        uploadAndInsert = mod.uploadAndInsert
        editor = mod.createHearthEditor({
          element: this.$refs.mount,
          content: initialJson,
          // Deferred set (3rd arg false) is JS-only — no network — so no debounce; the latest value
          // rides the next real Livewire request (ping/save).
          onUpdate: (json) => { this.$wire?.set('canonicalJson', json, false) },
        })
      },
      async upload(file) {
        if (file && editor && uploadAndInsert) await uploadAndInsert(editor, file)
      },
      destroy() {
        editor?.destroy()
        editor = null
      },
    }
  })
})
