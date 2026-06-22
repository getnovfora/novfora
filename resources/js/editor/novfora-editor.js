// SPDX-License-Identifier: Apache-2.0
// TipTap editor factory (MIT core/extensions only — ADR-0015). Heavy; loaded via dynamic import() so it
// stays out of the main bundle (Spike-0 criterion #6). Emits CANONICAL JSON only — never HTML; the server
// renders + sanitizes (App\Content\CanonicalRenderer).
import { Editor, Extension, Node } from '@tiptap/core'
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
      b.className = 'novfora-suggest-item' + (i === active ? ' is-active' : '')
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
      el.className = 'novfora-suggest'
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

// ── embed node — stores a URL ONLY (canonical: { type:'embed', attrs:{ url } }). The server resolves it to a
// sandboxed iframe (allowlisted provider) or a link-card facade via App\Content\Oembed; the editor only shows
// a placeholder card. This renderHTML is editor-display only — it is NEVER stored or trusted (the editor emits
// canonical JSON; the server re-renders + sanitizes). ─────────────────────────────────────────────────────
const EmbedNode = Node.create({
  name: 'embed',
  group: 'block',
  atom: true,
  selectable: true,
  draggable: true,
  addAttributes() {
    return { url: { default: null } }
  },
  parseHTML() {
    return [{ tag: 'div[data-embed-url]' }]
  },
  renderHTML({ HTMLAttributes }) {
    const url = HTMLAttributes.url || ''
    return ['div', { 'data-embed-url': url, class: 'novfora-embed-edit' }, `▶ Embed — ${url}`]
  },
})

// ── spoiler / content-warning node — a collapsible block (canonical: { type:'spoiler', attrs:{ summary },
// content:[…] }). The server renders it to <details><summary>…</summary>…</details> (App\Content
// \CanonicalRenderer::spoiler) and sanitises the inner content; details/summary are on the allowlist. This
// renderHTML is editor-display only — never stored or trusted. ───────────────────────────────────────────
const SpoilerNode = Node.create({
  name: 'spoiler',
  group: 'block',
  content: 'block+',
  defining: true,
  addAttributes() {
    return { summary: { default: 'Spoiler' } }
  },
  parseHTML() {
    return [{ tag: 'details' }]
  },
  renderHTML({ node }) {
    const summary = node?.attrs?.summary || 'Spoiler'
    // open in the editor so its content is editable; `0` is the content hole.
    return ['details', { class: 'novfora-spoiler-edit', open: 'open' },
      ['summary', { contenteditable: 'false' }, summary],
      ['div', { class: 'novfora-spoiler-body' }, 0]]
  },
})

function insertSpoiler(editor) {
  const label = window.prompt('Spoiler label (shown while collapsed)', 'Spoiler')
  if (label === null) return
  editor.chain().focus().insertContent({
    type: 'spoiler', attrs: { summary: label.trim() || 'Spoiler' }, content: [{ type: 'paragraph' }],
  }).run()
}

function insertEmbed(editor) {
  const url = window.prompt('Paste a URL to embed (YouTube, Vimeo, or any link)')
  if (url && url.trim()) editor.chain().focus().insertContent({ type: 'embed', attrs: { url: url.trim() } }).run()
}

// Headings are clamped to the rendered schema (h1–h3 — CanonicalRenderer::MAX_HEADING). Anything else → h2.
const clampLevel = (l) => Math.min(3, Math.max(1, Number(l) || 2))

// A single bare http(s) URL with no surrounding whitespace — the trigger for smart-paste (URL → embed/link).
const isBareUrl = (s) => /^https?:\/\/\S+$/i.test(s)

// Defence-in-depth: never put a `javascript:`/`data:` href into the document client-side. The server
// re-sanitises every link on render regardless (CanonicalRenderer + allowlist), so this is belt-and-braces.
function sanitizeUrl(raw) {
  const s = String(raw || '').trim()
  if (!s) return ''
  if (/^(https?:\/\/|mailto:)/i.test(s)) return s
  if (/^[\w.-]+\.[a-z]{2,}(\/|$)/i.test(s)) return 'https://' + s // bare domain → assume https
  return ''
}

