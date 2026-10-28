<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Forum;
use App\Models\TopicField;
use App\Models\User;
use App\Permissions\Scope;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ACP management of admin-defined custom TOPIC fields (U19 / NOV-116) — the forum-scoped sibling of the profile
 * custom fields. Gated on admin.settings (the custom-field precedent). Field VALUES are validated by
 * TopicFieldService at topic-creation time; this only defines the catalog.
 */
class TopicFieldController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeAdmin($request);

        return view('admin.topic-fields', [
            'fields' => TopicField::with('forum')->orderBy('position')->orderBy('id')->get(),
            'forums' => Forum::orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'key' => ['required', 'alpha_dash', 'max:40', Rule::unique('topic_fields', 'key')],
            'label' => ['required', 'string', 'max:80'],
            'type' => ['required', 'in:text,url,textarea,select'],
            'forum_id' => ['nullable', 'integer', 'exists:forums,id'],
            'is_required' => ['nullable', 'boolean'],
            'options' => ['nullable', 'string', 'max:2000'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        // A select's choices are entered one-per-line; parse to a clean, de-duplicated, capped list.
        $options = null;
        if ($data['type'] === 'select') {
            $options = collect(preg_split('/\r?\n/', (string) ($data['options'] ?? '')) ?: [])
                ->map(fn ($o) => trim((string) $o))
                ->filter(fn ($o) => $o !== '')
                ->unique()->take(50)->values()->all();
            if ($options === []) {
                return back()->withInput()->withErrors(['options' => 'A select field needs at least one choice (one per line).']);
            }
        }

        $field = TopicField::create([
            'key' => $data['key'],
            'label' => $data['label'],
            'type' => $data['type'],
            'forum_id' => $data['forum_id'] ?? null,
            'is_required' => (bool) ($data['is_required'] ?? false),
            'options' => $options,
            'position' => $data['position'] ?? 0,
            'is_active' => true,
        ]);
        Audit::log('topic_field.created', $field, ['key' => $field->key, 'type' => $field->type]);

        return back()->with('status', 'Topic field added.');
    }

    public function destroy(Request $request, TopicField $field): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $field->delete(); // values cascade via FK
        Audit::log('topic_field.deleted', null, ['key' => $field->key]);

        return back();
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('admin.settings', Scope::global()), 403);
    }
}
