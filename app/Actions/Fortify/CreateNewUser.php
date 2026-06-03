<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user, then place them in the default groups.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'username' => ['required', 'string', 'alpha_dash', 'min:3', 'max:30', Rule::unique(User::class)],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
        ], [
            'username.alpha_dash' => 'The username may only contain letters, numbers, dashes and underscores.',
        ])->validate();

        $user = User::create([
            'username' => $input['username'],
            'name' => $input['username'],
            'display_name' => $input['username'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']), // argon2id (config/hashing.php)
        ]);

        // Default membership: primary Members + the entry trust level (security §1 / ADR-0007).
        // Trust levels are ACL groups; promotion automation is M3.
        foreach (['members' => true, 'tl0' => false] as $slug => $isPrimary) {
            $group = Group::where('slug', $slug)->first();
            if ($group instanceof Group) {
                $user->groups()->attach($group->id, ['is_primary' => $isPrimary]);
            }
        }

        return $user;
    }
}
