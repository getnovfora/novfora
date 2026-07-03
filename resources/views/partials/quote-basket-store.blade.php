{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Quote-basket state (U1 multi-quote). A page-session Alpine store collecting the post ids a member picked
     with "+Quote"; the floating bar navigates to ?quote=<csv>#reply-composer, which the composer resolves
     server-side (approved-in-topic only, de-duplicated, capped). Registered once on alpine:init (survives
     wire:navigate within the page session); a second include is a no-op. nonce-aware for the strict CSP. --}}
@php($qbNonce = \Illuminate\Support\Facades\Vite::cspNonce())
<script @if ($qbNonce) nonce="{{ $qbNonce }}" @endif>
    document.addEventListener('alpine:init', () => {
        if (window.Alpine.store('quoteBasket')) {
            return;
        }
        window.Alpine.store('quoteBasket', {
            ids: [],
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
            },
        });
    });
</script>
