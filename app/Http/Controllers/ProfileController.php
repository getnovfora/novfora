<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Content\ContentRenderer;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Permissions\Scope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Member profiles (data-model §1): avatar/cover, signature, and admin-defined custom fields. The signature
 * is rendered through the M2 canonical pipeline + allowlist sanitizer (ADR-0005) — client HTML is never
 * trusted, exactly like posts.
 */
class ProfileController extends Controller
{
    public function show(User $user): View
    {
        $fields = CustomField::where('is_active', true)->orderBy('position')->get();
        $values = $user->customFieldValues()->get()->keyBy('custom_field_id');

        return view('profiles.show', compact('user', 'fields', 'values'));
    }

    public function edit(Request $request): View
    {
        $user = $this->user($request);
        $fields = CustomField::where('is_active', true)->orderBy('position')->get();
        $values = $user->customFieldValues()->get()->keyBy('custom_field_id');

        return view('profiles.edit', compact('user', 'fields', 'values'));
    }

    public function update(Request $request, ContentRenderer $renderer): RedirectResponse
    {
        $user = $this->user($request);

        $data = $request->validate([
            'signature' => ['nullable', 'string', 'max:1000'],
            'fields' => ['array'],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'cover' => ['nullable', 'image', 'max:5120'],
        ]);

        // Signature via the canonical pipeline (markdown → server-sanitized HTML). Never trust client HTML.
        $signature = trim((string) ($data['signature'] ?? ''));
        if ($signature === '') {
            $user->forceFill(['signature_doc' => null, 'signature_format' => null, 'signature_html' => null]);
        } else {
            // Apply the SAME anti-spam link/image suppression as post bodies (security §2.4): a gated
            // author (e.g. TL0, where post.links/post.images are a hard NEVER) must not render links or
            // images in their signature either — the signature is a public surface (/users/{user}).
            // Resolved through the permission engine, exactly like PostService::restrictionsFor.
            $restrict = [];
            if (! $user->canDo('post.links', Scope::global())) {
                $restrict[] = 'links';
            }
            if (! $user->canDo('post.images', Scope::global())) {
                $restrict[] = 'images';
            }
            $rendered = $renderer->render('markdown', ['source' => $signature], $restrict);
            $user->forceFill([
                'signature_doc' => ['source' => $signature],
                'signature_format' => 'markdown',
                'signature_html' => $rendered['html'],
            ]);
        }

        if ($request->hasFile('avatar')) {
            $user->avatar_path = (string) $request->file('avatar')->store('avatars', 'public');
        }
        if ($request->hasFile('cover')) {
            $user->cover_path = (string) $request->file('cover')->store('covers', 'public');
        }
        $user->save();

        foreach (CustomField::where('is_active', true)->get() as $field) {
            $value = $data['fields'][$field->key] ?? null;
            CustomFieldValue::updateOrCreate(
                ['user_id' => $user->getKey(), 'custom_field_id' => $field->id],
                ['value' => is_string($value) && trim($value) !== '' ? trim($value) : null],
            );
        }

        return back()->with('status', 'Profile updated.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
