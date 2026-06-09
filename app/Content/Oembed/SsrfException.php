<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Content\Oembed;

/** A URL was rejected by the SSRF guard (bad scheme, unresolvable host, or a private/reserved target). */
final class SsrfException extends \RuntimeException {}
