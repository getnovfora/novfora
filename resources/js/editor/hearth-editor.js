// SPDX-License-Identifier: Apache-2.0
// TipTap editor factory (MIT core/extensions only — ADR-0015). Heavy; loaded via dynamic import() so it
// stays out of the main bundle (Spike-0 criterion #6). Emits CANONICAL JSON only — never HTML; the server
// renders + sanitizes (App\Content\CanonicalRenderer).
import { Editor, Extension } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import Placeholder from '@tiptap/extension-placeholder'
import Mention from '@tiptap/extension-mention'
import Image from '@tiptap/extension-image'
import { TableKit } from '@tiptap/extension-table'
import Suggestion from '@tiptap/suggestion'

// StarterKit v3 already bundles Link, Bold, Italic, Strike, Code, Heading, lists, blockquote, codeBlock,
// horizontalRule, history, hardBreak (FINDING #5 — do NOT re-register them). Image/Placeholder/Mention are
// separate MIT packages.

// ── a shared, keyboard-navigable popup for both mention + slash suggestions (a11y, criterion #5) ────────
function suggestionPopup(label, pick) {
  let el = null
  let items = []
  let active = 0
  let command = null

  const paint = () => {
    if (!el) return
    el.innerHTML = ''
    items.forEach((item, i) => {
      const b = document.createElement('button')
      b.type = 'button'
      b.className = 'hearth-suggest-item' + (i === active ? ' is-active' : '')
      b.setAttribute('role', 'option')
      b.setAttribute('aria-selected', i === active ? 'true' : 'false')
      b.textContent = label(item)
      b.addEventListener('mousedown', (e) => { e.preventDefault(); choose(i) })
      el.appendChild(b)
    })
  }
  const choose = (i) => { if (items[i]) pick(command, items[i]) }
  const place = (rect) => {
    if (!el || !rect) return
    el.style.position = 'absolute'
    el.style.left = `${rect.left + window.scrollX}px`
    el.style.top = `${rect.bottom + window.scrollY + 4}px`
  }

  return {
    onStart: (props) => {
      command = props.command
      items = props.items
      active = 0
      el = document.createElement('div')
      el.className = 'hearth-suggest'
      el.setAttribute('role', 'listbox')
      document.body.appendChild(el)
      paint()
      place(props.clientRect?.())
    },
    onUpdate: (props) => {
      command = props.command
      items = props.items
      active = 0
      paint()
      place(props.clientRect?.())
    },
    onKeyDown: (props) => {
      const key = props.event.key
      if (!items.length) return false
      if (key === 'ArrowDown') { active = (active + 1) % items.length; paint(); return true }
      if (key === 'ArrowUp') { active = (active - 1 + items.length) % items.length; paint(); return true }
      if (key === 'Enter') { choose(active); return true }
      if (key === 'Escape') { el?.remove(); el = null; return true }
      return false
    },
    onExit: () => { el?.remove(); el = null },
  }
}

