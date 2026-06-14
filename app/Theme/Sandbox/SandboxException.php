<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme\Sandbox;

use RuntimeException;

/**
 * A parse-time or render-time error in a sandbox template (ADR-0038). Carries a SAFE, human-readable message
 * (never a PHP stack detail) so it can be shown to the admin editing the template — and so a broken template
 * degrades to a visible notice rather than leaking or executing anything.
 */
final class SandboxException extends RuntimeException {}
