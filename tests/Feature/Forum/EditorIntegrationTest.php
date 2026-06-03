<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| The server-observable half of the Spike-0 editor battery. The editor DOM survival across a Livewire
| morph is provided by wire:ignore (proven in-browser by Spike 0 / Playwright 6-6); here we assert the
| SYNCED canonical content (the property the island writes via $wire.set) survives the same round-trips.
| The full in-browser journey is in tests/Browser/ (Dusk), run in a Chrome-enabled CI.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('preserves the editor canonical content across a validation round-trip (criterion #1a, server half)', function () {
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $member = Users::inGroups(['members']);
    $doc = Content::doc('a draft that must survive the morph');

    Livewire::actingAs($member)
        ->test('forum.create-topic', ['forumId' => $forum->id])
        ->set('canonicalJson', $doc)
        ->set('title', 'ab')          // too short → save() validation fails → the component re-renders
        ->call('save')
        ->assertHasErrors('title')
        ->assertSet('canonicalJson', $doc); // synced editor content survived the round-trip
});

it('keeps the canonical content when toggling to Markdown mode and back', function () {
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $member = Users::inGroups(['members']);
    $doc = Content::doc('rich content worth keeping');

    Livewire::actingAs($member)
        ->test('forum.create-topic', ['forumId' => $forum->id])
        ->set('canonicalJson', $doc)
        ->call('toggleFormat')  // → markdown
        ->call('toggleFormat')  // → back to rich text
        ->assertSet('canonicalJson', $doc);
});
