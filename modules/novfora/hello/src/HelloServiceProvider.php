<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Modules\Novfora\Hello;

use App\Events\PostCreated;
use App\Modules\Facades\Hook;
use App\Modules\SlotRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * The Hello example plugin's single registration entrypoint (ADR-0031). The core never references this class
 * by name — ModuleLoader registers it from the validated manifest when the module is enabled. It demonstrates
 * every seam of the module API:
 *
 *   1. a DOMAIN-EVENT listener  — records a greeting on PostCreated;
 *   2. a FILTER hook            — appends a benign marker to rendered post HTML (re-sanitised by core);
 *   3. a UI SLOT                — a sanitised footer widget;
 *   4. a ROUTE                  — a page the module provides.
 *
 * The module's permission key (novfora.hello.manage) and its hello_greetings table are registered/migrated by
 * the lifecycle manager on enable, not here.
 */
final class HelloServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(PostCreated::class, static function (PostCreated $event): void {
            if (Schema::hasTable('hello_greetings')) {
                DB::table('hello_greetings')->insert([
                    'post_id' => $event->post->getKey(),
                    'created_at' => now(),
                ]);
            }
        });

        Hook::addFilter('post.html', static fn (string $html): string => $html.'<span class="hello-greeting">&#128075;</span>');

        $this->app->make(SlotRegistry::class)->addSlot(
            'footer.widgets',
            static fn (array $context): string => '<span class="hello-widget">Hello from the example plugin.</span>',
        );

        Route::middleware('web')->get('/hello', static fn () => response('Hello from the example plugin.'))
            ->name('module.hello.index');
    }
}
