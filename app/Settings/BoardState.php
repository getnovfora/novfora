<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Settings;

use App\Models\User;
use App\Permissions\Scope;

/**
 * The board's operational state (ACP General → "Take the board offline") as ONE predicate, shared by every
 * entry point that lets a member act (NF-07): the web stack and Livewire updates (EnsureBoardOnline, which
 * Livewire re-applies to each component's originating route), the REST API, and the scheduled-post publisher.
 * Admins are the recovery exception everywhere — they keep working and lift the switch.
 */
final class BoardState
{
    public function __construct(private readonly Settings $settings) {}

    public function isOffline(): bool
    {
        return $this->settings->bool('general.board_offline');
    }

    public function message(): string
    {
        return $this->settings->string('general.board_offline_message');
    }

    /** May this user act right now? Always while online; only admins while offline. */
    public function allows(?User $user): bool
    {
        return ! $this->isOffline() || $this->isAdmin($user);
    }

    private function isAdmin(?User $user): bool
    {
        return $user instanceof User && $user->canDo('admin.access', Scope::global());
    }
}
