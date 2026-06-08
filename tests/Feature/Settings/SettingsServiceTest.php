<?php

// SPDX-License-Identifier: Apache-2.0

use App\Models\AuditLog;
use App\Models\Setting;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function acpSettings(): Settings
{
    return app(Settings::class);
}

it('falls back to the config-backed value when no override row exists', function () {
    config(['hearth.antispam.new_user_moderation.posts' => 2]);

    expect(acpSettings()->int('moderation.new_user_hold_posts'))->toBe(2);
    expect(Setting::count())->toBe(0); // defaults are NOT persisted (ADR-0023)
});

it('falls back to the literal registry default for a pure-DB setting', function () {
    expect(acpSettings()->string('appearance.forum_width'))->toBe('standard');
    expect(acpSettings()->string('appearance.poster_position'))->toBe('left');
    expect(acpSettings()->bool('general.board_offline'))->toBeFalse();
});

it('lets a DB override take precedence over config, persisting as a row', function () {
    config(['hearth.antispam.new_user_moderation.posts' => 2]);

    acpSettings()->set('moderation.new_user_hold_posts', 0);

    expect(acpSettings()->int('moderation.new_user_hold_posts'))->toBe(0);
    expect(Setting::where('key', 'moderation.new_user_hold_posts')->value('value'))->toBe('0');
});

it('reads a write back immediately (write-through cache invalidation)', function () {
    acpSettings()->set('general.site_name', 'My Forum');

    expect(acpSettings()->string('general.site_name'))->toBe('My Forum');
    // A fresh service instance (cache, not memo) must also see it.
    expect(app()->make(Settings::class)->string('general.site_name'))->toBe('My Forum');
});

it('coerces values to their declared type', function () {
    acpSettings()->set('general.board_offline', '1');
    expect(acpSettings()->bool('general.board_offline'))->toBeTrue();

    acpSettings()->set('general.board_offline', false);
    expect(acpSettings()->bool('general.board_offline'))->toBeFalse();

    acpSettings()->set('moderation.suspicious_score', '7');
    expect(acpSettings()->int('moderation.suspicious_score'))->toBe(7);
});

it('stores a secret encrypted, decrypts only in-process, and never stores plaintext', function () {
    acpSettings()->set('mail.password', 's3cr3t-smtp-pass');

    $row = Setting::where('key', 'mail.password')->firstOrFail();
    expect($row->is_encrypted)->toBeTrue();
    expect($row->value)->not->toBe('s3cr3t-smtp-pass');
    expect($row->value)->not->toContain('s3cr3t');

    expect(acpSettings()->get('mail.password'))->toBe('s3cr3t-smtp-pass');
    expect(acpSettings()->secretIsSet('mail.password'))->toBeTrue();
});

it('treats a blank secret write as "leave the current value unchanged"', function () {
    acpSettings()->set('mail.password', 'first');
    acpSettings()->set('mail.password', '');

    expect(acpSettings()->get('mail.password'))->toBe('first');
});

it('audit-logs every write with who/key/old→new', function () {
    config(['hearth.antispam.new_user_moderation.posts' => 2]);

    acpSettings()->set('moderation.new_user_hold_posts', 0);

    $entry = AuditLog::where('action', 'settings.updated')->latest('id')->firstOrFail();
    expect($entry->changes['key'])->toBe('moderation.new_user_hold_posts');
    expect($entry->changes['old'])->toBe('2');
    expect($entry->changes['new'])->toBe('0');
});

it('masks secrets in the audit log (plaintext never written)', function () {
    acpSettings()->set('mail.password', 'topsecret-value');

    $entry = AuditLog::where('action', 'settings.updated')->latest('id')->firstOrFail();
    expect($entry->changes['key'])->toBe('mail.password');
    expect($entry->changes['new'])->toBe('••••••');
    expect(json_encode($entry->changes))->not->toContain('topsecret');
});

it('pushes DB overrides into live config so existing consumers honour them', function () {
    acpSettings()->set('moderation.new_user_hold_posts', 5);
    acpSettings()->set('general.site_name', 'Override Town');

    acpSettings()->applyToConfig();

    expect(config('hearth.antispam.new_user_moderation.posts'))->toBe(5);
    expect(config('app.name'))->toBe('Override Town');
});

it('leaves config untouched for keys without an override row', function () {
    config(['app.name' => 'Untouched']);

    acpSettings()->applyToConfig();

    expect(config('app.name'))->toBe('Untouched');
});

it('reverts a key to its env/config fallback on forget()', function () {
    config(['hearth.antispam.content.suspicious_score' => 2]);

    acpSettings()->set('moderation.suspicious_score', 9);
    expect(acpSettings()->int('moderation.suspicious_score'))->toBe(9);

    acpSettings()->forget('moderation.suspicious_score');

    expect(Setting::where('key', 'moderation.suspicious_score')->exists())->toBeFalse();
    expect(acpSettings()->int('moderation.suspicious_score'))->toBe(2);
    expect(AuditLog::where('action', 'settings.reset')->count())->toBe(1);
});

it('rejects an unknown setting key', function () {
    acpSettings()->set('not.a.real.key', 'x');
})->throws(InvalidArgumentException::class);
