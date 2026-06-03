<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme;

/** A resolved child theme (ADR-0009) — its identity, the API major it targets, and its filesystem path. */
final readonly class Theme
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $version,
        public string $apiVersion,
        public ?string $parent,
        public string $path,
    ) {}

    public function viewPath(): string
    {
        return $this->path.DIRECTORY_SEPARATOR.'views';
    }
}
