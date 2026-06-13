<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Import\BbcodeConverter;
use App\Import\Drivers\PhpbbDriver;
use App\Import\ImportRunner;
use App\Models\Attachment;
use App\Models\Forum;
use App\Models\ImportMap;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
| The phpBB importer (ADR-0034) against a FAKE legacy phpBB DB (a second sqlite connection with phpbb_* tables).
| Pins: bots excluded, forum hierarchy + author/forum mapping, BBCode→markdown, 301 redirects (served by the
| fallback), idempotent re-run, and the resume of newly-added rows.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function legacyPhpbb(): ConnectionInterface
{
    config(['database.connections.legacy_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
    $conn = DB::connection('legacy_test');
    $schema = Schema::connection('legacy_test');

    foreach (['phpbb_users', 'phpbb_forums', 'phpbb_topics', 'phpbb_posts', 'phpbb_attachments'] as $t) {
        $schema->dropIfExists($t);
    }
    $schema->create('phpbb_users', function ($t) {
        $t->integer('user_id');
        $t->string('username');
        $t->string('user_email');
        $t->string('user_password');
        $t->integer('user_regdate');
        $t->integer('user_type');
    });
    $schema->create('phpbb_forums', function ($t) {
        $t->integer('forum_id');
        $t->integer('parent_id');
        $t->string('forum_name');
        $t->integer('forum_type');
        $t->integer('left_id');
    });
    $schema->create('phpbb_topics', function ($t) {
        $t->integer('topic_id');
        $t->integer('forum_id');
        $t->string('topic_title');
        $t->integer('topic_poster');
        $t->integer('topic_time');
    });
    $schema->create('phpbb_posts', function ($t) {
        $t->integer('post_id');
        $t->integer('topic_id');
        $t->integer('poster_id');
        $t->string('post_subject');
        $t->text('post_text');
        $t->integer('post_time');
        $t->string('bbcode_uid');
    });
    $schema->create('phpbb_attachments', function ($t) {
        $t->integer('attach_id');
        $t->integer('post_msg_id');
        $t->integer('poster_id');
        $t->string('real_filename');
        $t->string('physical_filename');
        $t->string('mimetype');
    });

    $conn->table('phpbb_users')->insert([
        ['user_id' => 1, 'username' => 'alice', 'user_email' => 'alice@old.test', 'user_password' => '$2y$10$abcdefghijklmnopqrstuv', 'user_regdate' => 1500000000, 'user_type' => 0],
        ['user_id' => 2, 'username' => 'bob', 'user_email' => 'bob@old.test', 'user_password' => 'legacyhash', 'user_regdate' => 1500000001, 'user_type' => 3],
        ['user_id' => 3, 'username' => 'spambot', 'user_email' => 'bot@old.test', 'user_password' => 'x', 'user_regdate' => 1500000002, 'user_type' => 2], // bot — excluded
    ]);
    $conn->table('phpbb_forums')->insert([
        ['forum_id' => 1, 'parent_id' => 0, 'forum_name' => 'General', 'forum_type' => 0, 'left_id' => 1],   // category
        ['forum_id' => 2, 'parent_id' => 1, 'forum_name' => 'Chat', 'forum_type' => 1, 'left_id' => 2],      // forum under General
    ]);
    $conn->table('phpbb_topics')->insert([
        ['topic_id' => 1, 'forum_id' => 2, 'topic_title' => 'Hello world', 'topic_poster' => 1, 'topic_time' => 1500000100],
    ]);
    $conn->table('phpbb_posts')->insert([
        ['post_id' => 1, 'topic_id' => 1, 'poster_id' => 1, 'post_subject' => 'Hello world', 'post_text' => 'First [b:abc]bold[/b:abc] and [url:abc=http://x.test]link[/url:abc]', 'post_time' => 1500000100, 'bbcode_uid' => 'abc'],
        ['post_id' => 2, 'topic_id' => 1, 'poster_id' => 2, 'post_subject' => 'Re', 'post_text' => 'a reply', 'post_time' => 1500000200, 'bbcode_uid' => ''],
    ]);

    return $conn;
}

it('converts BBCode to markdown (clean-room)', function () {
    $c = new BbcodeConverter;
    expect($c->toMarkdown('[b:u]hi[/b:u]', 'u'))->toBe('**hi**')
        ->and($c->toMarkdown('[url=http://x.test]link[/url]'))->toBe('[link](http://x.test)')
        ->and($c->toMarkdown('[quote]said[/quote]'))->toContain('> said')
        ->and($c->toMarkdown('[unknowntag]plain[/unknowntag]'))->toBe('plain');
});

it('imports a phpBB board: users (no bots), hierarchy, content, redirects', function () {
    $driver = new PhpbbDriver(legacyPhpbb(), 'phpbb_');
    $runner = app(ImportRunner::class);

    $plan = $runner->preflight($driver);
    expect($plan['counts'])->toBe(['users' => 2, 'forums' => 2, 'topics' => 1, 'posts' => 2]); // bot excluded from users

    $report = $runner->import($driver);

    expect(User::where('username', 'alice')->exists())->toBeTrue()
        ->and(User::where('username', 'bob')->exists())->toBeTrue()
        ->and(User::where('username', 'spambot')->exists())->toBeFalse(); // bot not imported

    $general = Forum::where('title', 'General')->firstOrFail();
    $chat = Forum::where('title', 'Chat')->firstOrFail();
    expect($general->type)->toBe('category')
        ->and($general->parent_id)->toBeNull()
        ->and($chat->parent_id)->toBe($general->id);          // hierarchy preserved

    $topic = Topic::firstOrFail();
    expect($topic->forum_id)->toBe($chat->id)
        ->and($topic->user_id)->toBe(User::where('username', 'alice')->value('id')); // author mapped

    $opening = Post::where('topic_id', $topic->id)->orderBy('id')->first();
    expect($opening->body_html_cache)->toContain('<strong>bold</strong>')->toContain('http://x.test'); // BBCode→md→html

    // 301 redirect emitted + served by the fallback.
    expect(Redirect::where('from_path', '/viewtopic.php?t=1')->value('to_path'))->toBe('/topics/'.$topic->id);
    $this->get('/viewtopic.php?t=1')->assertStatus(301)->assertRedirect('/topics/'.$topic->id);

    expect($report['posts']['complete'])->toBeTrue()->and($report['users']['imported'])->toBe(2);
});

it('is idempotent on re-run and resumes newly-added rows', function () {
    $conn = legacyPhpbb();
    $driver = new PhpbbDriver($conn, 'phpbb_');
    $runner = app(ImportRunner::class);

    $runner->import($driver);
    $usersAfterFirst = User::count();
    $mapsAfterFirst = ImportMap::count();

    // Re-run: nothing new is created.
    $runner->import($driver);
    expect(User::count())->toBe($usersAfterFirst)->and(ImportMap::count())->toBe($mapsAfterFirst);

    // Add a new legacy user + post, re-run: only the new rows are imported (resume).
    $conn->table('phpbb_users')->insert(['user_id' => 9, 'username' => 'carol', 'user_email' => 'carol@old.test', 'user_password' => 'h', 'user_regdate' => 1500000300, 'user_type' => 0]);
    $conn->table('phpbb_posts')->insert(['post_id' => 9, 'topic_id' => 1, 'poster_id' => 9, 'post_subject' => 'late', 'post_text' => 'late reply', 'post_time' => 1500000400, 'bbcode_uid' => '']);

    $runner->import($driver);
    expect(User::where('username', 'carol')->exists())->toBeTrue()
        ->and(User::count())->toBe($usersAfterFirst + 1)
        ->and(Post::count())->toBe(3);
});

it('imports phpBB attachments and verifies their checksums + post content', function () {
    Storage::fake('local');
    $conn = legacyPhpbb();

    $dir = sys_get_temp_dir().'/novfora-phpbb-att-'.bin2hex(random_bytes(4));
    @mkdir($dir, 0777, true);
    $content = "\x89PNG\r\n".str_repeat('phpbb-image-bytes', 16);
    file_put_contents($dir.'/9f8e7d.png', $content);
    $conn->table('phpbb_attachments')->insert([
        'attach_id' => 1, 'post_msg_id' => 1, 'poster_id' => 1,
        'real_filename' => 'holiday.png', 'physical_filename' => '9f8e7d.png', 'mimetype' => 'image/png',
    ]);

    try {
        $driver = new PhpbbDriver($conn, 'phpbb_', $dir);
        $report = app(ImportRunner::class)->import($driver);

        $attachment = Attachment::firstOrFail();
        expect($attachment->post_id)->not->toBeNull()
            ->and($attachment->original_name)->toBe('holiday.png')
            ->and($attachment->checksum)->toBe(hash('sha256', $content))
            ->and(Storage::disk('local')->get($attachment->path))->toBe($content); // bytes survived intact

        // The verify pass re-hashes the stored file + reconciles post bodies — content, not just counts.
        expect($report['attachments']['imported'])->toBe(1)
            ->and($report['attachments']['complete'])->toBeTrue()
            ->and($report['attachments']['checksum_ok'])->toBeTrue()
            ->and($report['attachments']['verified'])->toBe(1)
            ->and($report['content']['ok'])->toBeTrue();
    } finally {
        @unlink($dir.'/9f8e7d.png');
        @rmdir($dir);
    }
});
