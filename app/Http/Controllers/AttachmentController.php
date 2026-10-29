<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forum\AttachmentRejected;
use App\Forum\AttachmentService;
use App\Forum\PostVisibility;
use App\Models\Attachment;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /** Editor upload endpoint (drag-drop / paste / picker all POST here). Gated on attachment.create + rate
     *  limited at the route. Caps + the extension allowlist come from config; the finfo MIME sniff Laravel's
     *  `mimes` rule performs is the authoritative type gate. AttachmentRejected (a bad image caught while
     *  hardening) maps to a 422 — a rejected upload never 500s. */
    public function store(Request $request, AttachmentService $service): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('attachment.create', Scope::global()), 403);

        $maxKb = (int) max(1, intdiv((int) config('novfora.attachments.max_bytes', 5_242_880), 1024));
        $exts = implode(',', (array) config('novfora.attachments.allowed_extensions', ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'txt']));

        $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKb, 'mimes:'.$exts],
        ]);

        try {
            $attachment = $service->store($user, $request->file('file'));
        } catch (AttachmentRejected $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return response()->json([
            'id' => $attachment->id,
            'url' => route('attachments.show', $attachment),
            'name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'is_image' => $attachment->width !== null,
            'size' => (int) $attachment->size,
        ]);
    }

    /** Stream an attachment from off the web root. */
    public function show(Request $request, Attachment $attachment, PostVisibility $visibility): StreamedResponse
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
            // NF-02 (NOV-145): an attached file is never more public than its parent post. PostVisibility
            // mirrors TopicController@show — club visibility, thread-scope forum.view, the recycle bin, AND the
            // moderation fences: a pending topic/post (held by anti-spam or the approval queue) is readable only
            // by its author and topic.moderate holders, so its files are too. Previously only forum.view and the
            // trashed state were checked, so a held post's attachments leaked to every forum.view holder.
            $user = $request->user() instanceof User ? $request->user() : null;
            $post = Post::withTrashed()->find($attachment->post_id);
            $topic = $post instanceof Post ? Topic::withTrashed()->find($post->topic_id) : null;
            $canView = $post instanceof Post && $topic instanceof Topic
                && $visibility->canView($user, $post, $topic);
            // Intentional, explicit extra access: the uploader keeps their own file (covers their own still-pending
            // or recycled post, and a file they uploaded into a post they no longer own).
            abort_unless($canView || ($user !== null && $user->getKey() === $attachment->user_id), 403);
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
