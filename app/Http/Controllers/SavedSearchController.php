<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Search\SavedSearchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Saved searches (search 6.1) — own-only, auth-gated. Save the current search from the results page, list +
 * re-run + delete from /saved-searches.
 */
class SavedSearchController extends Controller
{
    public function index(Request $request, SavedSearchService $service): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('saved-searches.index', ['searches' => $service->list($user)]);
    }

    public function store(Request $request, SavedSearchService $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'q' => ['nullable', 'string', 'max:500'],
            'query_string' => ['nullable', 'string', 'max:1000'],
        ]);

        $queryString = (string) ($data['query_string'] ?? '');

        if ($service->count($user) >= SavedSearchService::MAX_PER_USER) {
            return back()->with('error', 'You have reached your saved-search limit — remove one first.');
        }

        $service->save($user, $data['name'], (string) ($data['q'] ?? ''), $queryString);

        return redirect()
            ->to(route('search.index').($queryString !== '' ? '?'.$queryString : ''))
            ->with('status', 'Search saved.');
    }

    public function destroy(Request $request, SavedSearchService $service, int $search): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $service->delete($user, $search);

        return redirect()->route('saved-searches.index')->with('status', 'Saved search removed.');
    }
}
