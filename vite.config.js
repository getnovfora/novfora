import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// SPDX-License-Identifier: Apache-2.0
//
// No external-font fetching: the theme uses the system-ui stack only (the brief mandates no external
// fonts/CDNs — self-hosted privacy is a product value). Dropping the laravel-vite-plugin `bunny()` fonts
// plugin makes `npm run build` fully OFFLINE-DETERMINISTIC: nothing reaches fonts.bunny.net at build time,
// so the RH-5 assets-fresh guard can never flake on a CDN and the bundle is byte-reproducible anywhere.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
