<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use App\Settings\Settings;

/**
 * The single privacy + tuning gate for EXTERNAL anti-spam signals (Phase 4 · M6.3). StopForumSpam is already
 * wired at registration; this centralises the operator's control over it:
 *   • apiEnabled()        — the existing live-API opt-in (metadata lookups: IP / email / username).
 *   • confidenceThreshold — admin-tunable block threshold (DB setting → config → 75).
 *   • maySubmitContent()  — the PRIVACY FENCE: post CONTENT is sent to a third party ONLY when an admin has
 *                           explicitly opted in. DEFAULT FALSE. Nothing in the build sends content unless this
 *                           is on, so a community's posts never leave the server without a deliberate decision.
 */
final class ExternalSignalPolicy
{
    public function __construct(private readonly Settings $settings) {}

    /** The StopForumSpam live-API opt-in (metadata only — never post content). */
    public function apiEnabled(): bool
    {
        return $this->settings->bool('antispam.sfs_use_api');
    }

    /** The confidence at/above which a StopForumSpam hit hard-blocks a registration (admin-tunable). */
    public function confidenceThreshold(): int
    {
        $value = (int) $this->settings->int('antispam.sfs_confidence_threshold');

        return $value > 0 ? $value : (int) config('novfora.antispam.registration.stopforumspam.confidence_threshold', 75);
    }

    /** THE FENCE: may user-authored CONTENT be submitted to a third party? Default false — opt-in only. */
    public function maySubmitContent(): bool
    {
        return $this->settings->bool('antispam.external_content_optin');
    }

    /** The StopForumSpam submission API key (encrypted at rest). Empty disables reporting. */
    public function apiKey(): string
    {
        return $this->settings->string('antispam.sfs_api_key');
    }
}
