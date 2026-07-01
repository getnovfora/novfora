<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Embeds\EmbedManager;
use App\Embeds\WidgetData;
use App\Models\EmbedSite;
use App\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The public embed surface (U7, ADR-0103): server-rendered iframe/SSI widgets + the JSON the web
 * components consume. STATELESS by design — these routes run outside the `web` group (no session, no
 * cookies, GET-only), so a framed embed carries no authority to ride or steal. Every response owns its
 * security headers: the HTML variant grants `frame-ancestors` to exactly the registered origin; the JSON
 * variant grants CORS to exactly the registered origin (no `*`, no credentials). Content is fenced to the
 * GUEST principal inside WidgetData; every miss — feature off, bad key, unknown widget, invisible forum —
 * is the same 404 (no oracle).
 */
class EmbedController extends Controller
{
    public function __construct(
        private readonly EmbedManager $manager,
        private readonly WidgetData $data,
    ) {}

    public function widget(Request $request, string $widget): Response
    {
        $site = $this->resolveSite($request, $widget);
        $payload = $this->payload($request, $widget);

        $theme = (string) $request->query('theme', 'auto');
        $theme = in_array($theme, ['light', 'dark', 'auto'], true) ? $theme : 'auto';

        $html = view('embed.widget', [
            'widget' => $widget,
            'data' => $payload,
            'theme' => $theme,
            'siteName' => (string) config('app.name', 'NovFora'),
        ])->render();

        return response($html)
            ->withHeaders($this->sharedHeaders())
            // frame-ancestors is the whole point: exactly the registered origin (plus self for the ACP
            // preview). Everything else stays locked — the widget ships zero scripts.
            ->header('Content-Security-Policy',
                "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; "
                ."form-action 'none'; frame-ancestors 'self' {$site->origin}");
    }

    public function data(Request $request, string $widget): JsonResponse
    {
        $site = $this->resolveSite($request, $widget);
        $payload = $this->payload($request, $widget);

        $response = response()->json([
            'widget' => $widget,
            'version' => 1,
            'data' => $payload,
        ]);

        foreach ($this->sharedHeaders() as $name => $value) {
            $response->header($name, $value);
        }

        // Vary on Origin UNCONDITIONALLY: intermediaries may cache this response (Cache-Control public),
        // and the CORS grant below differs per requesting origin.
        $response->header('Vary', 'Origin');

        // CORS: only the registered origin, only when the browser actually sent it. GET with no custom
        // headers never preflights, so no OPTIONS route is needed. No credentials flag — ever.
        if ($request->headers->get('Origin') === $site->origin) {
            $response->header('Access-Control-Allow-Origin', $site->origin);
            $response->header('Access-Control-Allow-Methods', 'GET');
        }

        return $response;
    }

    /** Feature switch → widget exists → site key resolves + enabled → widget allowed. Any miss = 404. */
    private function resolveSite(Request $request, string $widget): EmbedSite
    {
        abort_unless(app(Settings::class)->bool('embeds.enabled'), 404);
        abort_unless(in_array($widget, EmbedManager::WIDGETS, true), 404);

        $site = $this->manager->resolve($request->query('site'));
        abort_unless($site instanceof EmbedSite && $site->allowsWidget($widget), 404);

        return $site;
    }

    /** @return array<string,mixed> */
    private function payload(Request $request, string $widget): array
    {
        if ($widget === 'stats') {
            return $this->data->stats();
        }

        $forumParam = $request->query('forum');
        $forumId = null;
        if ($forumParam !== null) {
            abort_unless(is_string($forumParam) && ctype_digit($forumParam) && strlen($forumParam) <= 12, 404);
            $forumId = (int) $forumParam;
        }

        $limitParam = $request->query('limit');
        $limit = is_string($limitParam) && ctype_digit($limitParam) ? (int) $limitParam : 0;

        $payload = $this->data->topics($forumId, $limit);
        abort_if($payload === null, 404);

        return $payload;
    }

    /** @return array<string,string> */
    private function sharedHeaders(): array
    {
        return [
            // Public, viewer-independent content: let browsers/CDNs absorb repeats (the fragment cache
            // absorbs the rest). Matches the WidgetData TTL.
            'Cache-Control' => 'public, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];
    }
}
