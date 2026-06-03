<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forum\AttachmentService;
use App\Models\Attachment;
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
        // M2: a post's attachments are public like the post HTML; an orphan (just-uploaded, not yet attached)
        // is visible only to its uploader. Finer per-forum private-attachment gating is a later refinement.
        if ($attachment->post_id === null) {
            abort_unless($request->user()?->getKey() === $attachment->user_id, 403);
        }

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response($attachment->path, $attachment->original_name, ['Content-Type' => $attachment->mime]);
    }
}
