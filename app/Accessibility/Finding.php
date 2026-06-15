<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Accessibility;

/**
 * One machine-detectable accessibility violation found by the auditor.
 *
 * `rule` is the stable WCAG success-criterion id (e.g. "1.1.1"); `level` is the conformance level it falls
 * under ("A" / "AA"); `message` is human-readable; `snippet` is a short excerpt of the offending element to
 * locate it. These are deterministic, parser-level checks only — contrast, focus order and screen-reader
 * semantics are NOT machine-checkable here and live in the manual checklist (docs/architecture/accessibility.md).
 */
final class Finding
{
    public function __construct(
        public readonly string $rule,
        public readonly string $level,
        public readonly string $message,
        public readonly string $snippet = '',
    ) {}

    public function label(): string
    {
        return "[WCAG {$this->rule} {$this->level}] {$this->message}".($this->snippet !== '' ? " — {$this->snippet}" : '');
    }
}
