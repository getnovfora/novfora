{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Language switcher (Wave 8.1). Open to guests and members; POSTs the chosen locale, which the controller
     validates against the allowlist before storing it in the session (and the profile when signed in).
     Auto-submits on change via Alpine — same enhancement pattern as the rest of the chrome. --}}
@php($locales = \App\Support\Locales::all())
@if (count($locales) > 1)
    <form method="POST" action="{{ route('locale.update') }}" class="inline-flex items-center gap-2">
        @csrf
        <label for="locale-switch" class="sr-only">{{ __('common.choose_language') }}</label>
        <x-ui.icon name="globe" class="h-4 w-4 text-ink-subtle" aria-hidden="true" />
        <select id="locale-switch" name="locale" x-on:change="$el.form.submit()" dusk="locale-switch"
                class="min-h-9 rounded-md border border-line bg-surface-raised px-2 text-xs text-ink focus:border-accent">
            @foreach ($locales as $code => $meta)
                <option value="{{ $code }}" @selected(app()->getLocale() === $code)>{{ $meta['native'] }}</option>
            @endforeach
        </select>
    </form>
@endif
