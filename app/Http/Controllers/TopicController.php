<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Topic;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class TopicController extends Controller
{
    public function show(Request $request, Topic $topic): View
    {
        $viewer = $request->user() ?? User::guest();
        abort_unless($viewer->canDo('forum.view', $topic->permissionScope()), 403);

        Topic::whereKey($topic->getKey())->increment('view_count'); // quiet: no model events

        $posts = $topic->posts()
            ->with(['author', 'revisions'])
            ->orderBy('position')->orderBy('id')
            ->paginate(15);

        $user = $request->user();
        $scope = $topic->permissionScope();
        $canReply = $topic->status !== 'locked' && ($user?->canDo('post.create', $scope) ?? false);
        $canModerate = $user?->canDo('topic.moderate', $scope) ?? false;

        return view('forum.topic', compact('topic', 'posts', 'viewer', 'user', 'canReply', 'canModerate'));
    }
}
