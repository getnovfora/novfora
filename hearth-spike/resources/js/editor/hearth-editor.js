// SPDX-License-Identifier: Apache-2.0
// TipTap editor factory (MIT core/extensions only — ADR-0015). Heavy; loaded via dynamic import().
import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import Placeholder from '@tiptap/extension-placeholder'
import Mention from '@tiptap/extension-mention'
import Image from '@tiptap/extension-image'

// StarterKit (v3) already bundles Link, Bold, Italic, Code, Heading, lists, blockquote, codeBlock, etc.
// Do NOT register those a second time — re-registering a bundled extension throws.

const DEMO_USERS = ['alice', 'bob', 'carol', 'dave', 'erin']

// Minimal, dependency-free mention popup (no tippy). Enough to validate criterion #3 in the spike.
function mentionSuggestion() {
  return {
    items: ({ query }) =>
      DEMO_USERS.filter((n) => n.toLowerCase().startsWith((query || '').toLowerCase())).slice(0, 5),
    render: () => {
      let el = null
      const place = (props) => {
        const rect = props.clientRect && props.clientRect()
        if (!el || !rect) return
        el.style.position = 'absolute'
        el.style.left = `${rect.left + window.scrollX}px`
        el.style.top = `${rect.bottom + window.scrollY}px`
      }
      const paint = (props) => {
        if (!el) return
        el.innerHTML = ''
        props.items.forEach((item) => {
          const b = document.createElement('button')
          b.type = 'button'
          b.dataset.mentionItem = item
          b.textContent = `@${item}`
          b.addEventListener('mousedown', (e) => {
            e.preventDefault()
            props.command({ id: item, label: item })
          })
          el.appendChild(b)
        })
        place(props)
      }
      return {
        onStart: (props) => {
          el = document.createElement('div')
          el.className = 'hearth-mention-list'
          el.setAttribute('role', 'listbox')
          document.body.appendChild(el)
          paint(props)
        },
        onUpdate: paint,
        onKeyDown: (props) => {
          if (props.event.key === 'Escape') {
            el?.remove()
            el = null
            return true
          }
          return false
        },
        onExit: () => {
          el?.remove()
          el = null
        },
      }
    },
  }
}

// Upload an image to the server and insert it at the cursor. Shared by paste + drop + picker (#2).
export async function uploadAndInsert(editor, file) {
  const form = new FormData()
  form.append('file', file)
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
  const res = await fetch('/spike/upload', {
    method: 'POST',
    headers: token ? { 'X-CSRF-TOKEN': token } : {},
    body: form,
  })
  if (!res.ok) return
  const { url } = await res.json().catch(() => ({}))
  if (!url) return
  // Defer one tick so any in-flight ProseMirror view update has settled before we dispatch.
  // Inserting synchronously right after the await can throw "Applying a mismatched transaction".
  await new Promise((resolve) => setTimeout(resolve, 0))
  editor.commands.insertContent({ type: 'image', attrs: { src: url } })
}

const imageFiles = (list) => Array.from(list || []).filter((f) => f.type && f.type.startsWith('image/'))

export function createHearthEditor({ element, content, onUpdate }) {
  let editor
  editor = new Editor({
    element,
    extensions: [
      StarterKit,
      Image,
      Placeholder.configure({ placeholder: 'Write something…' }),
      Mention.configure({
        HTMLAttributes: { class: 'mention' },
        suggestion: mentionSuggestion(),
      }),
    ],
    content: content ?? { type: 'doc', content: [] },
    // Emit CANONICAL JSON only — never HTML. The server renders + sanitizes (CanonicalRenderer).
    onUpdate: ({ editor }) => onUpdate?.(editor.getJSON()),
    editorProps: {
      handlePaste: (_view, event) => {
        const files = imageFiles(event.clipboardData?.files)
        if (!files.length) return false
        files.forEach((f) => uploadAndInsert(editor, f))
        return true
      },
      handleDrop: (_view, event) => {
        const files = imageFiles(event.dataTransfer?.files)
        if (!files.length) return false
        event.preventDefault()
        files.forEach((f) => uploadAndInsert(editor, f))
        return true
      },
    },
  })
  return editor
}
