<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Tier\Probes;

use Illuminate\Support\Facades\Http;

class MeilisearchProbe extends Probe
{
    public function key(): string
    {
        return 'meilisearch';
    }

    public function label(): string
    {
        return 'Meilisearch / Typesense';
    }

    public function unlocks(): string
    {
        return 'Typo-tolerant, faceted search with far better relevance/latency at scale (ADR-0010).';
    }

    public function configured(): bool
    {
        return in_array(config('scout.driver'), ['meilisearch', 'typesense'], true);
    }

    protected function check(): bool
    {
        $host = (string) config('scout.meilisearch.host', config('scout.typesense.host', ''));

        if ($host === '') {
            return false;
        }

        return Http::timeout(2)->connectTimeout(1)->get(rtrim($host, '/').'/health')->successful();
    }
}
