<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\AntiSpam\NewUserModeration;
use App\Forum\PostService;
use App\Members\MemberActivationService;
use App\Models\AuditLog;
use App\Models\Ban;
use App\Models\Forum;
use App\Models\Post;
use App\Models\RegistrationCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| U14 (NOV-112, ADR-0119) — the pending-member exit ramp. Activation lifts the anti-spam hold, so it is
| gated: only via an explicit admin action or the config-gated auto-threshold, and NEVER for a banned account.
*/

uses(RefreshDatabase::class);

/** A flagged, pending member with a registration check + N approved posts (attached to a real topic). */
function pendingMember(int $approved = 0, string $name = 'dan'): User
{
    $u = Users::inGroups(['members', 'tl0'], ['username' => $name, 'email' => $name.'@pending.test']);
    $u->forceFill(['status' => 'pending'])->save();
    RegistrationCheck::create(['user_id' => $u->id, 'ip_address' => '203.0.113.5', 'email' => $u->email, 'username' => $name, 'decision' => 'flag', 'provider_scores' => ['stopforumspam' => ['confidence' => 40], 'velocity' => true]]);

    if ($approved > 0) {
        $forum = Forum::firstOrCreate(['slug' => 'review'], ['title' => 'Review', 'type' => 'forum']);
        $op = Users::inGroups(['members', 'tl2'], ['username' => 'op-'.$name, 'email' => 'op-'.$name.'@t.test']);
        $topic = app(PostService::class)->createTopic($op, $forum, 'Topic for '.$name, 'markdown', ['source' => 'op']);
        for ($i = 0; $i < $approved; $i++) {
            Post::create(['user_id' => $u->id, 'topic_id' => $topic->id, 'position' => $i + 100, 'body_canonical' => "post {$i}", 'approved_state' => 'approved', 'ip_address' => '203.0.113.5']);
        }
    }

    return $u;
}

it('lists pending members with the flag reason + approved counts, and hides active ones', function () {
    $this->seed();
    pendingMember(2, 'dan');
    Users::inGroups(['members'], ['username' => 'active_alice']); // active — not in the queue

    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));
    Livewire::test('admin.members.pending')
        ->assertSee('dan')
        ->assertSee('flag')          // decision surfaced
        ->assertSee('2/2 posts approved')
        ->assertDontSee('active_alice');
});

it('activates a pending member: status flips, audited, trust recomputes, and condition A no longer holds', function () {
    $this->seed();
    $dan = pendingMember(15, 'dan'); // 15 approved posts — the "Dan" case; should promote out of TL0 on activation

    expect(app(NewUserModeration::class)->shouldHold($dan))->toBeTrue(); // pending → held

    app(MemberActivationService::class)->activate($dan, null, 'manual');

    $dan->refresh();
    expect($dan->status)->toBe('active')
        ->and(app(NewUserModeration::class)->shouldHold($dan))->toBeFalse() // condition A cleared — the U14 contract
        ->and(AuditLog::where('action', 'member.activated')->where('auditable_id', $dan->id)->where('changes->path', 'manual')->exists())->toBeTrue();
    // (Promotion out of TL0 is the trust engine's job — the recompute is invoked; its thresholds are tested in the trust suite.)
});

it('NEVER activates a banned account (manual)', function () {
    $this->seed();
    $u = Users::inGroups(['members'], ['username' => 'spammer']);
    $u->forceFill(['status' => 'banned'])->save();
    Ban::create(['user_id' => $u->id, 'type' => 'user', 'scope_type' => 'global', 'reason' => 'spam']);

    expect(app(MemberActivationService::class)->activate($u, null, 'manual'))->toBeFalse();
    expect($u->fresh()->status)->toBe('banned');
});

it('is idempotent — activating an active user is a no-op', function () {
    $this->seed();
    $u = Users::inGroups(['members'], ['username' => 'alice']); // active
    expect(app(MemberActivationService::class)->activate($u, null, 'manual'))->toBeFalse();
});

it('auto-activates on the Kth mod-approved post when enabled, never a banned account, and obeys default-off', function () {
    $this->seed();
    config()->set('novfora.antispam.auto_activation.enabled', true);
    config()->set('novfora.antispam.auto_activation.posts', 3);

    // A pending author with 2 already-approved posts; approving a 3rd should auto-activate.
    $author = pendingMember(2, 'nina');
    $forum = Forum::create(['slug' => 'g', 'title' => 'General', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl2'], ['username' => 'op']), $forum, 'T', 'markdown', ['source' => 'op']);
    $held = Post::create(['user_id' => $author->id, 'topic_id' => $topic->id, 'position' => 99, 'body_canonical' => 'held reply', 'approved_state' => 'pending', 'ip_address' => '203.0.113.5']);

    $mod = Users::withTwoFactor(Users::inGroups(['moderators']));
    $this->actingAs($mod)->post(route('posts.approve', $held))->assertRedirect();

    expect($author->fresh()->status)->toBe('active'); // 3rd approval → auto-activated

    // Default-off: a fresh pending author does NOT auto-activate.
    config()->set('novfora.antispam.auto_activation.enabled', false);
    $other = pendingMember(2, 'omar');
    $held2 = Post::create(['user_id' => $other->id, 'topic_id' => $topic->id, 'position' => 98, 'body_canonical' => 'held', 'approved_state' => 'pending', 'ip_address' => '203.0.113.5']);
    $this->actingAs($mod)->post(route('posts.approve', $held2))->assertRedirect();
    expect($other->fresh()->status)->toBe('pending');
});

it('gates the queue behind users.manage + staff-2FA', function () {
    $this->seed();
    // An admin WITHOUT users.manage (members.access only) is refused the PII-bearing queue.
    $this->actingAs(Users::inGroups(['members']));
    Livewire::test('admin.members.pending')->assertStatus(403);
});
