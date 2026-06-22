<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Mail\DigestMail;
use App\Mail\NotificationMail;
use App\Models\DigestQueueItem;
use App\Models\SiteTemplate;
use App\Models\User;
use App\Theme\Sandbox\SandboxException;
use App\Theme\Sandbox\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| T2 (ADR-0099) — admin-editable transactional email bodies through the EXISTING SiteTemplate sandbox (no new
| table; keys email.notification / email.digest). APEX render-injection boundary: the body renders through the
| sandbox (every variable auto-escaped, no PHP/Blade, scripts lint-blocked); the subject is code-controlled +
| CRLF-stripped. '' (no enabled custom template) → the shipped Blade default.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('renders a custom notification email body from an enabled sandbox template', function () {
    SiteTemplate::create(['template_key' => 'email.notification', 'source' => '<p>Custom: {{ actor }} in {{ topic_title }}</p>', 'is_enabled' => true]);

    $html = (new NotificationMail('reply', 'Alice', ['topic_title' => 'PHP tips', 'url' => 'http://x/1']))->render();

    expect($html)->toContain('Custom: Alice in PHP tips');
});

it('auto-escapes an attacker-controlled topic_title in the custom body (no stored XSS)', function () {
    SiteTemplate::create(['template_key' => 'email.notification', 'source' => '<p>{{ topic_title }}</p>', 'is_enabled' => true]);

    $html = (new NotificationMail('reply', 'Alice', ['topic_title' => '<script>alert(1)</script>', 'url' => 'http://x']))->render();

    expect($html)->toContain('&lt;script&gt;')->and($html)->not->toContain('<script>alert(1)</script>');
});

it('falls back to the built-in body when no custom template is enabled', function () {
    $html = (new NotificationMail('reply', 'Bob', ['topic_title' => 'A thread', 'url' => 'http://x']))->render();

    expect($html)->toContain('Bob replied in')->and($html)->toContain('A thread');
});

it('ignores a DISABLED custom template (falls back to the default body)', function () {
    SiteTemplate::create(['template_key' => 'email.notification', 'source' => '<p>Custom body</p>', 'is_enabled' => false]);

    $html = (new NotificationMail('reply', 'Bob', ['topic_title' => 'A thread', 'url' => 'http://x']))->render();

    expect($html)->not->toContain('Custom body')->and($html)->toContain('Bob replied in');
});

it('strips CRLF from the email subject (header-injection fence)', function () {
    $mail = new NotificationMail('reply', 'Alice', ['topic_title' => "Hello\r\nBcc: evil@example.test", 'url' => 'http://x']);

    $subject = $mail->envelope()->subject;
    expect($subject)->not->toContain("\n")->and($subject)->not->toContain("\r");
});

it('blocks a script in an email template at save (sandbox lint)', function () {
    expect(fn () => app(TemplateService::class)->save('email.notification', '<script>alert(1)</script>'))
        ->toThrow(SandboxException::class);
    expect(fn () => app(TemplateService::class)->save('email.digest', '<p onclick="x()">hi</p>'))
        ->toThrow(SandboxException::class);
});

it('renders a custom digest body that loops the items (escaped)', function () {
    SiteTemplate::create([
        'template_key' => 'email.digest',
        'source' => '<ul>{% for item in items %}<li>{{ item.actor }}: {{ item.topic_title }}</li>{% endfor %}</ul>',
        'is_enabled' => true,
    ]);
    $user = User::factory()->create();
    $items = [
        new DigestQueueItem(['actor_username' => 'Carol', 'event_type' => 'reply', 'payload' => ['topic_title' => 'Beta thread', 'url' => 'http://x/1']]),
        new DigestQueueItem(['actor_username' => 'Dave', 'event_type' => 'mention', 'payload' => ['topic_title' => '<b>raw</b>', 'url' => 'http://x/2']]),
    ];

    $html = (new DigestMail(1, $user, $items))->render();

    expect($html)->toContain('Carol: Beta thread')      // the loop ran
        ->and($html)->toContain('&lt;b&gt;raw&lt;/b&gt;') // attacker title escaped
        ->and($html)->not->toContain('<b>raw</b>');
});

it('lists the email templates in the ACP sandbox editor', function () {
    Livewire::actingAs(Users::withTwoFactor(Users::inGroups(['admins'])))->test('admin.settings.templates')
        ->assertSee('Email — notification')
        ->assertSee('Email — digest');
});
