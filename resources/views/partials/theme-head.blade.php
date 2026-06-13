{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Filesystem child-theme head-injection point. Empty by default. A filesystem child theme overrides this
     view (themes/<slug>/views/partials/theme-head.blade.php) to inject its own <style> into <head> — e.g. an
     AA-safe accent palette. It receives $nonce for the strict CSP. This is the filesystem-theme layer
     (ThemeManager view overrides), distinct from the DB style editor (ADR-0029 / StyleThemeManager) whose
     compiled <style> is emitted just above; this include comes last so a child theme wins on equal
     specificity, mirroring how the active DB style theme wins over the Appearance accent. --}}
