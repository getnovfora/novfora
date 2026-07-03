<?php

// SPDX-License-Identifier: Apache-2.0

// Permission-aware UI contract copy (NOV-96 / ADR-0109). The route-level friendly-403 page + the <x-action>
// inline states. Reason strings are USER-SAFE sentences (they surface directly to the viewer).
return [
    // Route-level friendly-403 page (App\Exceptions\FriendlyDenialException → errors/friendly-denial).
    'denied' => [
        'page_title' => 'Access denied',
        'heading' => 'You can’t access this',
        'sign_in' => 'Sign in',
        'go_back' => 'Go back',

        // Curated reasons passed to FriendlyDenialException::deny().
        'staff_only' => 'This area is available to staff only.',
        'signed_in' => 'You need to be signed in to do that.',
        'generic' => 'You don’t have permission to view this page.',
    ],

    // <x-action> inline states (disabled-with-reason + sign-in CTA).
    'reason' => [
        'thread_locked' => 'This thread is locked.',
        'no_permission' => 'You don’t have permission to do this.',
    ],
    'cta' => [
        'sign_in_to_reply' => 'Sign in to reply',
        'sign_in' => 'Sign in',
    ],
];
