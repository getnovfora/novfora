{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Live, group-derived staff flair (ACP v3 · v3-g): a small role marker for staff members, slotted into the
     post author block, the profile hero, and the members-directory card. DISPLAY-ONLY — the label is derived
     live from the user's groups + co-owner flag + per-forum moderator assignments via User::staffRole(); it
     NEVER reads or writes acl_entries. The whole flair is gated by the global members.staff_flair_show_badge
     setting; an optional per-group icon (groups.show_staff_icon) and per-group title override (groups.staff_title)
     decorate it. Renders nothing for a guest/deleted author or a non-staff member. --}}
@props(['user' => null])
@php($role = $user?->staffRole())
@if ($role !== null && app(\App\Settings\Settings::class)->bool('members.staff_flair_show_badge'))
    <x-ui.badge variant="accent" {{ $attributes }} dusk="staff-flair">
        @if ($user->showsStaffIcon())<x-ui.icon name="shield" class="h-3 w-3" />@endif{{ $user->staffTitle() ?? __('forum.role_'.$role) }}
    </x-ui.badge>
@endif
