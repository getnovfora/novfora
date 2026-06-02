<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Tier;

/**
 * Aggregates the active service tier (ADR-0003). The result is memoized PER REQUEST only — it is
 * deliberately NOT persisted through the application cache, because the cache store itself may be the
 * very service that is down. Building a snapshot never throws: it reads config and runs probes that
 * each catch everything.
 */
final class ServiceTier
{
    private ?TierSnapshot $memo = null;

    /** @param iterable<ServiceProbe> $probes */
    public function __construct(private readonly iterable $probes) {}

    public function snapshot(bool $fresh = false): TierSnapshot
    {
        if ($fresh) {
            $this->memo = null;
        }

        return $this->memo ??= $this->build();
    }

    public function refresh(): TierSnapshot
    {
        return $this->snapshot(true);
    }

    public function tierFor(Capability $c): Tier
    {
        return $this->snapshot()->capabilities[$c->value]->tier;
    }

    public function isEnhanced(Capability $c): bool
    {
        return $this->tierFor($c) === Tier::Enhanced;
    }

    private function build(): TierSnapshot
    {
        $capabilities = [];
        foreach (Capability::cases() as $c) {
            $driver = (string) ($this->driverFor($c) ?? 'unknown');
            $capabilities[$c->value] = new CapabilityStatus($c, $driver, $this->deriveTier($c, $driver));
        }

        $services = [];
        $overallEnhanced = false;
        foreach ($this->probes as $probe) {
            $r = $probe->probe(); // never throws
            $services[$probe->key()] = new ServiceStatus(
                key: $probe->key(),
                label: $probe->label(),
                configured: $r->configured,
                reachable: $r->reachable,
                latencyMs: $r->latencyMs,
                note: $r->note,
                unlocks: $probe->unlocks(),
            );
        }

        foreach ($capabilities as $status) {
            if ($status->tier === Tier::Enhanced) {
                $overallEnhanced = true;
                break;
            }
        }

        return new TierSnapshot($capabilities, $services, $overallEnhanced ? Tier::Enhanced : Tier::Baseline);
    }

    private function driverFor(Capability $c): ?string
    {
        return match ($c) {
            Capability::Cache => config('cache.default'),
            Capability::Session => config('session.driver'),
            Capability::Queue => config('queue.default'),
            Capability::Search => config('scout.driver', 'database'),
            Capability::Broadcast => config('broadcasting.default'),
            Capability::Files => config('filesystems.default'),
            Capability::Mail => config('mail.default'),
        };
    }

    private function deriveTier(Capability $c, string $driver): Tier
    {
        $enhanced = match ($c) {
            Capability::Cache, Capability::Session => in_array($driver, ['redis', 'memcached', 'dynamodb'], true),
            Capability::Queue => in_array($driver, ['redis', 'sqs', 'beanstalkd'], true),
            Capability::Search => in_array($driver, ['meilisearch', 'typesense', 'algolia'], true),
            Capability::Broadcast => in_array($driver, ['reverb', 'pusher', 'ably'], true),
            Capability::Files => in_array($driver, ['s3', 'gcs'], true),
            Capability::Mail => in_array($driver, ['ses', 'postmark', 'mailgun', 'resend'], true),
        };

        return $enhanced ? Tier::Enhanced : Tier::Baseline;
    }
}
