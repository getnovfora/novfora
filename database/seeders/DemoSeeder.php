<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Seeders;

use App\Community\FollowService;
use App\Forum\PollService;
use App\Forum\PostService;
use App\Forum\ReactionService;
use App\Messaging\ConversationService;
use App\Models\Forum;
use App\Models\Group;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A believable starter community (M5, phase-1-plan §5): categories → forums → topics → posts, authored by
 * users across the trust levels, on the default theme. Chosen by the installer's "demo" option and runnable
 * any time via `php artisan db:seed --class=DemoSeeder`.
 *
 * IDEMPOTENT: a sentinel forum guards re-runs (a second run is a no-op), and users/forums are keyed by a
 * stable slug/email. Content is written through the real {@see PostService} so canonical → sanitized HTML +
 * text, denormalised counters, and last-post pointers are all correct — the demo exercises the true write
 * path, not hand-built rows.
 *
 * Phase-2 content (reactions, polls, PMs, follows, badges) is also written through the real service paths.
 * The queue is pinned to sync for the duration so reaction listeners (reputation, badges) fire inline —
 * the same technique as the mail-transport pin used for notifications.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Idempotency sentinel — if the demo is already present, do nothing.
        if (Forum::where('slug', 'announcements')->exists()) {
            return;
        }

        // Reply notifications fire while authoring; pin mail to the array transport so the seed never
        // attempts SMTP (it can run during install on the baseline tier, where mail isn't configured yet).
        $mailWas = config('mail.default');
        config(['mail.default' => 'array']);

        // Pin the queue to sync so the Reacted listeners (reputation awards, badge checks) run inline
        // rather than being pushed to a worker or dropped — deterministic reputation on the first seed run.
        $queueWas = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            $users = $this->users();
            $posts = app(PostService::class);

            // ── Community ───────────────────────────────────────────────────────────────────────────
            $community = $this->category('community', 'Community', 0);
            $announce = $this->forum('announcements', 'Announcements', $community, 0, 'News and updates from the team.');
            $intro = $this->forum('introductions', 'Introductions', $community, 1, 'New here? Say hello.');

            $welcomeTopic = $this->thread($posts, $announce, $users['leader'],
                'Welcome to the community 🎉',
                "We're glad you're here.  — an open-source, self-hosted "
                ."community platform.\n\nTake a look around, introduce yourself, and start a discussion. "
                .'Staff post announcements here; everything else is fair game in the other forums.',
                [
                    [$users['regular'], 'Excited to be one of the first members. The editor feels great.'],
                    [$users['member'], 'Loving the clean, fast pages. Mobile works well too.'],
                ]);

            $this->thread($posts, $intro, $users['member'],
                "Hi, I\u{2019}m new here",
                'Long-time lurker on other forums, first time on something this modern. Looking forward to '
                .'the discussions!',
                [
                    [$users['regular'], 'Welcome aboard! What brings you here?'],
                    [$users['basic'], 'Hello and welcome 👋'],
                ]);

            // ── Discussion ──────────────────────────────────────────────────────────────────────────
            $discussion = $this->category('discussion', 'Discussion', 1);
            $general = $this->forum('general-discussion', 'General Discussion', $discussion, 0, 'Talk about anything.');
            $help = $this->forum('help-support', 'Help & Support', $discussion, 1, 'Questions and answers.');

            $featureTopic = $this->thread($posts, $general, $users['regular'],
                'What features matter most in a forum?',
                "Curious what people value: search, real-time, theming, mobile UX, anti-spam? For me it's "
                .'**search and anti-spam** — everything else is polish.',
                [
                    [$users['leader'], "Anti-spam, easily. A forum drowning in spam dies fast.\n\n- Trust levels\n- A good blocklist\n- A sane moderation queue"],
                    [$users['member'], 'Mobile UX. So many forums are unusable on a phone.'],
                    [$users['basic'], 'Honestly? Not breaking on upgrades. Reversible migrations are underrated.'],
                ]);

            $this->thread($posts, $help, $users['basic'],
                'How do I enable email notifications?',
                'I want to get an email when someone replies to my topic. Where is that setting?',
                [
                    [$users['leader'], 'Head to **Settings → Notifications** and turn on email for replies and mentions. '
                        .'On a shared host, mail is sent by the cron line, so make sure that\'s set up.'],
                ]);

            // ── Off-topic ───────────────────────────────────────────────────────────────────────────
            $offtopic = $this->category('off-topic', 'Off-Topic', 2);
            $lounge = $this->forum('the-lounge', 'The Lounge', $offtopic, 0, 'Chat about whatever.');

            $this->thread($posts, $lounge, $users['member'],
                'What are you reading lately?',
                'Always looking for recommendations. Currently on a sci-fi kick.',
                [
                    [$users['regular'], 'Just finished a great book on distributed systems. Niche, but excellent.'],
                ]);

            // ── Poll ────────────────────────────────────────────────────────────────────────────────
            // A poll on the "What features matter most" topic — authored by the topic's TL3 owner.
            $this->seedPoll($featureTopic, $users);

            // ── Reactions ───────────────────────────────────────────────────────────────────────────
            // Several demo users react to each other's posts (never self-reacting). The queue is pinned
            // to sync above, so the Reacted listeners (reputation + badge checks) fire inline here.
            $this->seedReactions($welcomeTopic, $featureTopic, $users);

            // ── Follows ─────────────────────────────────────────────────────────────────────────────
            // TL1–TL4 users follow the most active user (leader/avery); a couple of cross-follows too.
            // TL0 (quinn/lurker) is excluded — follow.create is soft-gated NO at TL0.
            $this->seedFollows($users);

            // ── Private messages ─────────────────────────────────────────────────────────────────────
            // One multi-participant conversation demonstrating the real PM write path.
            $this->seedPms($users);

            // ── Badges ──────────────────────────────────────────────────────────────────────────────
            // Run the full badge sweep so starter badges (welcome, first-post, well-regarded) are visibly
            // awarded in the demo rather than waiting for the next cron tick.
            Artisan::call('novfora:badges:recompute');
        } finally {
            config(['mail.default' => $mailWas]);
            config(['queue.default' => $queueWas]);
        }
    }

    /**
     * Demo users across trust levels TL0–TL4. Content-only accounts: each gets a random, unguessable
     * password (the real login is the admin the installer created). Idempotent by email.
     *
     * @return array{lurker:User, basic:User, member:User, regular:User, leader:User}
     */
    private function users(): array
    {
        return [
            'lurker' => $this->user('quinn', 'quinn@demo.novfora.test', 'tl0'),   // present, but doesn't post
            'basic' => $this->user('sam', 'sam@demo.novfora.test', 'tl1'),
            'member' => $this->user('riley', 'riley@demo.novfora.test', 'tl2'),
            'regular' => $this->user('jordan', 'jordan@demo.novfora.test', 'tl3'),
            'leader' => $this->user('avery', 'avery@demo.novfora.test', 'tl4'),
        ];
    }

    private function user(string $username, string $email, string $trustSlug): User
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'username' => $username,
                'name' => Str::title($username),
                'display_name' => Str::title($username),
                'password' => Hash::make(Str::password(24)),
                'status' => 'active',
                'trust_level' => (int) Str::after($trustSlug, 'tl'),
            ],
        );

        // email_verified_at is guarded on the User model — set it explicitly.
        $user->forceFill(['email_verified_at' => now()])->save();

        $groups = Group::whereIn('slug', ['members', $trustSlug])->get();
        $sync = [];
        foreach ($groups as $group) {
            $sync[$group->id] = ['is_primary' => $group->slug === 'members'];
        }
        $user->groups()->sync($sync);

        return $user->refresh();
    }

    private function category(string $slug, string $title, int $position): Forum
    {
        return Forum::updateOrCreate(
            ['slug' => $slug],
            ['title' => $title, 'type' => 'category', 'position' => $position],
        );
    }

    private function forum(string $slug, string $title, Forum $parent, int $position, string $description): Forum
    {
        return Forum::updateOrCreate(
            ['slug' => $slug],
            ['title' => $title, 'type' => 'forum', 'parent_id' => $parent->id, 'position' => $position, 'settings' => ['description' => $description]],
        );
    }

    /**
     * Create a topic + opening post, then the replies, through the real write path.
     *
     * @param  list<array{0:User, 1:string}>  $replies  [author, markdown body]
     */
    private function thread(PostService $posts, Forum $forum, User $author, string $title, string $body, array $replies = []): Topic
    {
        $topic = $posts->createTopic($author, $forum, $title, 'markdown', ['source' => $body]);

        foreach ($replies as [$replyAuthor, $replyBody]) {
            $posts->reply($replyAuthor, $topic, 'markdown', ['source' => $replyBody]);
        }

        return $topic;
    }

    /**
     * Add a single-choice poll to the "What features matter most?" topic, then cast votes from
     * three distinct users to populate the tallies. Written through the real PollService so the
     * topics.poll_id seam is wired and the option vote_counts are recomputed authoritatively.
     *
     * @param  array{lurker:User, basic:User, member:User, regular:User, leader:User}  $users
     */
    private function seedPoll(Topic $topic, array $users): void
    {
        $poll = app(PollService::class)->createPoll(
            author: $users['regular'],
            topic: $topic,
            question: 'Which forum feature do you value most?',
            options: ['Search quality', 'Anti-spam tools', 'Mobile UX', 'Upgrade safety'],
        );

        $optionIds = $poll->options->pluck('id')->values();

        // Three different voters pick different options — populates a varied result set.
        app(PollService::class)->vote($users['leader'], $poll, [$optionIds[0]]);  // Search quality
        app(PollService::class)->vote($users['member'], $poll, [$optionIds[1]]);  // Anti-spam tools
        app(PollService::class)->vote($users['basic'], $poll, [$optionIds[2]]);   // Mobile UX
    }

    /**
     * React to posts across two topics, using four reaction types, without any self-reacts.
     * Because the queue is pinned to sync, the Reacted listener runs inline and banks reputation + triggers
     * badge evaluation immediately — no need for a separate recompute after.
     *
     * @param  array{lurker:User, basic:User, member:User, regular:User, leader:User}  $users
     */
    private function seedReactions(Topic $welcomeTopic, Topic $featureTopic, array $users): void
    {
        $reactions = app(ReactionService::class);

        // Grab the opening post of each topic plus the first few replies.
        $welcomePosts = Post::where('topic_id', $welcomeTopic->id)->orderBy('id')->take(3)->get();
        $featurePosts = Post::where('topic_id', $featureTopic->id)->orderBy('id')->take(4)->get();

        // Helper: react if author != reactor (never self-react).
        $react = static function (User $reactor, Post $post, string $type) use ($reactions): void {
            if ((int) $reactor->id !== (int) $post->user_id) {
                $reactions->toggle($reactor, $post, $type);
            }
        };

        // Welcome topic — opening post by leader/avery; reactions from the rest.
        if ($welcomePosts->isNotEmpty()) {
            $opening = $welcomePosts->first();
            $react($users['regular'], $opening, 'love');
            $react($users['member'], $opening, 'helpful');
            $react($users['basic'], $opening, 'like');
        }
        if ($welcomePosts->count() >= 2) {
            $react($users['leader'], $welcomePosts[1], 'insightful'); // leader → regular's reply
        }
        if ($welcomePosts->count() >= 3) {
            $react($users['regular'], $welcomePosts[2], 'like');      // regular → member's reply
        }

        // Feature-discussion topic — opening post by regular/jordan; reactions from others.
        if ($featurePosts->isNotEmpty()) {
            $react($users['leader'], $featurePosts->first(), 'insightful');
            $react($users['member'], $featurePosts->first(), 'helpful');
        }
        if ($featurePosts->count() >= 2) {
            $react($users['regular'], $featurePosts[1], 'love');      // regular → leader's reply
        }
        if ($featurePosts->count() >= 3) {
            $react($users['leader'], $featurePosts[2], 'like');       // leader → member's reply
        }
        if ($featurePosts->count() >= 4) {
            $react($users['member'], $featurePosts[3], 'insightful'); // member → basic's reply
        }
    }

    /**
     * Seed follow edges. TL0 (quinn/lurker) is excluded — follow.create is soft-gated NO at TL0.
     * Never self-follow (FollowService throws).
     *
     * @param  array{lurker:User, basic:User, member:User, regular:User, leader:User}  $users
     */
    private function seedFollows(array $users): void
    {
        $follows = app(FollowService::class);

        // Everyone (TL1–TL4) follows the most active member (leader/avery).
        $follows->follow($users['basic'], $users['leader']);
        $follows->follow($users['member'], $users['leader']);
        $follows->follow($users['regular'], $users['leader']);

        // A couple of cross-follows to demonstrate a bidirectional relationship.
        $follows->follow($users['leader'], $users['regular']);
        $follows->follow($users['member'], $users['regular']);

        // Even the lurker can BE followed (just cannot initiate follows).
        $follows->follow($users['leader'], $users['lurker']);
    }

    /**
     * Start one multi-participant conversation with a few replies through the real ConversationService.
     * All senders are TL1+, so pm.send is allowed; the rate limiter is per-user so spreading sends across
     * users keeps each safely within the per-minute cap.
     *
     * @param  array{lurker:User, basic:User, member:User, regular:User, leader:User}  $users
     */
    private function seedPms(array $users): void
    {
        $pms = app(ConversationService::class);

        // Leader starts a conversation with regular and member.
        $conversation = $pms->startConversation(
            sender: $users['leader'],
            recipientIds: [(int) $users['regular']->id, (int) $users['member']->id],
            subject: 'Building the community together',
            format: 'markdown',
            canonical: ['source' => 'Hey folks — wanted to connect outside the public threads. How are you finding the platform so far?'],
        );

        // Regular replies.
        $pms->reply(
            sender: $users['regular'],
            conversation: $conversation,
            format: 'markdown',
            canonical: ['source' => 'Really enjoying it! The editor is smooth and pages load fast. The mobile experience is noticeably better than anything I have used before.'],
        );

        // Member adds their take.
        $pms->reply(
            sender: $users['member'],
            conversation: $conversation,
            format: 'markdown',
            canonical: ['source' => 'Agreed — and I appreciate that nothing broke on the upgrade path. Looking forward to what comes next.'],
        );
    }
}
