<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Tier\Probes;

class RedisProbe extends Probe
{
    public function key(): string
    {
        return 'redis';
    }

    public function label(): string
    {
        return 'Redis';
    }

    public function unlocks(): string
    {
        return 'Faster cache & sessions, a real queue worker, and broadcast scaling.';
    }

    public function configured(): bool
    {
        // "Configured" = some capability is actually pointed at redis (not merely REDIS_HOST being present).
        return in_array('redis', [
            config('cache.default'),
            config('session.driver'),
            config('queue.default'),
            config('broadcasting.default'),
        ], true);
    }

    protected function check(): bool
    {
        $host = (string) config('database.redis.default.host', '127.0.0.1');
        $port = (int) config('database.redis.default.port', 6379);

        return $this->tcp($host, $port);
    }
}
