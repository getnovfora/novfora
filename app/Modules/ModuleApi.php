<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Modules;

/**
 * The MODULE / PLUGIN API version — the semver'd PUBLIC CONTRACT (ADR-0008 / ADR-0031). A module declares the
 * API major(s) it targets via its manifest `api_version` constraint; the engine checks the core's VERSION
 * against that constraint BEFORE loading the module, so incompatibility is "known before you enable / upgrade",
 * never discovered as breakage afterwards.
 *
 * Contract rules: adding events / filter hooks / slots = MINOR. Changing or removing a public event payload,
 * filter name + signature, slot name, or lifecycle behaviour = MAJOR. Within a major, the surface is frozen.
 */
final class ModuleApi
{
    /**
     * The core's current MODULE API version. Bump per the contract rules above.
     *
     * 1.1.0 (Phase-3 hardening / dogfood): MINOR, additive — added the `topic.post.aside` UI slot (a per-post
     * extension outlet, with the post + topic as context) and a plugin SETTINGS registration path
     * (App\Settings\SettingsRegistry::register). Modules targeting `^1.0` keep working unchanged.
     */
    public const VERSION = '1.1.0';

    /** Whether the core's MODULE API satisfies a module's declared `api_version` constraint. */
    public static function satisfies(string $constraint): bool
    {
        return SemverConstraint::satisfies(self::VERSION, $constraint);
    }
}
