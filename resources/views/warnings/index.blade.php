{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Your warnings · '.config('app.name', 'Hearth')])

@section('content')
    <main style="max-width:40rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        <h1>Your warnings</h1>
        <p style="color:#777">Please read and acknowledge any active warning — posting is restored once all are acknowledged.</p>

        @forelse ($warnings as $warning)
            <div style="padding:.6rem 0;border-bottom:1px solid #f0f0f3">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <span><strong>{{ $warning->type?->label ?? 'Warning' }}</strong>
                        <span style="color:#999">· {{ $warning->points }} pts{{ $warning->expires_at ? ', expires '.$warning->expires_at->toFormattedDateString() : '' }}</span></span>
                    @if ($warning->acknowledged_at)
                        <span style="color:#1a7a1a;font-size:.85rem">Acknowledged</span>
                    @else
                        <form method="POST" action="{{ route('warnings.acknowledge', $warning->id) }}">@csrf
                            <button style="padding:.25rem .6rem;border:1px solid #2d2a6b;border-radius:6px;background:#fff;color:#2d2a6b;cursor:pointer;font-size:.8rem">Acknowledge</button></form>
                    @endif
                </div>
                @if ($warning->reason)
                    <p style="margin:.3rem 0 0;color:#555;font-size:.9rem">{{ $warning->reason }}</p>
                @endif
            </div>
        @empty
            <p style="color:#999">You have no warnings.</p>
        @endforelse
    </main>
@endsection
