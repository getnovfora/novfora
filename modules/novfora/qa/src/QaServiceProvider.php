<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Modules\Novfora\Qa;

use App\Events\PostCreated;
use App\Models\Post;
use App\Models\User;
use App\Modules\Facades\Hook;
use App\Modules\SlotRegistry;
use App\Permissions\Scope;
use App\Settings\SettingDefinition;
use App\Settings\Settings;
use App\Settings\SettingsRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * First-party Q&A dogfood plugin (ADR-0031 dogfood). The core never names this class — ModuleLoader registers
 * it from the validated manifest on enable. It exercises EVERY module seam through the public contract only,
 * with zero core edits:
 *
 *   • a plugin SETTING            — registered via SettingsRegistry::register (the D3 contract-gap fix);
 *   • a domain-EVENT listener     — PostCreated invalidates the topic's accepted-answer cache;
 *   • a post.html FILTER          — turns an [answer]…[/answer] author construct into a callout (re-sanitised);
 *   • a UI SLOT                   — topic.post.aside renders the accepted-answer badge / a "mark" affordance;
 *   • a PERMISSION                — novfora.qa.accept gates the mark action (resolved by the core engine);
 *   • ROUTES                      — a CSRF-guarded confirm page + accept action;
 *   • a plugin-owned MIGRATION    — qa_accepted_answers (run on enable, rolled back on remove).
 */
final class QaServiceProvider extends ServiceProvider
{
    private const SETTING_CALLOUT = 'qa.callout_enabled';

    public function boot(): void
    {
        // SETTINGS seam: register a plugin-owned, typed setting so the ACP/Settings service can manage it.
        SettingsRegistry::register(new SettingDefinition(
            self::SETTING_CALLOUT, 'bool', default: true, group: 'modules', label: 'Q&A — render [answer] callouts',
        ));

        // EVENT seam: a new post in a topic may change what's "current", so drop the cached accepted answer.
        Event::listen(PostCreated::class, static function (PostCreated $event): void {
            Cache::forget(self::cacheKey((int) $event->post->topic_id));
        });

        // FILTER seam: an [answer]…[/answer] content callout the plugin ships (re-sanitised by the core call
        // site, so it can never inject script). Gated by the plugin's own setting.
        Hook::addFilter('post.html', static function (string $html): string {
            if (! app(Settings::class)->bool(self::SETTING_CALLOUT)) {
                return $html;
            }

            return preg_replace('#\[answer\](.*?)\[/answer\]#is', '<span class="qa-callout">$1</span>', $html) ?? $html;
        });

        // SLOT seam: the per-post aside (the topic.post.aside outlet added in module API 1.1). Read-only +
        // sanitised — the interactive "mark" affordance is a sanitised link to the plugin's own CSRF route.
        $this->app->make(SlotRegistry::class)->addSlot('topic.post.aside', static function (array $context): string {
            $post = $context['post'] ?? null;
            if (! $post instanceof Post) {
                return '';
            }
            $topicId = (int) $post->topic_id;
            $acceptedId = Cache::remember(self::cacheKey($topicId), 300, static fn (): int => (int) (
                DB::table('qa_accepted_answers')->where('topic_id', $topicId)->value('post_id') ?? 0
            ));

            if ($acceptedId === (int) $post->getKey()) {
                return '<p class="qa-accepted"><strong>&#10003; Accepted answer</strong></p>';
            }
            if (self::canAccept()) {
                // Path-based url() (not route() by name) so a runtime-registered module route resolves even
                // under route:cache / before the name lookup is rebuilt.
                $url = url('/qa/confirm/'.$topicId.'/'.(int) $post->getKey());

                return '<p><a class="qa-mark" href="'.e($url).'">Mark as answer</a></p>';
            }

            return '';
        });

        // ROUTE seam: a confirm page (GET) + the accept action (POST), both permission-gated; the POST is CSRF-
        // guarded by the web middleware group.
        Route::middleware('web')->group(static function (): void {
            Route::get('/qa/confirm/{topic}/{post}', static function (int $topic, int $post) {
                abort_unless(self::canAccept(), 403);

                return response(
                    '<!doctype html><meta charset="utf-8"><title>Mark as answer</title>'
                    .'<form method="POST" action="'.e(url('/qa/accept')).'">'.csrf_field()
                    .'<input type="hidden" name="topic_id" value="'.$topic.'">'
                    .'<input type="hidden" name="post_id" value="'.$post.'">'
                    .'<button type="submit">Confirm — mark as accepted answer</button></form>'
                );
            })->name('module.qa.confirm');

            Route::post('/qa/accept', static function (Request $request) {
                abort_unless(self::canAccept(), 403);
                $topicId = $request->integer('topic_id');
                $postId = $request->integer('post_id');
                $post = Post::find($postId);
                abort_if(! $post instanceof Post || (int) $post->topic_id !== $topicId, 404);

                DB::table('qa_accepted_answers')->updateOrInsert(
                    ['topic_id' => $topicId],
                    ['post_id' => $postId, 'accepted_by' => auth()->id(), 'accepted_at' => now()],
                );
                Cache::forget(self::cacheKey($topicId));

                return back();
            })->name('module.qa.accept');
        });
    }

    private static function canAccept(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canDo('novfora.qa.accept', Scope::global());
    }

    private static function cacheKey(int $topicId): string
    {
        return 'qa.accepted.topic.'.$topicId;
    }
}
