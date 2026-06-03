{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'System · Backups'])

@section('content')
    <main style="max-width:980px;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        <nav style="color:#666;font-size:.9rem">Admin → System → Backups</nav>
        <h1>Backups</h1>
        <p style="color:#555">
            Create a backup (database + uploaded files) and download or restore it. Backups also run
            automatically from the cron schedule. To restore, download an archive and run
            <code>php artisan hearth:restore &lt;archive&gt;</code> on the host.
        </p>
        <livewire:admin.backups />
    </main>
@endsection