// ── @mentions — server-driven (mentionUrl?q=), graceful when absent ─────────────────────────────────────
function mentionSuggestion(mentionUrl) {
  return {
    items: async ({ query }) => {
      if (!mentionUrl) return []
      try {
        const res = await fetch(`${mentionUrl}?q=${encodeURIComponent(query || '')}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) return []
        const data = await res.json()
        return (data.data || data.users || data || []).slice(0, 8)
      } catch { return [] }
    },
    render: () => suggestionPopup(
      (u) => `@${u.username ?? u.label ?? u}`,
      (command, u) => command({ id: String(u.id ?? u.username ?? u), label: u.username ?? u.label ?? String(u) }),
    ),
  }
}

// ── /slash commands — block insertions via the same suggestion util ─────────────────────────────────────
const SLASH_ITEMS = [
  { title: 'Heading', run: (e) => e.chain().focus().toggleHeading({ level: 2 }).run() },
  { title: 'Subheading', run: (e) => e.chain().focus().toggleHeading({ level: 3 }).run() },
  { title: 'Bullet list', run: (e) => e.chain().focus().toggleBulletList().run() },
  { title: 'Numbered list', run: (e) => e.chain().focus().toggleOrderedList().run() },
  { title: 'Quote', run: (e) => e.chain().focus().toggleBlockquote().run() },
  { title: 'Code block', run: (e) => e.chain().focus().toggleCodeBlock().run() },
  { title: 'Table', run: (e) => e.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run() },
  { title: 'Divider', run: (e) => e.chain().focus().setHorizontalRule().run() },
]

const SlashCommand = Extension.create({
  name: 'slashCommand',
  addProseMirrorPlugins() {
    return [
      Suggestion({
        editor: this.editor,
        char: '/',
        startOfLine: false,
        items: ({ query }) => SLASH_ITEMS
          .filter((i) => i.title.toLowerCase().includes((query || '').toLowerCase()))
          .slice(0, 8),
        command: ({ editor, range, props }) => {
          editor.chain().focus().deleteRange(range).run()
          props.run(editor) // a synchronous chain from a real key event — no mismatched-transaction risk
        },
        render: () => suggestionPopup((i) => i.title, (command, item) => command(item)),
      }),
    ]
  },
})

// ── upload (shared by paste + drop + picker) — FINDING #4: defer a tick then insertContent ──────────────
const imageFiles = (list) => Array.from(list || []).filter((f) => f.type?.startsWith('image/'))

export async function uploadAndInsert(editor, file, uploadUrl) {
  if (!uploadUrl || !file) return
  const form = new FormData()
  form.append('file', file)
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
  const res = await fetch(uploadUrl, { method: 'POST', headers: token ? { 'X-CSRF-TOKEN': token } : {}, body: form })
  if (!res.ok) return
  const { url } = await res.json().catch(() => ({}))
  if (!url) return
  await new Promise((resolve) => setTimeout(resolve, 0)) // settle any in-flight view update before dispatch
  editor.commands.insertContent({ type: 'image', attrs: { src: url } })
}

// ── toolbar commands (run from real user events; chained focus keeps the selection) ─────────────────────
export function runCommand(editor, name) {
  const chain = () => editor.chain().focus()
  const commands = {
    bold: () => chain().toggleBold().run(),
    italic: () => chain().toggleItalic().run(),
    strike: () => chain().toggleStrike().run(),
    code: () => chain().toggleCode().run(),
    h2: () => chain().toggleHeading({ level: 2 }).run(),
    h3: () => chain().toggleHeading({ level: 3 }).run(),
    bulletList: () => chain().toggleBulletList().run(),
    orderedList: () => chain().toggleOrderedList().run(),
    blockquote: () => chain().toggleBlockquote().run(),
    codeBlock: () => chain().toggleCodeBlock().run(),
    hr: () => chain().setHorizontalRule().run(),
    link: () => {
      const url = window.prompt('Link URL (leave blank to remove)')
      if (url === null) return
      url === '' ? chain().unsetLink().run() : chain().setLink({ href: url }).run()
    },
  }
  ;(commands[name] ?? (() => {}))()
}

export function createHearthEditor({ element, content, placeholder, uploadUrl, mentionUrl, onUpdate }) {
  const editor = new Editor({
    element,
    extensions: [
      StarterKit,
      TableKit.configure({ table: { resizable: false } }),
      Image,
      Placeholder.configure({ placeholder: placeholder ?? 'Write something…' }),
      Mention.configure({ HTMLAttributes: { class: 'mention' }, suggestion: mentionSuggestion(mentionUrl) }),
      SlashCommand,
    ],
    content: content ?? { type: 'doc', content: [] },
    // Emit CANONICAL JSON only — never HTML. The server renders + sanitizes (CanonicalRenderer).
    onUpdate: ({ editor }) => onUpdate?.(editor.getJSON()),
    editorProps: {
      attributes: {
        class: 'hearth-prose',
        role: 'textbox',
        'aria-multiline': 'true',
        'aria-label': 'Post editor',
      },
      handlePaste: (_view, event) => {
        const files = imageFiles(event.clipboardData?.files)
        if (!files.length || !uploadUrl) return false
        files.forEach((f) => uploadAndInsert(editor, f, uploadUrl))
        return true
      },
      handleDrop: (_view, event) => {
        const files = imageFiles(event.dataTransfer?.files)
        if (!files.length || !uploadUrl) return false
        event.preventDefault()
        files.forEach((f) => uploadAndInsert(editor, f, uploadUrl))
        return true
      },
    },
  })

  return editor
}
