<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forum\AttachmentService;
use App\Models\Attachment;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /** Editor upload endpoint (drag-drop / paste / picker all POST here). Gated on attachment.create. */
    public function store(Request $request, AttachmentService $service): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('attachment.create', Scope::global()), 403);

        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:png,jpg,jpeg,gif,webp,pdf,txt'],
        ]);

        $attachment = $service->store($user, $request->file('file'));

        return response()->json([
            'id' => $attachment->id,
            'url' => route('attachments.show', $attachment),
        ]);
    }

    /** Stream an attachment from off the web root. */
    public function show(Request $request, Attachment $attachment): StreamedResponse
    {
        // Object-level authorization (security §4 IDOR). The {attachment} key is a public, enumerable
        // auto-increment id, so each request must prove it may read THIS file:
        //   • an orphan (just-uploaded, not yet attached to a post) is visible only to its uploader;
        //   • an attached file is gated exactly like the post it lives in — the viewer must hold
        //     `forum.view` for that thread (mirrors TopicController@show), with anonymous resolving as
        //     the Guests group. Previously attached files were served unconditionally, leaking every
        //     attachment in private/staff-only forums to anyone walking the id space.
        if ($attachment->post_id === null) {
            abort_unless($request->user()?->getKey() === $attachment->user_id, 403);
        } else {
            $viewer = $request->user() instanceof User ? $request->user() : User::guest();
            $post = Post::withTrashed()->find($attachment->post_id);
            $topic = $post ? Topic::withTrashed()->find($post->topic_id) : null;
            $topicForum = $topic instanceof Topic ? $topic->forum : null;
            // P5.1 — mirror TopicController's trashed gate: once a post/topic is soft-deleted (moderated to the
            // recycle bin) its attachment must not stay readable to ordinary forum.view holders, only to the
            // uploader and to a moderator who can review the recycled content.
            $isTrashed = ($topic instanceof Topic && $topic->trashed()) || ($post instanceof Post && $post->trashed());
            $canModerate = $request->user() instanceof User && $topic instanceof Topic
                && $request->user()->canDo('topic.moderate', $topic->permissionScope());
            $canView = $topic instanceof Topic
                && $viewer->canDo('forum.view', $topic->permissionScope())
                // M1.5: an attachment in a club forum is gated by club content visibility.
                && $topicForum instanceof Forum && $topicForum->clubContentVisibleTo($request->user())
                && (! $isTrashed || $canModerate);
            // The uploader keeps access to their own file (covers their own still-pending/recycled post).
            abort_unless($canView || $request->user()?->getKey() === $attachment->user_id, 403);
        }

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        // nosniff so a stored file is always handled as its declared type, never sniffed into active
        // content (defence in depth alongside the upload MIME allowlist).
        return $disk->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
