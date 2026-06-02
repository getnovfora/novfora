<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Tier\Probes;

use App\Services\Tier\ProbeResult;
use App\Services\Tier\ServiceProbe;
use Throwable;

/**
 * Base probe: wraps the concrete check() so it can NEVER throw (ADR-0003 "never error").
 * Provides a short-timeout TCP helper that will not hang on a black-holed host.
 */
abstract class Probe implements ServiceProbe
{
    public function probe(): ProbeResult
    {
        if (! $this->configured()) {
            return ProbeResult::notConfigured();
        }

        try {
            $start = microtime(true);
            $ok = $this->check();
            $ms = (int) round((microtime(true) - $start) * 1000);

            return $ok ? ProbeResult::up($ms) : ProbeResult::down();
        } catch (Throwable $e) {
            // Surface only the exception class — never a message that could leak config/secrets.
            return ProbeResult::down(class_basename($e));
        }
    }

    /** Return true if the service answered. May throw — the wrapper catches it. */
    abstract protected function check(): bool;

    /** Bounded TCP reachability check; @ + finite timeout so a dead host can't hang the request. */
    protected function tcp(string $host, int $port, float $timeout = 1.0): bool
    {
        $conn = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($conn !== false) {
            fclose($conn);

            return true;
        }

        return false;
    }
}
