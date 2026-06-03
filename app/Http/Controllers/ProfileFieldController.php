<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CustomField;
use App\Models\User;
use App\Permissions\Scope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** ACP management of admin-defined custom profile fields (data-model §1). Gated on admin.settings. */
class ProfileFieldController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeAdmin($request);

        return view('admin.profile-fields', ['fields' => CustomField::orderBy('position')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'key' => ['required', 'alpha_dash', 'max:40', Rule::unique('custom_fields', 'key')],
            'label' => ['required', 'string', 'max:80'],
            'type' => ['required', 'in:text,url,textarea'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        CustomField::create($data + ['is_active' => true]);

        return back()->with('status', 'Field added.');
    }

    public function destroy(Request $request, CustomField $field): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $field->delete();

        return back();
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('admin.settings', Scope::global()), 403);
    }
}
