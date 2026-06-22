<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme\Sandbox;

/**
 * The versioned, public CONTRACT for sandbox templates (ADR-0038), mirroring the module/theme APIs. It lists
 * the OVERRIDABLE templates — each a stable key, a human label, the variables exposed to it, and the shipped
 * DEFAULT source (so the editor can diff-vs-default and "revert"). A site renders an overridable template only
 * when an admin has enabled it; by default nothing here changes the stock UI.
 *
 * Versioning mirrors the module API: adding a template/variable/helper = MINOR; renaming/removing = MAJOR.
 */
final class TemplateContract
{
    public const VERSION = '1.0.0';

    /**
     * @return array<string, array{label:string, description:string, variables:array<string,string>, default:string}>
     */
    public static function templates(): array
    {
        return [
            'home_welcome' => [
                'label' => 'Home welcome panel',
                'description' => 'A panel shown at the top of the forum index.',
                'variables' => [
                    'site.name' => 'The board name', 'site.description' => 'The board tagline',
                    'user.is_guest' => 'true for a signed-out visitor', 'user.username' => 'The signed-in member’s name',
                    'stats.members' => 'Active member count', 'stats.topics' => 'Topic count', 'stats.posts' => 'Post count',
                ],
                'default' => <<<'TPL'
                    <div class="rounded-lg border border-line bg-surface-raised p-4">
                      <h2 class="text-lg font-semibold text-ink">{% if user.is_guest %}Welcome to {{ site.name }}{% else %}Welcome back, {{ user.username }}{% endif %}</h2>
                      {% if site.description %}<p class="mt-1 text-sm text-ink-muted">{{ site.description }}</p>{% endif %}
                      <p class="mt-2 text-xs text-ink-subtle">{{ number(stats.members) }} members · {{ number(stats.topics) }} topics · {{ number(stats.posts) }} posts</p>
                    </div>
                    TPL,
            ],
            'topic_footer' => [
                'label' => 'Topic footer note',
                'description' => 'A note shown beneath each topic’s posts.',
                'variables' => [
                    'topic.title' => 'The topic title', 'topic.reply_count' => 'Number of replies',
                    'site.name' => 'The board name',
                ],
                'default' => <<<'TPL'
                    {% if topic.reply_count > 0 %}<p class="mt-4 text-center text-xs text-ink-subtle">{{ number(topic.reply_count) }} {{ plural(topic.reply_count, 'reply', 'replies') }} · thanks for reading on {{ site.name }}.</p>{% endif %}
                    TPL,
            ],
            // T2 (ADR-0099) — admin-editable transactional email BODIES, rendered through this same sandbox
            // (variables auto-escaped, no PHP/Blade, scripts lint-blocked). Disabled/absent → the shipped Blade
            // default is used; the subject line is NEVER admin-rendered (it stays a code-controlled string).
            'email.notification' => [
                'label' => 'Email — notification',
                'description' => 'Body of the per-event notification email (reply, mention, reaction, PM, follow, moderation). Falls back to the built-in default when disabled.',
                'variables' => [
                    'event' => 'The notification type (reply, mention, reaction, pm.received, follow, moderation)',
                    'actor' => 'The member who triggered it',
                    'topic_title' => 'The topic title, if any',
                    'url' => 'A link to the content',
                    'site.name' => 'The board name',
                ],
                'default' => <<<'TPL'
                    <p>Hello,</p>
                    <p>{{ actor }} has new activity for you{% if topic_title %} in “{{ topic_title }}”{% endif %}.</p>
                    {% if url %}<p><a href="{{ url }}">View it on {{ site.name }}</a></p>{% endif %}
                    TPL,
            ],
            'email.digest' => [
                'label' => 'Email — digest',
                'description' => 'Body of the coalesced digest email. Loop the updates with {% for item in items %}. Falls back to the built-in default when disabled.',
                'variables' => [
                    'recipient_name' => 'The member receiving the digest',
                    'items' => 'The updates — loop with {% for item in items %}; each has item.actor, item.topic_title, item.url, item.event',
                    'unsubscribe_url' => 'The one-click unsubscribe link',
                    'site.name' => 'The board name',
                ],
                'default' => <<<'TPL'
                    <p>Hello {{ recipient_name }},</p>
                    <p>Here’s what you missed on {{ site.name }}:</p>
                    <ul>
                    {% for item in items %}<li>{{ item.actor }}{% if item.topic_title %} — {{ item.topic_title }}{% endif %}{% if item.url %} (<a href="{{ item.url }}">view</a>){% endif %}</li>{% endfor %}
                    </ul>
                    TPL,
            ],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::templates());
    }

    /** The shipped default source for a key, or '' if the key is unknown. */
    public static function default(string $key): string
    {
        return self::templates()[$key]['default'] ?? '';
    }
}
