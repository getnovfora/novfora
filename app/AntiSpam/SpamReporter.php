<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use Illuminate\Support\Facades\Http;

/**
 * Reports a confirmed spammer to StopForumSpam (Phase 4 · M6.3) — an OPT-IN, privacy-conscious external
 * submission. It is inert by default: it makes NO outbound call unless the live API is enabled AND a
 * submission key is configured. Crucially (the privacy fence), the spammer's post CONTENT is included as
 * evidence ONLY when an admin has explicitly opted in (`ExternalSignalPolicy::maySubmitContent()`); otherwise
 * only the metadata StopForumSpam already uses (IP / email / username) is sent — never the post body.
 *
 * ⚠ NOT VALIDATED against the live StopForumSpam submission API (no key in this build) — the gate logic + the
 * request shape are proven with a mocked HTTP client.
 */
final class SpamReporter
{
    private const ADD_API = 'https://www.stopforumspam.com/add.php';

    public function __construct(private readonly ExternalSignalPolicy $policy) {}

    /**
     * Submit a spammer report. Returns false (and makes no network call) when reporting is not enabled.
     * $content is the offending post body; it is transmitted ONLY when the content opt-in is on.
     */
    public function reportSpammer(string $ip, string $email, string $username, ?string $content = null): bool
    {
        if (! $this->policy->apiEnabled() || $this->policy->apiKey() === '') {
            return false; // opt-in + key required — no external submission otherwise
        }

        $payload = [
            'api_key' => $this->policy->apiKey(),
            'ip' => $ip,
            'email' => $email,
            'username' => $username,
        ];

        // PRIVACY FENCE: post content leaves the server only with an explicit admin opt-in.
        if ($content !== null && $content !== '' && $this->policy->maySubmitContent()) {
            $payload['evidence'] = $content;
        }

        try {
            return Http::asForm()->post(self::ADD_API, $payload)->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
