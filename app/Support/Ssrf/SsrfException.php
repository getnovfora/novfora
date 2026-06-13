<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Ssrf;

/**
 * Thrown when a guarded egress request resolves to (or redirects to) a blocked address. Callers catch it and
 * degrade — the oEmbed fetcher to a facade, the webhook runner to a scheduled retry — never letting it escape
 * as a fatal error.
 */
final class SsrfException extends \RuntimeException {}
