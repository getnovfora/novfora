<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Seeders;

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Group;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Seeder;
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

        try {
            $users = $this->users();
            $posts = app(PostService::class);

            // ── Community ───────────────────────────────────────────────────────────────────────────
            $community = $this->category('community', 'Community', 0);
            $announce = $this->forum('announcements', 'Announcements', $community, 0, 'News and updates from the team.');
            $intro = $this->forum('introductions', 'Introductions', $community, 1, 'New here? Say hello.');

            $this->thread($posts, $announce, $users['leader'],
                'Welcome to the community 🎉',
                "We're glad you're here. This forum is running on **Hearth** — an open-source, self-hosted "
                ."community platform.\n\nTake a look around, introduce yourself, and start a discussion. "
                .'Staff post announcements here; everything else is fair game in the other forums.',
                [
                    [$users['regular'], 'Excited to be one of the first members. The editor feels great.'],
                    [$users['member'], 'Loving the clean, fast pages. Mobile works well too.'],
                ]);

            $this->thread($posts, $intro, $users['member'],
                'Hi, I’m new here',
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

            $this->thread($posts, $general, $users['regular'],
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
                        .'On a shared host, mail is sent by the cron line, so make sure that’s set up.'],
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
        } finally {
            config(['mail.default' => $mailWas]);
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
            'lurker' => $this->user('quinn', 'quinn@demo.hearth.test', 'tl0'),   // present, but doesn't post
            'basic' => $this->user('sam', 'sam@demo.hearth.test', 'tl1'),
            'member' => $this->user('riley', 'riley@demo.hearth.test', 'tl2'),
            'regular' => $this->user('jordan', 'jordan@demo.hearth.test', 'tl3'),
            'leader' => $this->user('avery', 'avery@demo.hearth.test', 'tl4'),
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
}
