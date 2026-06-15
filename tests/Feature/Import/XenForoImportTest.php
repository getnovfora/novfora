<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Import\Drivers\XenForoDriver;
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
| Wave 4 / ADR-0041 — XenForo importer, mirroring the phpBB bar: clean-room (the fixture is a fake XF schema in
| an in-memory SQLite DB), full fidelity (valid users only, node hierarchy, BBCode→md→html, 301 redirects),
| idempotency + resume, and attachment import with sha-256 checksum + content reconciliation.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function legacyXenforo(): ConnectionInterface
{
    config(['database.connections.legacy_xf' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
    $conn = DB::connection('legacy_xf');
    $schema = Schema::connection('legacy_xf');

    foreach (['xf_user', 'xf_node', 'xf_thread', 'xf_post', 'xf_attachment', 'xf_attachment_data'] as $t) {
        $schema->dropIfExists($t);
    }
    $schema->create('xf_user', function ($t) {
        $t->integer('user_id');
        $t->string('username');
        $t->string('email');
        $t->integer('register_date');
        $t->string('user_state');
    });
    $schema->create('xf_node', function ($t) {
        $t->integer('node_id');
        $t->integer('parent_node_id');
        $t->string('title');
        $t->string('node_type_id');
        $t->integer('display_order');
    });
    $schema->create('xf_thread', function ($t) {
        $t->integer('thread_id');
        $t->integer('node_id');
        $t->string('title');
        $t->integer('user_id');
        $t->integer('post_date');
        $t->string('discussion_state');
    });
    $schema->create('xf_post', function ($t) {
        $t->integer('post_id');
        $t->integer('thread_id');
        $t->integer('user_id');
        $t->text('message');
        $t->integer('post_date');
        $t->string('message_state');
    });
    $schema->create('xf_attachment', function ($t) {
        $t->integer('attachment_id');
        $t->integer('data_id');
        $t->string('content_type');
        $t->integer('content_id');
    });
    $schema->create('xf_attachment_data', function ($t) {
        $t->integer('data_id');
        $t->integer('user_id');
        $t->string('filename');
        $t->string('file_hash');
        $t->integer('file_size');
    });

    $conn->table('xf_user')->insert([
        ['user_id' => 1, 'username' => 'alice', 'email' => 'alice@xf.test', 'register_date' => 1600000000, 'user_state' => 'valid'],
        ['user_id' => 2, 'username' => 'bob', 'email' => 'bob@xf.test', 'register_date' => 1600000001, 'user_state' => 'valid'],
        ['user_id' => 3, 'username' => 'pending', 'email' => 'p@xf.test', 'register_date' => 1600000002, 'user_state' => 'email_confirm'], // excluded
    ]);
    $conn->table('xf_node')->insert([
        ['node_id' => 1, 'parent_node_id' => 0, 'title' => 'General', 'node_type_id' => 'Category', 'display_order' => 1],
        ['node_id' => 2, 'parent_node_id' => 1, 'title' => 'Chat', 'node_type_id' => 'Forum', 'display_order' => 2],
    ]);
    $conn->table('xf_thread')->insert([
        ['thread_id' => 1, 'node_id' => 2, 'title' => 'Hello XF', 'user_id' => 1, 'post_date' => 1600000100, 'discussion_state' => 'visible'],
        ['thread_id' => 2, 'node_id' => 2, 'title' => 'Removed', 'user_id' => 1, 'post_date' => 1600000101, 'discussion_state' => 'deleted'], // excluded
    ]);
    $conn->table('xf_post')->insert([
        ['post_id' => 1, 'thread_id' => 1, 'user_id' => 1, 'message' => 'Hello [b]bold[/b] world', 'post_date' => 1600000100, 'message_state' => 'visible'],
        ['post_id' => 2, 'thread_id' => 1, 'user_id' => 2, 'message' => 'A reply', 'post_date' => 1600000200, 'message_state' => 'visible'],
        ['post_id' => 3, 'thread_id' => 1, 'user_id' => 2, 'message' => 'gone', 'post_date' => 1600000201, 'message_state' => 'deleted'], // excluded
    ]);

    return $conn;
}

it('imports a XenForo board: valid users, node hierarchy, content, redirects', function () {
    $driver = new XenForoDriver(legacyXenforo(), 'xf_');
    $runner = app(ImportRunner::class);

    $plan = $runner->preflight($driver);
    expect($plan['counts'])->toBe(['users' => 2, 'forums' => 2, 'topics' => 1, 'posts' => 2]);

    $report = $runner->import($driver);

    expect(User::where('username', 'alice')->exists())->toBeTrue()
        ->and(User::where('username', 'pending')->exists())->toBeFalse(); // non-valid state excluded

    $general = Forum::where('title', 'General')->firstOrFail();
    $chat = Forum::where('title', 'Chat')->firstOrFail();
    expect($general->type)->toBe('category')
        ->and($chat->parent_id)->toBe($general->id); // node tree → forum hierarchy

    $topic = Topic::where('title', 'Hello XF')->firstOrFail();
    $opening = Post::where('topic_id', $topic->id)->orderBy('id')->first();
    expect($opening->body_html_cache)->toContain('<strong>bold</strong>'); // BBCode → markdown → HTML

    // The XF URL shapes get 301 redirect rows (trailing-slash, bare, and index.php forms)…
    expect(Redirect::where('from_path', '/threads/1/')->value('to_path'))->toBe('/topics/'.$topic->id)
        ->and(Redirect::where('from_path', '/threads/1')->value('to_path'))->toBe('/topics/'.$topic->id)
        ->and(Redirect::where('from_path', '/index.php?threads/1/')->value('to_path'))->toBe('/topics/'.$topic->id);
    // …and the controller serves the redirect (the bare form round-trips cleanly through the test client; the
    // trailing-slash + index.php forms are served identically for real browser requests in production).
    $this->get('/threads/1')->assertStatus(301)->assertRedirect('/topics/'.$topic->id);

    expect($report['posts']['complete'])->toBeTrue()
        ->and($report['content']['ok'])->toBeTrue();
});

it('is idempotent on re-run and resumes newly-added rows', function () {
    $conn = legacyXenforo();
    $driver = new XenForoDriver($conn, 'xf_');
    $runner = app(ImportRunner::class);

    $runner->import($driver);
    $users = User::count();
    $maps = ImportMap::count();

    $runner->import($driver); // re-run = no-op
    expect(User::count())->toBe($users)->and(ImportMap::count())->toBe($maps);

    $conn->table('xf_user')->insert([['user_id' => 9, 'username' => 'carol', 'email' => 'carol@xf.test', 'register_date' => 1600009000, 'user_state' => 'valid']]);
    $conn->table('xf_post')->insert([['post_id' => 20, 'thread_id' => 1, 'user_id' => 9, 'message' => 'late reply', 'post_date' => 1600009100, 'message_state' => 'visible']]);

    $runner->import($driver); // resume
    expect(User::where('username', 'carol')->exists())->toBeTrue()
        ->and(User::count())->toBe($users + 1)
        ->and(Post::count())->toBe(3);
});

it('imports XenForo attachments and verifies checksums + post content', function () {
    Storage::fake('local');
    $conn = legacyXenforo();

    $dir = sys_get_temp_dir().'/novfora-xf-att-'.bin2hex(random_bytes(4));
    $dataId = 5;
    $hash = 'deadbeefcafe';
    $group = intdiv($dataId, 1000); // XF2 shard
    @mkdir($dir.'/'.$group, 0777, true);
    $content = "\x89PNG\r\n".str_repeat('xenforo-image-bytes', 16);
    file_put_contents($dir.'/'.$group.'/'.$dataId.'-'.$hash.'.data', $content);

    $conn->table('xf_attachment_data')->insert([['data_id' => $dataId, 'user_id' => 1, 'filename' => 'pic.png', 'file_hash' => $hash, 'file_size' => strlen($content)]]);
    $conn->table('xf_attachment')->insert([['attachment_id' => 1, 'data_id' => $dataId, 'content_type' => 'post', 'content_id' => 1]]);

    try {
        $driver = new XenForoDriver($conn, 'xf_', $dir);
        $report = app(ImportRunner::class)->import($driver);

        $attachment = Attachment::firstOrFail();
        expect($attachment->checksum)->toBe(hash('sha256', $content))
            ->and($attachment->mime)->toBe('image/png')
            ->and(Storage::disk('local')->get($attachment->path))->toBe($content);

        expect($report['attachments']['checksum_ok'])->toBeTrue()
            ->and($report['attachments']['verified'])->toBe(1)
            ->and($report['content']['ok'])->toBeTrue();
    } finally {
        @unlink($dir.'/'.$group.'/'.$dataId.'-'.$hash.'.data');
        @rmdir($dir.'/'.$group);
        @rmdir($dir);
    }
});
