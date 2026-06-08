<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Deliverability\Suppressor;
use App\Deliverability\Webhook\ProviderWebhookParser;
use App\Deliverability\Webhook\WebhookVerifier;
use App\Http\Controllers\MailWebhookController;
use App\Models\EmailSuppression;
use App\Models\MailWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

/*
| GO CRITERION 2 (webhook path) — the inbound bounce/complaint webhook is UNAUTHENTICATED + UNTRUSTED, so
| its biggest risk is suppression-as-DoS (forging a bounce to silence a victim). Trust is an HMAC over the
| RAW body; a missing/wrong/stale signature is rejected with NO DB write; malformed/oversize bodies never
| 500; a replay is acknowledged but applied at most once. Driven controller-direct (the route is registered
| only when configured, which is a boot-time decision).
*/

uses(RefreshDatabase::class);

const WEBHOOK_SECRET = 'a-long-random-per-install-secret';

beforeEach(function () {
    config([
        'hearth.deliverability.enabled' => true,
        'hearth.deliverability.webhook.enabled' => true,
        'hearth.deliverability.webhook.secret' => WEBHOOK_SECRET,
        'hearth.deliverability.webhook.max_body_bytes' => 262144,
        'hearth.deliverability.webhook.tolerance_seconds' => 300,
    ]);
});

function webhookCall(string $raw, ?string $sig, ?string $ts, string $provider = 'generic'): Illuminate\Http\JsonResponse
{
    $request = Request::create("/webhooks/mail/{$provider}", 'POST', [], [], [], [], $raw);
    if ($sig !== null) {
        $request->headers->set('X-Hearth-Signature', $sig);
    }
    if ($ts !== null) {
        $request->headers->set('X-Hearth-Timestamp', $ts);
    }

    return app(MailWebhookController::class)->__invoke(
        $request,
        $provider,
        app(WebhookVerifier::class),
        app(ProviderWebhookParser::class),
        app(Suppressor::class),
    );
}

/** @return array{0:string,1:string} [timestamp, signature] over the canonical "{ts}.{raw}". */
function signWebhook(string $raw, ?string $ts = null): array
{
    $ts ??= (string) now()->getTimestamp();

    return [$ts, hash_hmac('sha256', "{$ts}.{$raw}", WEBHOOK_SECRET)];
}

it('suppresses on a valid-HMAC hard bounce', function () {
    $raw = json_encode(['type' => 'bounce', 'email' => 'bob@example.com', 'permanent' => true, 'id' => 'evt-1']);
    [$ts, $sig] = signWebhook($raw);

    $response = webhookCall($raw, $sig, $ts);

    expect($response->getStatusCode())->toBe(200)
        ->and(EmailSuppression::where('email', 'bob@example.com')->exists())->toBeTrue();
});

it('rejects a missing signature with 401 and no suppression', function () {
    $raw = json_encode(['type' => 'bounce', 'email' => 'victim@example.com', 'permanent' => true, 'id' => 'x']);
    [$ts] = signWebhook($raw);

    $response = webhookCall($raw, null, $ts);

    expect($response->getStatusCode())->toBe(401)
        ->and(EmailSuppression::count())->toBe(0);
});

it('rejects a forged signature with 401 and no suppression', function () {
    $raw = json_encode(['type' => 'bounce', 'email' => 'victim@example.com', 'permanent' => true, 'id' => 'x']);
    [$ts] = signWebhook($raw);

    $response = webhookCall($raw, 'deadbeef'.str_repeat('0', 56), $ts);

    expect($response->getStatusCode())->toBe(401)
        ->and(EmailSuppression::count())->toBe(0);
});

it('rejects a stale timestamp (replay window) with 401', function () {
    $raw = json_encode(['type' => 'bounce', 'email' => 'victim@example.com', 'permanent' => true, 'id' => 'x']);
    $staleTs = (string) (now()->getTimestamp() - 4000); // well outside the 300s tolerance
    [, $sig] = signWebhook($raw, $staleTs);

    $response = webhookCall($raw, $sig, $staleTs);

    expect($response->getStatusCode())->toBe(401)
        ->and(EmailSuppression::count())->toBe(0);
});

it('acknowledges a replay but applies suppression at most once', function () {
    $raw = json_encode(['type' => 'bounce', 'email' => 'bob@example.com', 'permanent' => true, 'id' => 'evt-42']);
    [$ts, $sig] = signWebhook($raw);

    $first = webhookCall($raw, $sig, $ts);
    $second = webhookCall($raw, $sig, $ts);

    expect($first->getStatusCode())->toBe(200)
        ->and($second->getStatusCode())->toBe(200)
        ->and(EmailSuppression::where('email', 'bob@example.com')->count())->toBe(1)
        ->and(MailWebhookEvent::count())->toBe(1);
});

it('rejects a malformed JSON body with 422, never 500', function () {
    $raw = 'this is not json';
    [$ts, $sig] = signWebhook($raw);

    $response = webhookCall($raw, $sig, $ts);

    expect($response->getStatusCode())->toBe(422)
        ->and(EmailSuppression::count())->toBe(0);
});

it('rejects an oversize body with 413 before parsing', function () {
    config(['hearth.deliverability.webhook.max_body_bytes' => 32]);
    $raw = json_encode(['type' => 'bounce', 'email' => 'bob@example.com', 'permanent' => true, 'id' => 'big', 'pad' => str_repeat('x', 200)]);
    [$ts, $sig] = signWebhook($raw);

    $response = webhookCall($raw, $sig, $ts);

    expect($response->getStatusCode())->toBe(413);
});

it('does not suppress a transient/soft bounce delivered by webhook', function () {
    $raw = json_encode(['type' => 'bounce', 'email' => 'soft@example.com', 'permanent' => false, 'id' => 'soft-1']);
    [$ts, $sig] = signWebhook($raw);

    $response = webhookCall($raw, $sig, $ts);

    expect($response->getStatusCode())->toBe(200)
        ->and(EmailSuppression::where('email', 'soft@example.com')->exists())->toBeFalse();
});
