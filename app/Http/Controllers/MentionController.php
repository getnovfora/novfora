<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Typeahead source for the editor's @mentions (authenticated composers only). */
class MentionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        $users = User::query()
            ->whereNotNull('username')
            ->when($query !== '', fn ($q) => $q->where('username', 'like', $query.'%'))
            ->orderBy('username')
            ->limit(8)
            ->get(['id', 'username'])
            ->map(fn (User $u) => ['id' => $u->id, 'username' => $u->username]);

        return response()->json(['data' => $users]);
    }
}
