<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\CustomField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Users;

/*
| Profiles (data-model §1): signatures rendered + sanitized through the canonical pipeline (never trust client
| HTML), admin-defined custom fields with values, and avatar/cover uploads.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('saves a signature rendered and sanitized through the canonical pipeline', function () {
    $user = Users::inGroups(['members', 'tl1']);

    $this->actingAs($user)->post(route('settings.profile.save'), [
        'signature' => '**bold** sig <script>alert(1)</script>',
    ])->assertRedirect();

    $user->refresh();
    expect($user->signature_html)->toContain('<strong>bold</strong>')->not->toContain('<script');
    expect($user->signature_doc)->toMatchArray(['source' => '**bold** sig <script>alert(1)</script>']);
});

it('saves custom field values and shows them on the profile', function () {
    $user = Users::inGroups(['members', 'tl1'], ['username' => 'fielduser']);

    $this->actingAs($user)->post(route('settings.profile.save'), [
        'fields' => ['location' => 'Berlin', 'website' => 'https://example.com'],
    ])->assertRedirect();

    $this->get(route('profiles.show', $user))->assertOk()
        ->assertSee('Berlin')->assertSee('https://example.com');
});

it('stores an uploaded avatar', function () {
    Storage::fake('public');
    $user = Users::inGroups(['members', 'tl1']);

    $this->actingAs($user)->post(route('settings.profile.save'), [
        'avatar' => UploadedFile::fake()->create('me.png', 40, 'image/png'), // ->create (not ->image) needs no GD
    ])->assertRedirect();

    $user->refresh();
    expect($user->avatar_path)->not->toBeNull();
    Storage::disk('public')->assertExists($user->avatar_path);
});

it('lets an admin define a custom field', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));

    $this->actingAs($admin)->post(route('admin.system.profile-fields.store'), [
        'key' => 'pronouns', 'label' => 'Pronouns', 'type' => 'text',
    ])->assertRedirect();

    expect(CustomField::where('key', 'pronouns')->exists())->toBeTrue();
});

it('forbids a non-admin from the field management screen', function () {
    $this->actingAs(Users::inGroups(['members']))->get(route('admin.system.profile-fields'))->assertForbidden();
});
