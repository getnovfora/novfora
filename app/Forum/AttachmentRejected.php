<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use RuntimeException;

/**
 * Thrown by AttachmentService when an upload is rejected DURING processing (not by the request validator) —
 * e.g. an image whose header dimensions exceed the decompression-bomb limit, or bytes that pass the MIME
 * allowlist yet fail to decode as a real image (a polyglot). The controller maps it to a 422, so a rejected
 * upload never 500s and never leaves a half-written file behind.
 */
final class AttachmentRejected extends RuntimeException {}
