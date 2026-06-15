<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Group;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seed a large, realistic "big board" fixture for load testing (Wave 8.3).
 *
 * Content is written through the real {@see PostService} so canonical→HTML/text, denormalised counters and
 * last-post pointers are correct — the load test then exercises true query shapes (deep pagination over big
 * forums, search over many posts), not hand-built rows. ADDITIVE and resumable: forums/users keyed by a
 * stable load-test slug/email are skipped if present, so re-running with larger counts grows the dataset.
 *
 * This produces the DATA half of the harness; the k6/artillery scripts in load-tests/ drive traffic at it.
 * It is deliberately NOT wired into any automatic path. No at-scale numbers are claimed — scale the counts
 * to your target and measure on YOUR hardware (see docs/architecture/load-testing.md).
 */
final class LoadTestSeedCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'novfora:loadtest:seed
                            {--force : run without the production confirmation prompt}
                            {--forums=5 : number of load-test forums}
                            {--topics=40 : topics per forum}
                            {--posts=8 : replies per topic (on top of the opening post)}
                            {--users=50 : distinct member authors}';

    protected $description = 'Seed a large forum/topic/post fixture for load testing (additive, real write path)';

    /** A small deterministic word bank — varied bodies give search something real to match (no RNG: index-keyed). */
    private const WORDS = [
        'forum', 'thread', 'reply', 'community', 'moderation', 'permission', 'search', 'notification',
        'theme', 'widget', 'import', 'digest', 'reputation', 'badge', 'profile', 'message', 'tag', 'poll',
        'baseline', 'enhanced', 'queue', 'cache', 'index', 'render', 'latency', 'throughput', 'scale',
    ];

    public function handle(PostService $posts): int
    {
        $forums = max(1, (int) $this->option('forums'));
        $topicsPer = max(1, (int) $this->option('topics'));
        $repliesPer = max(0, (int) $this->option('posts'));
        $userCount = max(1, (int) $this->option('users'));

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        // Side-effects fire inline but never hit the network: sync queue (listeners run), array mail (no SMTP).
        $queueWas = config('queue.default');
        $mailWas = config('mail.default');
        config(['queue.default' => 'sync', 'mail.default' => 'array']);

        try {
            $this->info("Seeding load-test fixture: {$forums} forums × {$topicsPer} topics × ".($repliesPer + 1).' posts…');

            $users = $this->users($userCount);
            $category = Forum::updateOrCreate(
                ['slug' => 'loadtest'],
                ['title' => 'Load Test', 'type' => 'category', 'position' => 99],
            );

            $createdTopics = 0;
            $createdPosts = 0;

            for ($f = 0; $f < $forums; $f++) {
                $forum = Forum::updateOrCreate(
                    ['slug' => "loadtest-forum-{$f}"],
                    ['title' => "Load Test Forum {$f}", 'type' => 'forum', 'parent_id' => $category->id, 'position' => $f],
                );

                $bar = $this->output->createProgressBar($topicsPer);
                $bar->setFormat(" forum {$f}: %current%/%max% topics [%bar%] %elapsed%");
                for ($t = 0; $t < $topicsPer; $t++) {
                    $author = $users[($f * $topicsPer + $t) % count($users)];
                    $topic = $posts->createTopic(
                        $author, $forum,
                        $this->title($f, $t),
                        'markdown',
                        ['source' => $this->body($f, $t, 0)],
                    );
                    $createdTopics++;
                    $createdPosts++;

                    for ($r = 0; $r < $repliesPer; $r++) {
                        $replier = $users[($f + $t + $r + 1) % count($users)];
                        $posts->reply($replier, $topic, 'markdown', ['source' => $this->body($f, $t, $r + 1)]);
                        $createdPosts++;
                    }
                    $bar->advance();
                }
                $bar->finish();
                $this->newLine();
            }

            $this->info("Done: +{$createdTopics} topics, +{$createdPosts} posts across {$forums} forums, {$userCount} authors.");
            $this->line('Drive traffic with the scripts in load-tests/ (see docs/architecture/load-testing.md).');

            return self::SUCCESS;
        } finally {
            config(['queue.default' => $queueWas, 'mail.default' => $mailWas]);
        }
    }

    /**
     * @return list<User>
     */
    private function users(int $count): array
    {
        $groups = Group::whereIn('slug', ['members', 'tl1'])->get();
        $sync = [];
        foreach ($groups as $group) {
            $sync[$group->id] = ['is_primary' => $group->slug === 'members'];
        }

        $users = [];
        for ($i = 0; $i < $count; $i++) {
            $user = User::updateOrCreate(
                ['email' => "loadtest{$i}@example.test"],
                [
                    'username' => "loadtester{$i}",
                    'name' => "Load Tester {$i}",
                    'display_name' => "Load Tester {$i}",
                    'password' => Hash::make(Str::password(24)),
                    'status' => 'active',
                    'trust_level' => 1,
                ],
            );
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->groups()->sync($sync);
            $users[] = $user->refresh();
        }

        return $users;
    }

    private function title(int $f, int $t): string
    {
        $a = self::WORDS[($f + $t) % count(self::WORDS)];
        $b = self::WORDS[($f * 3 + $t * 7 + 5) % count(self::WORDS)];

        return Str::title("{$a} {$b} discussion #{$f}-{$t}");
    }

    /** A few deterministic sentences whose word mix varies by position, so search/pagination see real spread. */
    private function body(int $f, int $t, int $n): string
    {
        $lines = [];
        for ($s = 0; $s < 4; $s++) {
            $words = [];
            for ($w = 0; $w < 12; $w++) {
                $words[] = self::WORDS[($f * 13 + $t * 17 + $n * 19 + $s * 23 + $w * 5) % count(self::WORDS)];
            }
            $lines[] = ucfirst(implode(' ', $words)).'.';
        }

        return implode("\n\n", $lines);
    }
}
