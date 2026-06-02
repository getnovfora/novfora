<?php
// SPDX-License-Identifier: Apache-2.0
use Livewire\Component;

new class extends Component
{
    // Any Livewire action re-renders this component, which re-runs the (per-request) probes.
    public function redetect(): void {}
};
?>

@php($snapshot = app(\App\Services\Tier\ServiceTier::class)->snapshot(fresh: true))
<div style="font-family:system-ui,sans-serif">
    <p style="display:flex;align-items:center;gap:.75rem">
        <strong>Overall tier:</strong>
        <span style="padding:.15rem .5rem;border:1px solid #999;border-radius:4px">{{ $snapshot->overall->label() }}</span>
        <button type="button" wire:click="redetect" wire:loading.attr="disabled" style="padding:.3rem .7rem;cursor:pointer">Re-detect</button>
        <span wire:loading style="color:#777">probing…</span>
    </p>

    <table cellpadding="7" cellspacing="0" style="border-collapse:collapse;width:100%;margin-bottom:1.5rem">
        <thead>
            <tr style="background:#f4f4f5;text-align:left">
                <th style="border:1px solid #ddd">Capability</th>
                <th style="border:1px solid #ddd">Active driver</th>
                <th style="border:1px solid #ddd">Tier</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($snapshot->capabilities as $c)
                <tr>
                    <td style="border:1px solid #ddd">{{ $c->capability->label() }}</td>
                    <td style="border:1px solid #ddd"><code>{{ $c->driver }}</code></td>
                    <td style="border:1px solid #ddd">{{ $c->tier->label() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h3>Optional enhanced services</h3>
    <table cellpadding="7" cellspacing="0" style="border-collapse:collapse;width:100%">
        <thead>
            <tr style="background:#f4f4f5;text-align:left">
                <th style="border:1px solid #ddd">Service</th>
                <th style="border:1px solid #ddd">Configured</th>
                <th style="border:1px solid #ddd">Reachable</th>
                <th style="border:1px solid #ddd">Latency</th>
                <th style="border:1px solid #ddd">Enabling it unlocks…</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($snapshot->services as $s)
                <tr>
                    <td style="border:1px solid #ddd">{{ $s->label }}</td>
                    <td style="border:1px solid #ddd">{{ $s->configured ? 'yes' : 'no' }}</td>
                    <td style="border:1px solid #ddd">{{ $s->configured ? ($s->reachable ? 'reachable' : 'unreachable') : '—' }}</td>
                    <td style="border:1px solid #ddd">{{ $s->latencyMs !== null ? $s->latencyMs.' ms' : '—' }}</td>
                    <td style="border:1px solid #ddd;color:#555">{{ $s->unlocks }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
