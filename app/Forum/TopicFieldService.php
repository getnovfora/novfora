<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Models\Forum;
use App\Models\Topic;
use App\Models\TopicField;
use App\Models\TopicFieldValue;
use Illuminate\Support\Collection;

/**
 * The one authority for custom TOPIC field values (U19 / NOV-116). Topic-field values are entered by TOPIC
 * CREATORS (untrusted), so this validates every value server-side — the profile-field precedent only had an
 * HTML5 hint, which is not a control. It enforces the required flag, a real URL (http/https only, so a
 * `javascript:`/`data:` value can never be rendered as a link), a `select` value drawn from the field's own
 * option list, and length caps. `applicable()` resolves the forum-scoped catalog (global + this forum).
 */
final class TopicFieldService
{
    private const MAX_TEXT = 255;

    private const MAX_TEXTAREA = 2000;

    /**
     * The active fields that apply to $forum — global (null forum_id) plus this forum's — ordered for display.
     *
     * @return Collection<int, TopicField>
     */
    public function applicable(Forum $forum): Collection
    {
        return TopicField::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('forum_id')->orWhere('forum_id', $forum->getKey()))
            ->orderBy('position')->orderBy('id')
            ->get();
    }

    /**
     * Validate raw {key => value} input for $forum's fields. Returns per-field error messages (keyed by field
     * KEY, for `addError("fieldValues.{key}")`) and the cleaned values (keyed by field ID, for storage).
     *
     * @param  array<string, mixed>  $raw
     * @return array{errors: array<string, string>, clean: array<int, ?string>}
     */
    public function validate(Forum $forum, array $raw): array
    {
        $errors = [];
        $clean = [];

        foreach ($this->applicable($forum) as $field) {
            $value = $raw[$field->key] ?? null;
            $value = is_string($value) ? trim($value) : (is_scalar($value) ? (string) $value : '');

            if ($value === '') {
                if ($field->is_required) {
                    $errors[$field->key] = $field->label.' is required.';
                }
                $clean[$field->id] = null;

                continue;
            }

            $error = $this->validateValue($field, $value);
            if ($error !== null) {
                $errors[$field->key] = $error;

                continue;
            }

            $clean[$field->id] = $value;
        }

        return ['errors' => $errors, 'clean' => $clean];
    }

    /** Validate one non-empty value for its field's type; null if OK, else the message. */
    private function validateValue(TopicField $field, string $value): ?string
    {
        return match ($field->type) {
            'url' => $this->validateUrl($field, $value),
            'select' => in_array($value, is_array($field->options) ? $field->options : [], true)
                ? null
                : $field->label.' is not a valid choice.',
            'textarea' => mb_strlen($value) <= self::MAX_TEXTAREA
                ? null
                : $field->label.' is too long (max '.self::MAX_TEXTAREA.' characters).',
            default => mb_strlen($value) <= self::MAX_TEXT
                ? null
                : $field->label.' is too long (max '.self::MAX_TEXT.' characters).',
        };
    }

    /** A URL value must be capped, an http(s) URL, and carry no embedded credentials (userinfo phishing). */
    private function validateUrl(TopicField $field, string $value): ?string
    {
        if (mb_strlen($value) > self::MAX_TEXT) {
            return $field->label.' is too long (max '.self::MAX_TEXT.' characters).';
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#i', $value) !== 1) {
            return $field->label.' must be a valid http(s) URL.';
        }
        // Reject an embedded username/password (e.g. https://trusted.example@evil.test) — a phishing vector when
        // the value is rendered as a link.
        if (parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null) {
            return $field->label.' must not contain a username or password.';
        }

        return null;
    }

    /**
     * Validate + persist a topic's field values in one call (the createTopic hook). Throws on the first invalid
     * value so a bad value never silently drops — the Livewire form pre-validates for nicer per-field errors,
     * this is the service-boundary backstop.
     *
     * @param  array<string, mixed>  $raw
     *
     * @throws TopicFieldException
     */
    public function sync(Topic $topic, Forum $forum, array $raw): void
    {
        $result = $this->validate($forum, $raw);
        if ($result['errors'] !== []) {
            $key = array_key_first($result['errors']);

            throw new TopicFieldException((string) $key, $result['errors'][$key]);
        }

        foreach ($result['clean'] as $fieldId => $value) {
            if ($value === null) {
                TopicFieldValue::query()
                    ->where('topic_id', $topic->getKey())->where('topic_field_id', $fieldId)->delete();

                continue;
            }
            TopicFieldValue::updateOrCreate(
                ['topic_id' => $topic->getKey(), 'topic_field_id' => $fieldId],
                ['value' => $value],
            );
        }
    }

    /**
     * The topic's non-empty field values for display, ordered — each ['label', 'type', 'value'].
     *
     * @return list<array{label:string, type:string, value:string}>
     */
    public function displayValues(Topic $topic): array
    {
        $values = TopicFieldValue::query()
            ->where('topic_id', $topic->getKey())
            ->whereNotNull('value')
            ->with('field')
            ->get();

        $rows = [];
        foreach ($values as $v) {
            $field = $v->field;
            if (! $field instanceof TopicField || ! $field->is_active || trim((string) $v->value) === '') {
                continue;
            }
            $rows[] = [
                'position' => (int) $field->position,
                'label' => (string) $field->label,
                'type' => (string) $field->type,
                'value' => (string) $v->value,
            ];
        }
        usort($rows, fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return array_map(
            fn (array $r): array => ['label' => $r['label'], 'type' => $r['type'], 'value' => $r['value']],
            $rows,
        );
    }
}
