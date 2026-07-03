<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Forum\ReactionService;
use App\Mail\WelcomeMail;
use App\Models\Forum;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Onboarding-lite (NOV-123): a welcome email queued on registration (the T2 mail engine) + a dismissible
| getting-started checklist whose items mirror BadgeService's earn-path signals (join / approved post /
| given reaction). The checklist self-gates: shown until every item is done OR the member dismisses it.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('queues a welcome email when a member registers', function () {
    Mail::fake();
    $user = Users::inGroups(['members', 'tl1']);

    event(new Registered($user));

    Mail::assertQueued(WelcomeMail::class, fn (WelcomeMail $mail) => $mail->hasTo($user->email));
});

it('shows the getting-started checklist to a fresh member', function () {
    $user = Users::inGroups(['members', 'tl1']);

    $this->actingAs($user)->get(route('forums.index'))
        ->assertOk()
        ->assertSee('dusk="onboarding-checklist"', false)
        ->assertSee(__('onboarding.item_post'));
});

it('hides the checklist once every item is complete (wired to the earn-path signals)', function () {
    $forum = Forum::create(['slug' => 'ob', 'title' => 'OB', 'type' => 'forum']);
    $user = Users::inGroups(['members', 'tl2']);

    // profile (signature) + first approved post + a given reaction → all three items done.
    $user->forceFill(['signature_doc' => Content::doc('hi there')])->save();
    app(PostService::class)->createTopic($user, $forum, 'My first topic', 'tiptap_json', Content::doc('body'));
    $other = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl2']), $forum, 'Another', 'tiptap_json', Content::doc('b'));
    app(ReactionService::class)->toggle($user, $other->posts()->first(), 'like');

    $this->actingAs($user->fresh())->get(route('forums.index'))
        ->assertOk()
        ->assertDontSee('dusk="onboarding-checklist"', false);
});

it('dismisses the checklist and never shows it again', function () {
    $user = Users::inGroups(['members', 'tl1']);
    expect($user->onboarding_dismissed_at)->toBeNull();

    Livewire::actingAs($user)->test('onboarding-checklist')->call('dismiss');
    expect($user->fresh()->onboarding_dismissed_at)->not->toBeNull();

    $this->actingAs($user->fresh())->get(route('forums.index'))
        ->assertOk()
        ->assertDontSee('dusk="onboarding-checklist"', false);
});
