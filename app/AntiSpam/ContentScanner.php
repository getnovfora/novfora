<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

/**
 * Pluggable post-time content scanning contract (ADR-0007 §2.4). M3 ships the local-heuristics
 * implementation behind this interface; an Akismet-backed provider is a Phase 2 module implementing the
 * same contract (so the integration point exists now, the network dependency does not).
 */
interface ContentScanner
{
    public function scan(string $text): ScanResult;
}
