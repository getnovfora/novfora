<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/*
| NF-08 (NOV-151) — a REAL concurrency check of the reply write path, for MySQL/MariaDB/PostgreSQL. Pest runs
| each test in one connection inside a transaction, so it can't race two writers; this script can. It forks
| WORKERS processes (each with its own DB connection) that reply simultaneously to two topics in one forum
| through PostService, then verifies, against the live tables:
|
|   • positions within each topic are unique and contiguous (no duplicates from a racy MAX()+1);
|   • topic reply_count / first / last pointers and forum post_count / topic_count / last pointers equal the
|     authoritative recompute (no lost or stale counter updates);
|   • every author's users.post_count equals their real post count.
|
| Usage (against a disposable, migrated + seeded database — it writes test data):
|   php artisan migrate:fresh --seed --force && php scripts/stress/concurrent-replies.php [workers] [replies] [topics: 1|2] [deleters]
| Exit code 0 = PASS, 1 = FAIL (details printed). Requires the pcntl extension. Never point it at production.
*/

use App\Forum\PostService;
use App\Forum\TopicCounters;
use App\Models\Forum;
use App\Models\Group;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! function_exists('pcntl_fork')) {
    fwrite(STDERR, "pcntl extension required\n");
    exit(1);
}
$driver = DB::connection()->getDriverName();
if ($driver === 'sqlite') {
    fwrite(STDERR, "refusing to run on sqlite: it serialises writers, so it can't exercise the race\n");
    exit(1);
}

$workers = max(2, (int) ($argv[1] ?? 8));
$replies = max(1, (int) ($argv[2] ?? 25));
$topicCount = min(2, max(1, (int) ($argv[3] ?? 2))); // 1 = every worker on ONE topic (the sharpest position race)
// Deleters race the repliers: each repeatedly soft-deletes the newest reply in a transaction — a POST lock, then
// (via the deleted observer) the TOPIC lock: the SpamCleaner/moderation order. Repliers take the topic first, so a
// reply path that also locked a post row would deadlock here (PR #54 review).
$deleters = max(0, (int) ($argv[4] ?? 0));

// ── Fixture: one forum, two topics, one member per worker ─────────────────────────────────────────────
$groups = Group::whereIn('slug', ['members', 'tl1'])->pluck('id', 'slug');
$mkUser = function () use ($groups): User {
    $u = User::factory()->create(); // dev dependency (fakerphp) — this is a test harness, never shipped
    $u->groups()->attach($groups['members'], ['is_primary' => true]);
    $u->groups()->attach($groups['tl1'], ['is_primary' => false]);

    return $u->refresh();
};
$forum = Forum::create(['slug' => 'stress-'.Str::lower(Str::random(6)), 'title' => 'Stress', 'type' => 'forum']);
$topics = [
    app(PostService::class)->createTopic($mkUser(), $forum, 'Stress topic A', 'markdown', ['source' => 'opening A']),
    app(PostService::class)->createTopic($mkUser(), $forum, 'Stress topic B', 'markdown', ['source' => 'opening B']),
];
$userIds = [];
for ($i = 0; $i < $workers; $i++) {
    $userIds[] = $mkUser()->getKey();
}
$topicIds = array_map(fn (Topic $t) => (int) $t->getKey(), $topics);
printf("driver=%s workers=%d replies/worker=%d topics=%s deleters=%d\n", $driver, $workers, $replies, implode(',', $topicIds), $deleters);

