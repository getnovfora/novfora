{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Cross-page bulk-selection state (P2-M4). A single Alpine store survives wire:navigate within a page
     session (it is registered on alpine:init, which fires once per full load, not per SPA navigation), so a
     moderator can keep a selection while paging through a thread or forum. Registered once; the guard makes a
     second include on the same page a no-op. nonce-aware for the strict CSP (phase-1.5 F-M3). --}}
@php($bulkNonce = \Illuminate\Support\Facades\Vite::cspNonce())
<script @if ($bulkNonce) nonce="{{ $bulkNonce }}" @endif>
    document.addEventListener('alpine:init', () => {
        if (window.Alpine.store('bulkSelect')) {
            return;
        }
        window.Alpine.store('bulkSelect', {
            active: false,
            ids: [],
            toggleMode() {
                this.active = ! this.active;
                if (! this.active) {
                    this.ids = [];
                }
            },
            toggle(id) {
                id = Number(id);
                const i = this.ids.indexOf(id);
                i < 0 ? this.ids.push(id) : this.ids.splice(i, 1);
            },
            has(id) {
                return this.ids.indexOf(Number(id)) !== -1;
            },
            clear() {
                this.ids = [];
                this.active = false;
            },
        });
    });
</script>
