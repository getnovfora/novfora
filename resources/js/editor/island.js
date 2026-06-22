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
  window.Alpine.data('novforaEditor', (config = {}) => {
    let editor = null
    let api = null
    let draftTimer = null
    return {
      // Attach-zone state (reactive Alpine — NOT the editor, which stays in closure per SPIKE FINDING #1).
      uploads: [],
      maxBytes: Number(config.maxBytes) || 0,
      async init() {
        api = await import('./novfora-editor') // <-- lazy chunk (TipTap + extensions)
        editor = api.createNovForaEditor({
          element: this.$refs.mount,
          content: config.content ?? { type: 'doc', content: [] },
          placeholder: config.placeholder ?? 'Write something…',
          uploadUrl: config.uploadUrl ?? null,
          mentionUrl: config.mentionUrl ?? null,
          onUpdate: (json) => {
            // Deferred set (3rd arg false) is JS-only — no network — so it is NEVER debounced; the latest
            // value always rides the next real Livewire request (SPIKE FINDING #3). Do not move this.
            this.$wire?.set(config.model ?? 'canonicalJson', json, false)

            // P2-M1 drafts: ONLY the network autosave is debounced. The deferred set above already keeps the
            // host property current, so debouncing the save can never lose the doc on publish. Opt-in via
            // `draft: true` (the host wires a saveDraft action); a no-op otherwise.
            if (config.draft) {
              clearTimeout(draftTimer)
              draftTimer = setTimeout(() => { this.$wire?.saveDraft(json) }, config.draftDebounce ?? 1500)
            }
          },
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
      // Multi-file attach zone (drag-drop + click-to-browse). Each file gets a row in `uploads` with a live
      // status; the server enforces the real size/type/security gates, the client cap is just fast feedback.
      async uploadFiles(list) {
        const files = Array.from(list || [])
        for (const file of files) {
          const entry = { name: file.name, status: 'uploading' }
          if (this.maxBytes && file.size > this.maxBytes) {
            entry.status = 'error'
            this.uploads.push(entry)
            continue
          }
          this.uploads.push(entry)
          try {
            await api?.uploadAndInsert(editor, file, config.uploadUrl)
            entry.status = 'done'
          } catch {
            entry.status = 'error'
          }
        }
      },
      removeUpload(i) {
        this.uploads.splice(i, 1)
      },
      destroy() {
        clearTimeout(draftTimer)
        editor?.destroy()
        editor = null
      },
    }
  })

  // ── toolbar controller: ARIA roving-tabindex + a single-variable menu state machine ──────────────────
  // Nested inside the editor's x-data, so `cmd`/`isActive` resolve up to the parent scope while the menu
  // + roving state live here. `menu` is the name of the one open popover (or null). Roving tabindex keeps
  // exactly one control in the Tab order; Arrow/Home/End move focus among the VISIBLE controls (so the
  // mobile … overflow doesn't strand focus on a hidden button). Degrades to plain focusable buttons if JS
  // never runs.
  window.Alpine.data('novforaToolbar', () => ({
    menu: null,
    linkHref: '',
    init() {
      const items = this._items()
      items.forEach((el, i) => el.setAttribute('tabindex', i === 0 ? '0' : '-1'))
    },
    _items() {
      return Array.from(this.$el.querySelectorAll('[data-tb-item]'))
    },
    _visible() {
      return this._items().filter((el) => el.offsetParent !== null && el.getAttribute('aria-disabled') !== 'true')
    },
    _focusTo(i) {
      const v = this._visible()
      if (!v.length) return
      const idx = ((i % v.length) + v.length) % v.length
      this._items().forEach((el) => el.setAttribute('tabindex', '-1'))
      v[idx].setAttribute('tabindex', '0')
      v[idx].focus()
    },
    onArrow(e, dir) {
      const cur = this._visible().indexOf(document.activeElement)
      if (cur < 0) return // focus is inside a menu/input, not on a toolbar control — let the key through
      e.preventDefault()
      this._focusTo(cur + dir)
    },
    onHome(e) {
      if (this._visible().includes(document.activeElement)) { e.preventDefault(); this._focusTo(0) }
    },
    onEnd(e) {
      if (this._visible().includes(document.activeElement)) { e.preventDefault(); this._focusTo(this._visible().length - 1) }
    },
    openMenu(name) { this.menu = this.menu === name ? null : name },
    closeMenus() { this.menu = null },
  }))
})
