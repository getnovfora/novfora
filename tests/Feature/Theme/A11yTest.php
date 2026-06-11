<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| The accessibility floor (ADR-0009 §3.3 / ADR-0016): a keyboard skip link + a single main landmark on every
| page, a visible-focus rule, and AA-contrast design tokens — baked into core so themes can't strip them.
*/

uses(RefreshDatabase::class);

it('renders the skip link and a main landmark (keyboard floor)', function () {
    $this->seed();
    Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);

    $this->get(route('forums.index'))->assertOk()
        ->assertSee('Skip to content')
        ->assertSee('href="#main"', false)
        ->assertSee('id="main"', false);
});

it('ships a visible-focus rule and AA-contrast design tokens', function () {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain(':focus-visible')
        ->toContain('.skip-link')
        ->toContain('--novfora-accent');
});
