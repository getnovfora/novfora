<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Discovery\TrendingService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Trending / best-of (discovery 3.1). Public, permission-safe (the service gates on forum visibility). A guest
 * sees only public-forum topics; a member sees their visible set.
 */
class TrendingController extends Controller
{
    public function index(Request $request, TrendingService $trending): View
    {
        $viewer = $request->user() ?? User::guest();

        return view('discovery.trending', [
            'trending' => $trending->trending($viewer, 7, 20),
            'bestOf' => $trending->bestOf($viewer, 20),
        ]);
    }
}
