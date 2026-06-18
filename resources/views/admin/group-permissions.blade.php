{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- ACP v3 · v3-c — Groups → Group permissions: the GLOBAL-scope card-per-group editor (the global defaults). --}}
<x-admin.shell :title="__('admin.perms.title')">
    <livewire:permissions.group-editor scope-type="global" />
</x-admin.shell>
