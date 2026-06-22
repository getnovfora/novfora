<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\AttachmentService;
use App\Forum\PostService;
use App\Models\Attachment;
use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Users;

/*
| Attachment HARDENING (ADR-0094, apex untrusted-file boundary). Complements AttachmentAuthorizationTest
| (the serve-gate IDOR/club/trashed cases) with the upload-processing + lifecycle guarantees added here:
| image re-encode (EXIF/polyglot strip) + dimension clamp, the decompression-bomb fence, the upload rate
| limit, association-on-publish (the correctness fix + ownership rule + per-post cap), and orphan pruning.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** A real on-disk PNG of $w×$h with $trailer appended after the image bytes (a polyglot when $trailer is set). */
function fakePng(int $w, int $h, string $trailer = ''): UploadedFile
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 10, 120, 200));
    ob_start();
    imagepng($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    $path = tempnam(sys_get_temp_dir(), 'att').'.png';
    file_put_contents($path, $bytes.$trailer);

    return new UploadedFile($path, 'photo.png', 'image/png', null, true);
}

it('re-encodes an uploaded image: clamps dimensions and strips a trailing polyglot payload', function () {
    Storage::fake('local');
    config(['novfora.attachments.max_image_dimension' => 200]);
    $member = Users::inGroups(['members']);

    // A 600×600 PNG with PHP appended after the image data (a classic polyglot).
    $attachment = app(AttachmentService::class)->store($member, fakePng(600, 600, '<?php system($_GET["c"]); ?>'));

    // Clamped to the configured max longest side…
    expect($attachment->width)->toBeLessThanOrEqual(200)
        ->and($attachment->height)->toBeLessThanOrEqual(200);

    // …and the stored bytes are the clean re-encode — the polyglot payload is gone.
    $stored = Storage::disk('local')->get($attachment->path);
    expect($stored)->not->toContain('<?php')->not->toContain('system(');
});

it('refuses a source image whose header dimensions exceed the decompression-bomb limit', function () {
    Storage::fake('local');
    config(['novfora.attachments.max_source_dimension' => 100]); // tiny limit so a small fixture trips it
    $member = Users::inGroups(['members']);

    $this->actingAs($member)
        ->postJson(route('attachments.store'), ['file' => fakePng(300, 300)])
        ->assertStatus(422);

    // Nothing is left behind on disk or in the table when an upload is refused mid-processing.
    expect(Attachment::count())->toBe(0);
});

it('refuses a square decompression bomb that slips the per-SIDE fence (total-pixel budget)', function () {
    Storage::fake('local');
    // Generous per-side limit (the 600px image passes it) but a tiny PIXEL budget that 600×600 exceeds — the
    // exact shape of the 11999×11999 bomb the per-side fence alone would miss (apex review MEDIUM, ADR-0094).
    config(['novfora.attachments.max_source_dimension' => 5000, 'novfora.attachments.max_source_pixels' => 100_000]);
    $member = Users::inGroups(['members']);

    $this->actingAs($member)
        ->postJson(route('attachments.store'), ['file' => fakePng(600, 600)]) // 360k px > 100k budget
        ->assertStatus(422);

    expect(Attachment::count())->toBe(0);
});

it('rate-limits the upload endpoint (untrusted-file boundary)', function () {
    $middleware = Route::getRoutes()->getByName('attachments.store')->gatherMiddleware();

    expect($middleware)->toContain('throttle:40,1');
});

it('associates the author’s referenced draft attachment on publish, so readers can fetch it', function () {
    Storage::fake('local');
    $author = Users::inGroups(['members', 'tl4']);
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);

    $attachment = app(AttachmentService::class)->store($author, fakePng(20, 20));
    expect($attachment->post_id)->toBeNull(); // orphan: uploader-only until published

    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'image', 'attrs' => ['src' => route('attachments.show', $attachment)]],
    ]];
    $topic = app(PostService::class)->createTopic($author, $forum, 'With a photo', 'tiptap_json', $doc);

    $attachment->refresh();
    expect($attachment->post_id)->toBe($topic->posts()->firstOrFail()->id);

    // Now a guest reading the public forum can fetch it (the forum.view path applies) — the correctness fix.
    $this->get(route('attachments.show', $attachment))->assertOk();
});

it('never associates another member’s orphan (no cross-user attachment hijack)', function () {
    Storage::fake('local');
    $author = Users::inGroups(['members', 'tl4']);
    $victim = Users::inGroups(['members', 'tl4']);
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);

    $victimsFile = app(AttachmentService::class)->store($victim, fakePng(20, 20));

    // The author references the VICTIM's attachment id in their own post.
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'image', 'attrs' => ['src' => route('attachments.show', $victimsFile)]],
    ]];
    app(PostService::class)->createTopic($author, $forum, 'Trying to steal', 'tiptap_json', $doc);

    // It stays an orphan owned by the victim — never bound to the author's post.
    expect($victimsFile->fresh()->post_id)->toBeNull();
});

it('enforces the per-post attachment count cap (excess stay orphans)', function () {
    Storage::fake('local');
    config(['novfora.attachments.max_per_post' => 2]);
    $author = Users::inGroups(['members', 'tl4']);
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);

    $files = collect(range(1, 3))->map(fn () => app(AttachmentService::class)->store($author, fakePng(20, 20)));
    $doc = ['type' => 'doc', 'content' => $files->map(fn ($a) => [
        'type' => 'image', 'attrs' => ['src' => route('attachments.show', $a)],
    ])->all()];

    $topic = app(PostService::class)->createTopic($author, $forum, 'Too many', 'tiptap_json', $doc);
    $postId = $topic->posts()->firstOrFail()->id;

    expect(Attachment::where('post_id', $postId)->count())->toBe(2)
        ->and(Attachment::whereNull('post_id')->count())->toBe(1);
});

it('prunes never-published orphans past the window but keeps recent + published ones', function () {
    Storage::fake('local');
    $author = Users::inGroups(['members', 'tl4']);
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);

    // An old orphan (abandoned draft), a fresh orphan, and a published attachment.
    $old = app(AttachmentService::class)->store($author, fakePng(20, 20));
    $old->forceFill(['created_at' => now()->subDays(2)])->save();
    $fresh = app(AttachmentService::class)->store($author, fakePng(20, 20));
    $published = app(AttachmentService::class)->store($author, fakePng(20, 20));
    app(PostService::class)->createTopic($author, $forum, 'Pub', 'tiptap_json',
        ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => route('attachments.show', $published)]]]]);

    $pruned = app(AttachmentService::class)->pruneOrphans(24);

    expect($pruned)->toBe(1)
        ->and(Attachment::find($old->id))->toBeNull()                 // old orphan gone (row + file)
        ->and(Storage::disk('local')->exists($old->path))->toBeFalse()
        ->and(Attachment::find($fresh->id))->not->toBeNull()          // recent orphan kept
        ->and($published->fresh()->post_id)->not->toBeNull();         // published attachment untouched
});
