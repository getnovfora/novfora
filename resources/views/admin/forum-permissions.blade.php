{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- ACP v3 · v3-c — Forums → (forum) → Permissions: the per-forum-scope card-per-group editor (overrides). --}}
<x-admin.shell :title="__('admin.perms.title').' — '.$forum->title">
    <livewire:permissions.group-editor scope-type="forum" :scope-id="$forum->id" />
</x-admin.shell>
