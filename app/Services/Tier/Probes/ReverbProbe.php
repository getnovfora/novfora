<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Tier\Probes;

class ReverbProbe extends Probe
{
    public function key(): string
    {
        return 'reverb';
    }

    public function label(): string
    {
        return 'Reverb / Pusher (WebSockets)';
    }

    public function unlocks(): string
    {
        return 'True real-time updates & web push. Baseline stays on Livewire polling (needs a daemon).';
    }

    public function configured(): bool
    {
        return in_array(config('broadcasting.default'), ['reverb', 'pusher'], true);
    }

    protected function check(): bool
    {
        $host = (string) config('broadcasting.connections.reverb.options.host', config('reverb.servers.reverb.host', '127.0.0.1'));
        $port = (int) config('broadcasting.connections.reverb.options.port', config('reverb.servers.reverb.port', 8080));

        return $this->tcp($host, $port);
    }
}
