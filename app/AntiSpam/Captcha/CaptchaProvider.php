<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam\Captcha;

/**
 * A swappable CAPTCHA challenge (ADR-0007 §2.5). Built-ins need no external service (Q&A, none); external
 * providers (Turnstile/hCaptcha) are pluggable and DEGRADE to Q&A when unavailable — the CaptchaManager
 * enforces that, so a misconfigured or unreachable provider never blocks registration.
 */
interface CaptchaProvider
{
    /** Stable key: qa | turnstile | null. */
    public function key(): string;

    /** Can this provider actually be used right now (configured/reachable)? false → manager degrades to Q&A. */
    public function available(): bool;

    /**
     * View data for rendering the challenge; [] means invisible (no user-facing challenge).
     *
     * @return array<string,mixed>
     */
    public function challenge(): array;

    /**
     * Verify the submitted response. MUST NOT throw — a verifier outage resolves to a boolean.
     *
     * @param  array<string,mixed>  $input  the submitted form fields
     */
    public function verify(array $input): bool;
}
