<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Harness placeholder for the non-negotiable "permission-mask truth-table" suite (ADR-0006).
// The engine itself lands in M1; this reserves the directory and signals the requirement so CI
// surfaces it as outstanding work rather than silently missing.

it('resolves ALLOW / NO / NEVER masks across global -> category -> forum -> thread (M1)')
    ->todo(note: 'Permission-mask engine + exhaustive truth-tables land in M1 (ADR-0006).');
