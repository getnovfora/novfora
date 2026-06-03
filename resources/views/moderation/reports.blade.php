{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Reports · '.config('app.name', 'Hearth')])

@section('content')
    <main style="max-width:48rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        <h1>Reports</h1>
        <p style="color:#777">Open reports from the community, awaiting a moderator.</p>

        @forelse ($reports as $report)
            <div style="padding:.6rem 0;border-bottom:1px solid #f0f0f3">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <span>{{ class_basename($report->reportable_type) }} #{{ $report->reportable_id }}
                        <span style="color:#999">reported by {{ $report->reporter?->username ?? 'system' }}</span></span>
                    <form method="POST" action="{{ route('reports.resolve', $report->id) }}">@csrf
                        <button name="action" value="resolved" style="padding:.25rem .6rem;border:1px solid #7cc47c;border-radius:6px;background:#fff;color:#1a7a1a;cursor:pointer;font-size:.8rem">Resolve</button>
                        <button name="action" value="dismissed" style="padding:.25rem .6rem;border:1px solid #bbb;border-radius:6px;background:#fff;color:#555;cursor:pointer;font-size:.8rem">Dismiss</button>
                    </form>
                </div>
                @if ($report->reason)
                    <p style="margin:.3rem 0 0;color:#555;font-size:.9rem">{{ $report->reason }}</p>
                @endif
            </div>
        @empty
            <p style="color:#999">No open reports. 🎉</p>
        @endforelse

        <div style="margin-top:1rem">{{ $reports->links() }}</div>
    </main>
@endsection
