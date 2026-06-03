<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\AttachmentService;
use App\Forum\PostService;
use App\Models\AclEntry;
use App\Models\Attachment;
use App\Models\Forum;
use App\Models\Group;
use App\Models\User;
use App\Permissions\PermissionValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Object-level authorization on the attachment stream (security §4 IDOR). The {attachment} route key is a
| public, enumerable auto-increment id, so streaming an ATTACHED file must honour the same forum.view gate
| as the post it belongs to — otherwise anyone could walk the id space and pull files out of private forums.
| Orphan (unattached) files stay uploader-only. Regression for the HIGH finding in docs/SECURITY-REVIEW.md.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** Attach an uploaded file to a fresh post in $forum (authored by $author); return the Attachment. */
function attachInForum(Forum $forum, User $author): Attachment
{
    $topic = app(PostService::class)->createTopic($author, $forum, 'With a file', 'tiptap_json', Content::doc('see attached'));
    $post = $topic->posts()->firstOrFail();
    $attachment = app(AttachmentService::class)->store($author, UploadedFile::fake()->create('secret.png', 20, 'image/png'));
    $attachment->forceFill(['post_id' => $post->id])->save();

    return $attachment;
}

/** Deny forum.view for the given groups at this forum (a NEVER, the private-forum pattern). */
function denyForumView(Forum $forum, array $slugs): void
{
    foreach ($slugs as $slug) {
        AclEntry::create([
            'permission_key' => 'forum.view',
            'holder_type' => 'group',
            'holder_id' => Group::where('slug', $slug)->value('id'),
            'scope_type' => 'forum',
            'scope_id' => $forum->id,
            'value' => PermissionValue::Never->value,
        ]);
    }
}

it('serves an attached file to anyone who can read its (public) forum — M2 behaviour preserved', function () {
    Storage::fake('local');
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $attachment = attachInForum($forum, Users::inGroups(['members', 'tl4']));

    $this->get(route('attachments.show', $attachment))->assertOk(); // guest, public forum
});

it('blocks an attached file in a forum the requester cannot view (the IDOR fix)', function () {
    Storage::fake('local');
    $forum = Forum::create(['slug' => 'staff', 'title' => 'Staff only', 'type' => 'forum']);
    $author = Users::inGroups(['members', 'tl4']);
    $attachment = attachInForum($forum, $author);

    denyForumView($forum, ['guests', 'members']);

    // Anonymous id-enumeration is refused…
    $this->get(route('attachments.show', $attachment))->assertForbidden();
    // …as is a logged-in member who cannot read the forum…
    $this->actingAs(Users::inGroups(['members']))->get(route('attachments.show', $attachment))->assertForbidden();
    // …but the uploader keeps access to their own file.
    $this->actingAs($author)->get(route('attachments.show', $attachment))->assertOk();
});

it('still serves an orphan attachment only to its uploader (unchanged)', function () {
    Storage::fake('local');
    $member = Users::inGroups(['members']);
    $orphan = app(AttachmentService::class)->store($member, UploadedFile::fake()->create('o.png', 10, 'image/png'));

    $this->get(route('attachments.show', $orphan))->assertForbidden();                 // anonymous
    $this->actingAs(Users::inGroups(['members']))->get(route('attachments.show', $orphan))->assertForbidden();
    $this->actingAs($member)->get(route('attachments.show', $orphan))->assertOk();
});
