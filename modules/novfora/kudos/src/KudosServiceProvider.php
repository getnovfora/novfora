<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Modules\Novfora\Kudos;

use App\Events\PostCreated;
use App\Models\Post;
use App\Models\User;
use App\Modules\Facades\Hook;
use App\Modules\SlotRegistry;
use App\Permissions\Scope;
use App\Settings\SettingDefinition;
use App\Settings\Settings;
use App\Settings\SettingsRegistry;
use App\Theme\WidgetRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * First-party Kudos dogfood plugin (ADR-0031/0032 dogfood). Built purely via the public contract; exercises a
 * domain-event listener, a post.html filter, a UI slot, a module-registered layout WIDGET, a plugin migration,
 * a plugin setting, a permission, and a CSRF-guarded route — zero core edits.
 */
final class KudosServiceProvider extends ServiceProvider
{
    private const SETTING_GLYPH = 'kudos.glyph';

    private const TOTAL_CACHE = 'novfora:kudos:total';

    public function boot(): void
    {
        // SETTINGS seam: a plugin-owned, typed setting (the [kudos] glyph), managed by the core Settings service.
        SettingsRegistry::register(new SettingDefinition(
            self::SETTING_GLYPH, 'string', default: '👍', group: 'modules', label: 'Kudos — glyph for the [kudos] shortcode',
        ));

        // EVENT seam: keep the cached community total honest (defensive cache hygiene on new content).
        Event::listen(PostCreated::class, static fn (PostCreated $event) => Cache::forget(self::TOTAL_CACHE));

        // FILTER seam: a [kudos] content shortcode → the configured glyph (re-sanitised by the core call site).
        Hook::addFilter('post.html', static function (string $html): string {
            return str_replace('[kudos]', e((string) app(Settings::class)->string(self::SETTING_GLYPH)), $html);
        });

        // SLOT seam: a footer line with the community kudos total.
        $this->app->make(SlotRegistry::class)->addSlot('footer.widgets', static fn (array $context): string => '<span class="kudos-total">'.e(__('Kudos given: :n', ['n' => self::total()])).'</span>');

        // WIDGET seam (B2): a placeable layout widget — a module contributes one like a built-in.
        $this->app->make(WidgetRegistry::class)->register(new KudosWidget);

        // ROUTE seam: give kudos (one per user per post), permission-gated + CSRF-guarded.
        Route::middleware('web')->post('/kudos/give', static function (Request $request) {
            $user = auth()->user();
            abort_unless($user instanceof User && $user->canDo('novfora.kudos.give', Scope::global()), 403);
            $postId = $request->integer('post_id');
            abort_unless(Post::whereKey($postId)->exists(), 404);

            DB::table('kudos')->insertOrIgnore([
                'post_id' => $postId, 'user_id' => $user->getKey(), 'created_at' => now(),
            ]);
            Cache::forget(self::TOTAL_CACHE);

            return back();
        })->name('module.kudos.give');
    }

    /** The cached community-wide kudos total (off the footer/widget hot path). */
    public static function total(): int
    {
        return (int) Cache::remember(self::TOTAL_CACHE, 300, static fn (): int => (int) DB::table('kudos')->count());
    }
}
