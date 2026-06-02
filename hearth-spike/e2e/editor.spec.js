// SPDX-License-Identifier: Apache-2.0
// Spike 0 browser criteria against the live Livewire 4 + TipTap page.
import { test, expect } from '@playwright/test'

const EDITOR = '[data-editor] .ProseMirror'

test.beforeEach(async ({ page }) => {
  await page.goto('/spike')
  // TipTap mounts asynchronously (dynamic import). Wait for the ProseMirror surface.
  await page.waitForSelector(EDITOR, { timeout: 20000 })
})

test('#1a content survives a sibling (non-validation) Livewire re-render', async ({ page }) => {
  const editor = page.locator(EDITOR)
  await editor.click()
  await page.keyboard.type('Hello world')
  await expect(editor).toContainText('Hello world')

  await page.click('[data-action="ping"]')
  await expect(page.locator('[data-clicks]')).toHaveText('1') // server round-trip happened
  await expect(editor).toContainText('Hello world')           // wire:ignore preserved the editor
})

test('#1a content survives a validation-error re-render', async ({ page }) => {
  const editor = page.locator(EDITOR)
  await editor.click()
  await page.keyboard.type('Persist me')

  await page.click('[data-action="save"]')                    // empty title -> validation error
  await expect(page.locator('[data-error="title"]')).toBeVisible()
  await expect(editor).toContainText('Persist me')            // editor still intact
})

test('save renders a sanitized server-side preview from canonical JSON', async ({ page }) => {
  const editor = page.locator(EDITOR)
  await editor.click()
  await page.keyboard.type('Hello preview')
  await page.fill('#title', 'A valid title')
  await page.click('[data-action="save"]')
  await expect(page.locator('[data-preview]')).toContainText('Hello preview')
})

test('#3 mention suggestion inserts a mention node', async ({ page }) => {
  const editor = page.locator(EDITOR)
  await editor.click()
  await page.keyboard.type('hi @al')
  await page.waitForSelector('.hearth-mention-list button[data-mention-item="alice"]', { timeout: 5000 })
  await page.click('.hearth-mention-list button[data-mention-item="alice"]')
  await expect(editor.locator('.mention')).toContainText('alice')
})

test('#5 editor is keyboard-operable with ARIA attributes', async ({ page }) => {
  const mount = page.locator('[data-editor]')
  await expect(mount).toHaveAttribute('role', 'textbox')
  await expect(mount).toHaveAttribute('aria-multiline', 'true')

  const editable = page.locator(EDITOR)
  await expect(editable).toHaveAttribute('contenteditable', 'true')
  await editable.focus()
  await page.keyboard.type('typed via keyboard only')
  await expect(editable).toContainText('typed via keyboard only')
})

test('#2 image upload+insert pipeline (shared by drag-drop / paste / picker) inserts an <img>', async ({ page }) => {
  // The upload+insert path is identical for drag-drop (handleDrop), paste (handlePaste) and the
  // file picker; we drive it via the picker because synthetic native file-drops are unreliable
  // headless. This validates the actual integration risk: fetch upload + editor insert inside wire:ignore.
  const editor = page.locator(EDITOR)
  await editor.click()
  await page.setInputFiles('[data-upload]', {
    name: 'pixel.png',
    mimeType: 'image/png',
    buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64'),
  })
  const img = editor.locator('img')
  await expect(img).toBeVisible({ timeout: 8000 })
  expect(await img.getAttribute('src')).toContain('/spike-uploads/')
})
