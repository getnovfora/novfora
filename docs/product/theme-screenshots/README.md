<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# Default theme — screenshot gate

Captured by the Dusk harness (`tests/Browser/ThemeScreenshotTest.php`) — the four core pages in **light +
dark** at **mobile (360px)** and **desktop (1280px)**. Regenerate with
`docker compose -f docker/dusk/compose.yml run --rm dusk` (PASS 2 writes these) or the CI **dusk** job
(uploaded as the `dusk-screenshots` artifact).

> **Font note:** these are rendered by headless Chromium in a minimal Debian image, so `system-ui` falls back
> to the container's default face (it looks slightly monospaced). On a real OS the theme renders in the native
> UI font (Segoe UI / San Francisco / Roboto). Judge layout, spacing, colour, and contrast here — not the font.

## Forum index
| | Light | Dark |
|---|---|---|
| Desktop | ![](theme-forum-index-light-desktop.png) | ![](theme-forum-index-dark-desktop.png) |
| Mobile (360) | ![](theme-forum-index-light-mobile.png) | ![](theme-forum-index-dark-mobile.png) |

## Topic view
| | Light | Dark |
|---|---|---|
| Desktop | ![](theme-topic-light-desktop.png) | ![](theme-topic-dark-desktop.png) |
| Mobile (360) | ![](theme-topic-light-mobile.png) | ![](theme-topic-dark-mobile.png) |

## Auth (sign in)
| | Light | Dark |
|---|---|---|
| Desktop | ![](theme-auth-login-light-desktop.png) | ![](theme-auth-login-dark-desktop.png) |
| Mobile (360) | ![](theme-auth-login-light-mobile.png) | ![](theme-auth-login-dark-mobile.png) |

## Settings — appearance
| | Light | Dark |
|---|---|---|
| Desktop | ![](theme-settings-appearance-light-desktop.png) | ![](theme-settings-appearance-dark-desktop.png) |
| Mobile (360) | ![](theme-settings-appearance-light-mobile.png) | ![](theme-settings-appearance-dark-mobile.png) |
