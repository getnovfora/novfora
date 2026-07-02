<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support;

/**
 * Gravatar URL builder (U18, ADR-0107). PRIVACY FENCE: this only COMPUTES a URL — the image is fetched by
 * the member's BROWSER, never by this server (no outbound HTTP call here, ever), and the render seam
 * (<x-ui.avatar>) only emits it behind the admin opt-in `members.gravatar_enabled` (default OFF). What
 * leaves the site is the MD5 hash of the member's email, client-side, at image-load time — never the
 * address itself.
 */
final class Gravatar
{
    public static function url(string $email, int $size = 80): string
    {
        $hash = md5(strtolower(trim($email)));

        return "https://www.gravatar.com/avatar/{$hash}?s={$size}&d=mp";
    }
}
