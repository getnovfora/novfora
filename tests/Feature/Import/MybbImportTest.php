<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Import\Drivers\MybbDriver;
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
| The MyBB 1.8 importer (ADR-0034) against a FAKE legacy MyBB DB (a second sqlite connection with mybb_* tables).
| Promotes the MyBB driver from "scaffold" to VERIFIED: it pins the schema mapping (uid/fid/pid/tid/pid),
| hierarchy (forums deliberately stored child-before-parent to exercise the order-independent forum import),
| category vs forum (type 'c'), BBCode→markdown→html, 301 redirect maps, and idempotent re-run + resume.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function legacyMybb(): ConnectionInterface
{
    config(['database.connections.legacy_mybb' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
    $conn = DB::connection('legacy_mybb');
    $schema = Schema::connection('legacy_mybb');

    foreach (['mybb_users', 'mybb_forums', 'mybb_threads', 'mybb_posts', 'mybb_attachments'] as $t) {
        $schema->dropIfExists($t);
    }
    $schema->create('mybb_users', function ($t) {
        $t->integer('uid');
        $t->string('username');
        $t->string('email');
        $t->string('password');
        $t->integer('regdate');
    });
    $schema->create('mybb_forums', function ($t) {
        $t->integer('fid');
        $t->integer('pid');
        $t->string('name');
        $t->string('type');
        $t->integer('disporder');
    });
    $schema->create('mybb_threads', function ($t) {
        $t->integer('tid');
        $t->integer('fid');
        $t->string('subject');
        $t->integer('uid');
        $t->integer('dateline');
    });
    $schema->create('mybb_posts', function ($t) {
        $t->integer('pid');
        $t->integer('tid');
        $t->integer('uid');
        $t->string('subject');
        $t->text('message');
        $t->integer('dateline');
    });
    $schema->create('mybb_attachments', function ($t) {
        $t->integer('aid');
        $t->integer('pid');
        $t->integer('uid');
        $t->string('filename');
        $t->string('filetype');
        $t->string('attachname');
    });

    $conn->table('mybb_users')->insert([
        ['uid' => 1, 'username' => 'mary', 'email' => 'mary@old.test', 'password' => 'saltedmd5hash', 'regdate' => 1500000000],
        ['uid' => 2, 'username' => 'nate', 'email' => 'nate@old.test', 'password' => 'saltedmd5hash2', 'regdate' => 1500000001],
    ]);
    // Stored CHILD-first (disporder 1) then its PARENT category (disporder 2): the importer must still nest it.
    $conn->table('mybb_forums')->insert([
        ['fid' => 2, 'pid' => 1, 'name' => 'Off-topic', 'type' => 'f', 'disporder' => 1], // forum under the category
        ['fid' => 1, 'pid' => 0, 'name' => 'Lounge', 'type' => 'c', 'disporder' => 2],     // category (root)
    ]);
    $conn->table('mybb_threads')->insert([
        ['tid' => 1, 'fid' => 2, 'subject' => 'MyBB hello', 'uid' => 1, 'dateline' => 1500000100],
    ]);
    $conn->table('mybb_posts')->insert([
        ['pid' => 1, 'tid' => 1, 'uid' => 1, 'subject' => 'MyBB hello', 'message' => 'Hi [b]there[/b] see [url=http://m.test]this[/url]', 'dateline' => 1500000100],
        ['pid' => 2, 'tid' => 1, 'uid' => 2, 'subject' => 'Re', 'message' => 'second post', 'dateline' => 1500000200],
    ]);

    return $conn;
}

it('imports a MyBB board: users, child-before-parent hierarchy, content, redirects', function () {
    $driver = new MybbDriver(legacyMybb(), 'mybb_');
    $runner = app(ImportRunner::class);

    expect($runner->preflight($driver)['counts'])->toBe(['users' => 2, 'forums' => 2, 'topics' => 1, 'posts' => 2]);

    $report = $runner->import($driver);

    expect(User::where('username', 'mary')->exists())->toBeTrue()
        ->and(User::where('username', 'nate')->exists())->toBeTrue();

    $lounge = Forum::where('title', 'Lounge')->firstOrFail();
    $offtopic = Forum::where('title', 'Off-topic')->firstOrFail();
    expect($lounge->type)->toBe('category')
        ->and($lounge->parent_id)->toBeNull()
        ->and($offtopic->type)->toBe('forum')
        ->and($offtopic->parent_id)->toBe($lounge->id); // hierarchy preserved despite child-first order

    $topic = Topic::firstOrFail();
    expect($topic->forum_id)->toBe($offtopic->id)
        ->and($topic->title)->toBe('MyBB hello')
        ->and($topic->user_id)->toBe(User::where('username', 'mary')->value('id'));

    $opening = Post::where('topic_id', $topic->id)->orderBy('id')->first();
    expect($opening->body_html_cache)->toContain('<strong>there</strong>')->toContain('http://m.test');

    // MyBB stores a salted double-md5 hash Laravel can't verify → the imported user must reset (no usable hash).
    expect(User::where('username', 'mary')->value('password'))->not->toBe('saltedmd5hash');

    expect(Redirect::where('from_path', '/showthread.php?tid=1')->value('to_path'))->toBe('/topics/'.$topic->id)
        ->and(Redirect::where('from_path', '/forumdisplay.php?fid=1')->value('to_path'))->toBe('/forums/'.$lounge->id);
    $this->get('/showthread.php?tid=1')->assertStatus(301)->assertRedirect('/topics/'.$topic->id);

    expect($report['posts']['complete'])->toBeTrue()->and($report['users']['imported'])->toBe(2);
});

it('is idempotent on re-run and resumes newly-added MyBB rows', function () {
    $conn = legacyMybb();
    $driver = new MybbDriver($conn, 'mybb_');
    $runner = app(ImportRunner::class);

    $runner->import($driver);
    [$users, $maps] = [User::count(), ImportMap::count()];

    $runner->import($driver); // re-run: nothing new
    expect(User::count())->toBe($users)->and(ImportMap::count())->toBe($maps);

    $conn->table('mybb_users')->insert(['uid' => 9, 'username' => 'olive', 'email' => 'olive@old.test', 'password' => 'h', 'regdate' => 1500000300]);
    $conn->table('mybb_posts')->insert(['pid' => 9, 'tid' => 1, 'uid' => 9, 'subject' => 'late', 'message' => 'late reply', 'dateline' => 1500000400]);

    $runner->import($driver); // resume: only the new rows
    expect(User::where('username', 'olive')->exists())->toBeTrue()
        ->and(User::count())->toBe($users + 1)
        ->and(Post::count())->toBe(3);
});

it('imports MyBB attachments and verifies their checksums', function () {
    Storage::fake('local');
    $conn = legacyMybb();

    $dir = sys_get_temp_dir().'/novfora-mybb-att-'.bin2hex(random_bytes(4));
    @mkdir($dir, 0777, true);
    $content = 'mybb-attachment-'.str_repeat('y', 64);
    file_put_contents($dir.'/post_1_hash.attach', $content);
    $conn->table('mybb_attachments')->insert([
        'aid' => 1, 'pid' => 1, 'uid' => 1,
        'filename' => 'report.pdf', 'filetype' => 'application/pdf', 'attachname' => 'post_1_hash.attach',
    ]);

    try {
        $report = app(ImportRunner::class)->import(new MybbDriver($conn, 'mybb_', $dir));

        $attachment = Attachment::firstOrFail();
        expect($attachment->original_name)->toBe('report.pdf')
            ->and($attachment->checksum)->toBe(hash('sha256', $content))
            ->and(Storage::disk('local')->get($attachment->path))->toBe($content)
            ->and($report['attachments']['imported'])->toBe(1)
            ->and($report['attachments']['checksum_ok'])->toBeTrue()
            ->and($report['content']['ok'])->toBeTrue();
    } finally {
        @unlink($dir.'/post_1_hash.attach');
        @rmdir($dir);
    }
});
