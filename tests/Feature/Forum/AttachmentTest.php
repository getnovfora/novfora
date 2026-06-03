<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\AttachmentService;
use App\Models\Attachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('lets a member upload an attachment off the web root with a checksum', function () {
    Storage::fake('local');
    $member = Users::inGroups(['members']);
    $file = UploadedFile::fake()->create('photo.png', 40, 'image/png');

    $this->actingAs($member)->postJson(route('attachments.store'), ['file' => $file])
        ->assertOk()
        ->assertJsonStructure(['id', 'url']);

    $attachment = Attachment::firstOrFail();
    expect($attachment->checksum)->not->toBeNull();
    expect($attachment->disk)->toBe('local');
    expect($attachment->path)->toStartWith('attachments/');
    Storage::disk('local')->assertExists($attachment->path);
});

it('forbids uploading without the attachment.create permission', function () {
    Storage::fake('local');
    $stranger = Users::inGroups([]); // no granted group
    $file = UploadedFile::fake()->create('photo.png', 40, 'image/png');

    $this->actingAs($stranger)->postJson(route('attachments.store'), ['file' => $file])->assertForbidden();
});

it('rejects an upload with no file (the size/type allowlist runs)', function () {
    $member = Users::inGroups(['members']);

    $this->actingAs($member)->postJson(route('attachments.store'), [])->assertStatus(422);
});

it('serves an orphan attachment only to its uploader', function () {
    Storage::fake('local');
    $member = Users::inGroups(['members']);
    $attachment = app(AttachmentService::class)->store($member, UploadedFile::fake()->create('photo.png', 40, 'image/png'));

    $this->actingAs(Users::inGroups(['members']))->get(route('attachments.show', $attachment))->assertForbidden();
    $this->actingAs($member)->get(route('attachments.show', $attachment))->assertOk();
});

it('returns mention suggestions for the editor', function () {
    $member = Users::inGroups(['members'], ['username' => 'alice']);

    $this->actingAs($member)->getJson(route('mentions', ['q' => 'al']))
        ->assertOk()
        ->assertJsonFragment(['username' => 'alice']);
});