// Apply a link to the current selection, or — when the selection is collapsed — insert the URL as linked
// text (the incumbent "paste-a-link-with-nothing-selected" behaviour).
function applyLink(editor, href) {
  const url = sanitizeUrl(href)
  if (!url) return
  if (editor.state.selection.empty) {
    editor.chain().focus().insertContent([{ type: 'text', text: url, marks: [{ type: 'link', attrs: { href: url } }] }]).run()
  } else {
    editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run()
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
  { title: 'Embed (video / link)', run: (e) => insertEmbed(e) },
  { title: 'Spoiler / content warning', run: (e) => insertSpoiler(e) },
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

// ── toolbar commands (run from real user events; chained focus keeps the selection). `attrs` carries
// parameters for the menu-driven commands (heading level, emoji char, link href). ──────────────────────
export function runCommand(editor, name, attrs) {
  const chain = () => editor.chain().focus()
  const commands = {
    bold: () => chain().toggleBold().run(),
    italic: () => chain().toggleItalic().run(),
    strike: () => chain().toggleStrike().run(),
    code: () => chain().toggleCode().run(),
    paragraph: () => chain().setParagraph().run(),
    heading: () => chain().toggleHeading({ level: clampLevel(attrs?.level) }).run(),
    h2: () => chain().toggleHeading({ level: 2 }).run(), // kept for back-compat (slash + older callers)
    h3: () => chain().toggleHeading({ level: 3 }).run(),
    bulletList: () => chain().toggleBulletList().run(),
    orderedList: () => chain().toggleOrderedList().run(),
    blockquote: () => chain().toggleBlockquote().run(),
    codeBlock: () => chain().toggleCodeBlock().run(),
    hr: () => chain().setHorizontalRule().run(),
    table: () => chain().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run(),
    embed: () => insertEmbed(editor),
    spoiler: () => insertSpoiler(editor),
    emoji: () => { if (attrs?.ch) chain().insertContent(String(attrs.ch)).run() },
    // Link: the toolbar opens a proper dialog and calls setLink with the typed href; `link` is the
    // window.prompt fallback (used if the dialog is unavailable, e.g. a host that omits it).
    link: () => {
      const url = window.prompt('Link URL (leave blank to remove)')
      if (url === null) return
      url === '' ? chain().unsetLink().run() : applyLink(editor, url)
    },
    setLink: () => applyLink(editor, attrs?.href || ''),
    unsetLink: () => chain().unsetLink().run(),
  }
  ;(commands[name] ?? (() => {}))()
}

export function createNovForaEditor({ element, content, placeholder, uploadUrl, mentionUrl, onUpdate }) {
  const editor = new Editor({
    element,
    extensions: [
      StarterKit,
      TableKit.configure({ table: { resizable: false } }),
      Image,
      Placeholder.configure({ placeholder: placeholder ?? 'Write something…' }),
      Mention.configure({ HTMLAttributes: { class: 'mention' }, suggestion: mentionSuggestion(mentionUrl) }),
      EmbedNode,
      SpoilerNode,
      SlashCommand,
    ],
    content: content ?? { type: 'doc', content: [] },
    // Emit CANONICAL JSON only — never HTML. The server renders + sanitizes (CanonicalRenderer).
    onUpdate: ({ editor }) => onUpdate?.(editor.getJSON()),
    editorProps: {
      attributes: {
        class: 'novfora-prose',
        role: 'textbox',
        'aria-multiline': 'true',
        'aria-label': 'Post editor',
      },
      handlePaste: (_view, event) => {
        // 1) Image files → upload + insert (existing behaviour).
        const files = imageFiles(event.clipboardData?.files)
        if (files.length && uploadUrl) {
          files.forEach((f) => uploadAndInsert(editor, f, uploadUrl))
          return true
        }
        // 2) Smart paste: a bare URL on its own. With a selection → link it; otherwise → an embed facade
        // (the server resolves it to a sandboxed embed or a safe link-card). Anything else pastes normally.
        const text = (event.clipboardData?.getData('text/plain') || '').trim()
        if (isBareUrl(text)) {
          if (editor.state.selection.empty) {
            editor.chain().focus().insertContent({ type: 'embed', attrs: { url: text } }).run()
          } else {
            applyLink(editor, text)
          }
          return true
        }
        return false
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
