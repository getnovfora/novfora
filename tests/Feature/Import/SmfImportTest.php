<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Import\Drivers\SmfDriver;
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
| The SMF 2.x importer (ADR-0034) against a FAKE legacy SMF DB (a second sqlite connection with smf_* tables).
| Promotes the SMF driver from "scaffold" to VERIFIED and pins the title-from-first-message join (SMF keeps no
| title on the topic row), child-before-parent boards, BBCode→markdown→html, 301 redirects, and idempotent
| re-run + resume. CLEAN-ROOM: only SMF's public schema is encoded — no SMF code/templates are used.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function legacySmf(): ConnectionInterface
{
    config(['database.connections.legacy_smf' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
    $conn = DB::connection('legacy_smf');
    $schema = Schema::connection('legacy_smf');

    foreach (['smf_members', 'smf_boards', 'smf_topics', 'smf_messages', 'smf_attachments'] as $t) {
        $schema->dropIfExists($t);
    }
    $schema->create('smf_members', function ($t) {
        $t->integer('id_member');
        $t->string('member_name');
        $t->string('email_address');
        $t->integer('date_registered');
    });
    $schema->create('smf_boards', function ($t) {
        $t->integer('id_board');
        $t->integer('id_parent');
        $t->string('name');
        $t->integer('board_order');
    });
    $schema->create('smf_topics', function ($t) {
        $t->integer('id_topic');
        $t->integer('id_board');
        $t->integer('id_first_msg');
        $t->integer('id_member_started');
    });
    $schema->create('smf_messages', function ($t) {
        $t->integer('id_msg');
        $t->integer('id_topic');
        $t->integer('id_member');
        $t->string('subject');
        $t->text('body');
        $t->integer('poster_time');
    });
    $schema->create('smf_attachments', function ($t) {
        $t->integer('id_attach');
        $t->integer('id_msg');
        $t->string('filename');
        $t->string('file_hash');
        $t->string('mime_type');
    });

    $conn->table('smf_members')->insert([
        ['id_member' => 1, 'member_name' => 'opal', 'email_address' => 'opal@old.test', 'date_registered' => 1500000000],
        ['id_member' => 2, 'member_name' => 'pete', 'email_address' => 'pete@old.test', 'date_registered' => 1500000001],
    ]);
    // Child board (order 1) before its parent category (order 2): the order-independent import must still nest it.
    $conn->table('smf_boards')->insert([
        ['id_board' => 2, 'id_parent' => 1, 'name' => 'Support', 'board_order' => 1],
        ['id_board' => 1, 'id_parent' => 0, 'name' => 'Community', 'board_order' => 2],
    ]);
    $conn->table('smf_topics')->insert([
        ['id_topic' => 1, 'id_board' => 2, 'id_first_msg' => 1, 'id_member_started' => 1],
    ]);
    $conn->table('smf_messages')->insert([
        ['id_msg' => 1, 'id_topic' => 1, 'id_member' => 1, 'subject' => 'SMF welcome', 'body' => 'Hello [i]world[/i] and [url=http://s.test]link[/url]', 'poster_time' => 1500000100],
        ['id_msg' => 2, 'id_topic' => 1, 'id_member' => 2, 'subject' => 'Re: SMF welcome', 'body' => 'a follow-up', 'poster_time' => 1500000200],
    ]);

    return $conn;
}

it('imports an SMF board: members, hierarchy, title-from-first-message, content, redirects', function () {
    $driver = new SmfDriver(legacySmf(), 'smf_');
    $runner = app(ImportRunner::class);

    expect($runner->preflight($driver)['counts'])->toBe(['users' => 2, 'forums' => 2, 'topics' => 1, 'posts' => 2]);

    $report = $runner->import($driver);

    expect(User::where('username', 'opal')->exists())->toBeTrue()
        ->and(User::where('username', 'pete')->exists())->toBeTrue();

    $community = Forum::where('title', 'Community')->firstOrFail();
    $support = Forum::where('title', 'Support')->firstOrFail();
    expect($community->parent_id)->toBeNull()
        ->and($support->parent_id)->toBe($community->id); // child-before-parent order handled

    $topic = Topic::firstOrFail();
    expect($topic->forum_id)->toBe($support->id)
        ->and($topic->title)->toBe('SMF welcome')                       // title resolved from the first message
        ->and($topic->user_id)->toBe(User::where('username', 'opal')->value('id'));

    $opening = Post::where('topic_id', $topic->id)->orderBy('id')->first();
    expect($opening->body_html_cache)->toContain('<em>world</em>')->toContain('http://s.test');

    expect(Redirect::where('from_path', '/index.php?topic=1')->value('to_path'))->toBe('/topics/'.$topic->id)
        ->and(Redirect::where('from_path', '/index.php?board=1')->value('to_path'))->toBe('/forums/'.$community->id);
    $this->get('/index.php?topic=1')->assertStatus(301)->assertRedirect('/topics/'.$topic->id);

    expect($report['topics']['complete'])->toBeTrue()->and($report['posts']['imported'])->toBe(2);
});

it('is idempotent on re-run and resumes newly-added SMF rows', function () {
    $conn = legacySmf();
    $driver = new SmfDriver($conn, 'smf_');
    $runner = app(ImportRunner::class);

    $runner->import($driver);
    [$users, $maps] = [User::count(), ImportMap::count()];

    $runner->import($driver);
    expect(User::count())->toBe($users)->and(ImportMap::count())->toBe($maps);

    $conn->table('smf_members')->insert(['id_member' => 9, 'member_name' => 'quinn', 'email_address' => 'quinn@old.test', 'date_registered' => 1500000300]);
    $conn->table('smf_messages')->insert(['id_msg' => 9, 'id_topic' => 1, 'id_member' => 9, 'subject' => 'Re', 'body' => 'late', 'poster_time' => 1500000400]);

    $runner->import($driver);
    expect(User::where('username', 'quinn')->exists())->toBeTrue()
        ->and(User::count())->toBe($users + 1)
        ->and(Post::count())->toBe(3);
});

it('imports SMF attachments and verifies their checksums', function () {
    Storage::fake('local');
    $conn = legacySmf();

    $dir = sys_get_temp_dir().'/novfora-smf-att-'.bin2hex(random_bytes(4));
    @mkdir($dir, 0777, true);
    $content = 'smf-attachment-'.str_repeat('z', 48);
    file_put_contents($dir.'/1_deadbeefhash', $content); // SMF 2.1 physical name: {id_attach}_{file_hash}
    $conn->table('smf_attachments')->insert([
        'id_attach' => 1, 'id_msg' => 1, 'filename' => 'diagram.png', 'file_hash' => 'deadbeefhash', 'mime_type' => 'image/png',
    ]);

    try {
        $report = app(ImportRunner::class)->import(new SmfDriver($conn, 'smf_', $dir));

        $attachment = Attachment::firstOrFail();
        expect($attachment->original_name)->toBe('diagram.png')
            ->and($attachment->checksum)->toBe(hash('sha256', $content))
            ->and(Storage::disk('local')->get($attachment->path))->toBe($content)
            ->and($report['attachments']['imported'])->toBe(1)
            ->and($report['attachments']['checksum_ok'])->toBeTrue()
            ->and($report['content']['ok'])->toBeTrue();
    } finally {
        @unlink($dir.'/1_deadbeefhash');
        @rmdir($dir);
    }
});