// ── Race: fork the workers; each opens its OWN connection and replies as fast as it can ───────────────
DB::disconnect(); // never share the parent's socket with a child
$start = microtime(true);
$pids = [];
foreach ($userIds as $n => $userId) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        DB::purge();
        $user = User::findOrFail($userId);
        $failures = 0;
        for ($r = 0; $r < $replies; $r++) {
            $topic = Topic::findOrFail($topicIds[($n + $r) % $topicCount]);
            try {
                app(PostService::class)->reply($user, $topic, 'markdown', ['source' => "worker {$n} reply {$r} ".Str::random(8)]);
            } catch (Throwable $e) {
                $failures++;
                fwrite(STDERR, "worker {$n}: ".get_class($e).': '.$e->getMessage()."\n");
            }
        }
        exit($failures === 0 ? 0 : 2);
    }
    $pids[] = $pid;
}
for ($d = 0; $d < $deleters; $d++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        DB::purge();
        $failures = 0;
        for ($r = 0; $r < $replies; $r++) {
            try {
                DB::transaction(function () use ($topicIds, $d, $r, $topicCount): void {
                    // Like the real deleters (SpamCleaner, moderation): find the post with a plain read, then lock it
                    // by PRIMARY KEY — a point lock, no gap — and delete; the observer then takes the topic (post →
                    // topic, the deleter lock order). A locking RANGE read here would hold next-key gap locks on
                    // (topic_id, position) that block every reply's INSERT on MySQL — a harness-only deadlock no
                    // production path has. Skipping an already-trashed post keeps two deleters from double-deleting
                    // the same one (a separate double-decrement race, NOV-169).
                    $id = Post::where('topic_id', $topicIds[($d + $r) % $topicCount])->where('position', '>', 1)
                        ->orderByDesc('position')->value('id');
                    $post = $id === null ? null : Post::whereKey($id)->lockForUpdate()->first();
                    $post?->delete();
                });
            } catch (Throwable $e) {
                $failures++;
                fwrite(STDERR, "deleter {$d}: ".get_class($e).': '.$e->getMessage()."\n");
            }
            usleep(2000);
        }
        exit($failures === 0 ? 0 : 2);
    }
    $pids[] = $pid;
}
$workerFailures = 0;
foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
    if (pcntl_wexitstatus($status) !== 0) {
        $workerFailures++;
    }
}
DB::purge();
printf("raced %d replies in %.2fs\n", $workers * $replies, microtime(true) - $start);

// ── Verify ────────────────────────────────────────────────────────────────────────────────────────────
$problems = [];
if ($workerFailures > 0) {
    $problems[] = "{$workerFailures} worker(s) hit errors writing replies (see stderr)";
}
$expectedReplies = $workers * $replies;
$actualReplies = Post::withTrashed()->whereIn('topic_id', $topicIds)->whereIn('user_id', $userIds)->count();
if ($actualReplies !== $expectedReplies) {
    $problems[] = "expected {$expectedReplies} replies, found {$actualReplies}";
}

foreach ($topicIds as $topicId) {
    $positions = Post::withTrashed()->where('topic_id', $topicId)->orderBy('position')->pluck('position')->map(fn ($p) => (int) $p)->all();
    if (count($positions) !== count(array_unique($positions))) {
        $problems[] = "topic {$topicId}: duplicate positions";
    }
    if ($positions !== range(1, count($positions))) {
        $problems[] = "topic {$topicId}: positions are not contiguous 1..".count($positions);
    }
}

$snapshot = fn () => [
    'topics' => DB::table('topics')->whereIn('id', $topicIds)->orderBy('id')
        ->get(['id', 'reply_count', 'first_post_id', 'last_post_id', 'last_post_user_id', 'last_posted_at'])->map(fn ($r) => (array) $r)->all(),
    'forum' => (array) DB::table('forums')->where('id', $forum->getKey())
        ->first(['post_count', 'topic_count', 'last_post_id', 'last_topic_id', 'last_posted_at']),
];
$stored = $snapshot();
foreach ($topicIds as $topicId) {
    app(TopicCounters::class)->recomputeTopic($topicId);
}
app(TopicCounters::class)->recomputeForum((int) $forum->getKey());
$recomputed = $snapshot();
if ($stored != $recomputed) {
    $problems[] = 'stored aggregates differ from the authoritative recompute:'
        ."\n  stored:     ".json_encode($stored)."\n  recomputed: ".json_encode($recomputed);
}

foreach (array_merge($userIds, $topics[0]->user_id ? [(int) $topics[0]->user_id, (int) $topics[1]->user_id] : []) as $userId) {
    $stored = (int) DB::table('users')->where('id', $userId)->value('post_count');
    $real = Post::where('user_id', $userId)->count();
    if ($stored !== $real) {
        $problems[] = "user {$userId}: post_count {$stored} != real {$real}";
    }
}

if ($problems === []) {
    echo "PASS: positions unique + contiguous; topic, forum and author aggregates match the recompute\n";
    exit(0);
}
echo "FAIL:\n - ".implode("\n - ", $problems)."\n";
exit(1);
