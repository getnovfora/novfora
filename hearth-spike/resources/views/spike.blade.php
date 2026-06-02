<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Hearth Spike 0 — Editor</title>
    @vite(['resources/js/app.js'])
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; }
        label { display:block; font-weight:600; margin-bottom:.25rem; }
        input[type=text] { width:100%; padding:.5rem; box-sizing:border-box; }
        .editor { border:1px solid #ccc; border-radius:6px; padding:.75rem; min-height:8rem; margin-top:.75rem; }
        .editor:focus-within { outline:2px solid #4f46e5; }
        .editor .ProseMirror:focus { outline:none; }
        .editor .is-editor-empty:first-child::before { content: attr(data-placeholder); color:#999; float:left; height:0; pointer-events:none; }
        .mention { color:#4f46e5; font-weight:600; }
        .hearth-mention-list { position:absolute; background:#fff; border:1px solid #ccc; border-radius:6px; box-shadow:0 4px 12px rgba(0,0,0,.12); display:flex; flex-direction:column; z-index:50; }
        .hearth-mention-list button { padding:.4rem .7rem; border:0; background:none; text-align:left; cursor:pointer; }
        .hearth-mention-list button:hover { background:#eef; }
        .err { color:#c00; margin:.25rem 0 0; }
        .preview { margin-top:1rem; padding:.75rem; border:1px dashed #999; border-radius:6px; }
        .actions { margin-top:.75rem; display:flex; gap:.5rem; }
        button[data-action] { padding:.5rem .9rem; cursor:pointer; }
    </style>
</head>
<body>
    <h1>Spike 0 — WYSIWYG ↔ Livewire 4</h1>
    <livewire:post-composer />
    {{-- Livewire 4 auto-injects its scripts into a full-page response. --}}
</body>
</html>
