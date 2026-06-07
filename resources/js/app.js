// SPDX-License-Identifier: Apache-2.0
import './editor/island'; // registers the Alpine `hearthEditor` island (it dynamic-imports TipTap)

// ── Appearance helpers (default-theme PART 2) ─────────────────────────────────────────────────────────
// The inline boot snippet in <head> applies the stored preference BEFORE paint (no flash). These helpers
// handle interactive changes (the header colour-mode toggle, the footer density switch, the settings form)
// and keep localStorage — and, for signed-in users, the server — in sync. Everything degrades without JS:
// signed-in users get their server-rendered <html> attributes; guests get auto/comfortable.
(function () {
    const root = document.documentElement;
    const MODES = ['auto', 'light', 'dark'];

    function persist(payload) {
        // Only signed-in users have server-side storage; guests live in localStorage only.
        if (!document.querySelector('meta[name="hearth-auth"]')) return;
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        fetch('/settings/appearance', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            body: JSON.stringify(payload),
        }).catch(() => {});
    }

    function applyColorMode(mode) {
        if (!MODES.includes(mode)) mode = 'auto';
        try { localStorage.setItem('hearth-color-mode', mode); } catch (e) { /* private mode */ }
        if (mode === 'light' || mode === 'dark') root.setAttribute('data-theme', mode);
        else root.removeAttribute('data-theme');
        root.setAttribute('data-color-mode', mode);
        window.dispatchEvent(new CustomEvent('hearth:color-mode', { detail: mode }));
    }

    function applyDensity(density) {
        if (density !== 'compact') density = 'comfortable';
        try { localStorage.setItem('hearth-density', density); } catch (e) { /* private mode */ }
        root.setAttribute('data-density', density);
        window.dispatchEvent(new CustomEvent('hearth:density', { detail: density }));
    }

    window.Hearth = window.Hearth || {};
    window.Hearth.colorMode = () => root.getAttribute('data-color-mode') || 'auto';
    window.Hearth.setColorMode = (mode) => { applyColorMode(mode); persist({ color_mode: window.Hearth.colorMode() }); };
    window.Hearth.cycleColorMode = () => {
        const next = MODES[(MODES.indexOf(window.Hearth.colorMode()) + 1) % MODES.length];
        window.Hearth.setColorMode(next);
    };
    window.Hearth.density = () => root.getAttribute('data-density') || 'comfortable';
    window.Hearth.setDensity = (d) => { applyDensity(d); persist({ density: window.Hearth.density() }); };
})();
