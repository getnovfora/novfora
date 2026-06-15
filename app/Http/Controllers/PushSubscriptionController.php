<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Push\WebPushService;
use App\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Web Push subscription lifecycle for the authenticated user (Phase 4 · M3.2). The browser subscribes with the
 * site VAPID public key and POSTs its PushSubscription here; the existence of the row is the opt-in. Own-account
 * only — every action scopes to the request user.
 */
class PushSubscriptionController extends Controller
{
    /** The VAPID public key the browser needs to subscribe, plus whether push is available. */
    public function publicKey(WebPushService $push, Settings $settings): JsonResponse
    {
        return response()->json([
            'enabled' => $push->isConfigured(),
            'publicKey' => $push->isConfigured() ? $settings->string('push.vapid_public_key') : null,
        ]);
    }

    /** Store (or refresh) a device subscription for the authenticated user. */
    public function subscribe(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:1024'],
            'keys.p256dh' => ['required', 'string', 'max:191'],
            'keys.auth' => ['required', 'string', 'max:191'],
            'contentEncoding' => ['nullable', 'string', 'max:32'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($data['endpoint'])],
            [
                'user_id' => $user->getKey(),
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
            ],
        );

        return response()->json(['ok' => true], 201);
    }

    /** Remove a device subscription (this user's, by endpoint). */
    public function unsubscribe(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1024']]);

        PushSubscription::query()
            ->where('user_id', $user->getKey())
            ->where('endpoint_hash', PushSubscription::hashEndpoint($data['endpoint']))
            ->delete();

        return response()->json(['ok' => true]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
