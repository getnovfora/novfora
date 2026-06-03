{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Moderator control panel · '.config('app.name', 'Hearth')])

@section('content')
    <main style="max-width:48rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        <h1>Moderator control panel</h1>
        <p style="color:#777">The anti-spam &amp; moderation baseline (ADR-0007). Trust-level gating, content scanning, and rate limits run automatically; the rest lives here.</p>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:1rem;margin-top:1.5rem">
            <a href="{{ route('moderation.queue') }}" style="display:block;padding:1rem;border:1px solid #ececf1;border-radius:8px;text-decoration:none;color:inherit;background:#fff">
                <strong>Approval queue</strong>
                <div style="color:#777;font-size:.9rem;margin-top:.3rem">{{ $counts['pending_topics'] + $counts['pending_posts'] }} item(s) awaiting review</div>
            </a>
            <a href="{{ route('moderation.reports') }}" style="display:block;padding:1rem;border:1px solid #ececf1;border-radius:8px;text-decoration:none;color:inherit;background:#fff">
                <strong>Reports</strong>
                <div style="color:#777;font-size:.9rem;margin-top:.3rem">{{ $counts['open_reports'] }} open report(s)</div>
            </a>
            <a href="{{ route('moderation.recycle-bin') }}" style="display:block;padding:1rem;border:1px solid #ececf1;border-radius:8px;text-decoration:none;color:inherit;background:#fff">
                <strong>Recycle bin</strong>
                <div style="color:#777;font-size:.9rem;margin-top:.3rem">Restore soft-deleted content</div>
            </a>
        </div>
    </main>
@endsection
