<?php

// SPDX-License-Identifier: Apache-2.0

use App\Account\AccountDeletionException;
use App\Account\AccountDeletionService;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

/**
 * Voluntary account deletion (ADR-0025). Own-account-only: like ⚡notification-preferences it takes no
 * user-id prop and re-asserts auth in mount() AND every action. Two steps: (1) review the pre-deletion
 * summary and arm the danger form; (2) re-authenticate with the password + tick an explicit confirmation,
 * then run AccountDeletionService::deleteOwnAccount, log out, and redirect. The password is wire:model
 * (deferred) — only sent with the final action and reset immediately, never persisted across an armed
 * round-trip. The irreversible cascade, the recounts, and the sole-admin guard all live in the service.
 */
new class extends Component
{
    public int $step = 1;

    public string $password = '';

    public bool $confirm = false;

    public ?string $error = null;

    /** @var array{posts:int,topics:int,reactions:int,poll_votes:int,messages:int,conversations:int,attachments:int} */
    public array $summary = [];

    public bool $blocked = false;

    public function mount(): void
    {
        $user = $this->user();
        $this->summary = app(AccountDeletionService::class)->summary($user);
        $this->blocked = app(AccountDeletionService::class)->isSoleAdmin($user);
    }

    /** Step 1 → 2: reveal the confirmation form. No password is collected until step 2 submits. */
    public function arm(): void
    {
        $this->user();
        $this->error = null;
        if ($this->blocked) {
            return;
        }
        $this->step = 2;
    }

    public function cancel(): void
    {
        $this->user();
        $this->reset('password', 'confirm', 'error');
        $this->step = 1;
    }

    /** Step 2: re-authenticate, confirm, and run the irreversible cascade. */
    public function deleteAccount(): void
    {
        $user = $this->user();
        $this->error = null;

        if ($this->blocked) {
            $this->error = __('The last administrator account cannot be deleted.');

            return;
        }
        if (! Hash::check($this->password, (string) $user->password)) {
            $this->reset('password');
            $this->error = __('That password is incorrect.');

            return;
        }
        if (! $this->confirm) {
            $this->error = __('Please tick the box to confirm you understand this is permanent.');

            return;
        }

        try {
            app(AccountDeletionService::class)->deleteOwnAccount($user);
        } catch (AccountDeletionException $e) {
            $this->blocked = true;
            $this->reset('password', 'confirm');
            $this->error = $e->getMessage();

            return;
        }

        $this->reset('password', 'confirm');

        // Log the user out by flushing the session — NOT Auth::logout(). The cascade has already removed the
        // users row, so the guard still holds that now-`exists=false` model; Auth::logout() would cycle its
        // remember token and call save(), which Eloquent treats as an INSERT and would silently re-create the
        // just-deleted account. Flushing the session clears the auth login key (logged out on the next
        // request); a stale remember-me cookie is inert because the user no longer exists.
        session()->invalidate();
        session()->regenerateToken();
        session()->flash('status', __('Your account has been permanently deleted.'));

        $this->redirect('/');
    }

    private function user(): User
    {
        $u = auth()->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }
};
?>

<div class="space-y-5">
    @if ($error)
        <x-ui.alert variant="danger" dusk="delete-error">{{ $error }}</x-ui.alert>
    @endif

    @if ($blocked)
        <x-ui.alert variant="warn" :title="__('Deletion unavailable')">
            {{ __('You are the only administrator. Promote another administrator before deleting your account.') }}
        </x-ui.alert>
    @endif

    <x-ui.card>
        <div class="space-y-4">
            <div>
                <h3 class="text-base font-semibold text-ink">{{ __('Delete account') }}</h3>
                <p class="mt-1 text-sm text-ink-subtle">
                    {{ __('Deleting your account is permanent and cannot be undone. Your posts and topics stay on the forum but are anonymised to “[Deleted]”; your private messages, reactions, poll votes, drafts, and notifications are removed.') }}
                </p>
            </div>

            {{-- Pre-deletion summary of what will be permanently removed or anonymised. --}}
            <dl class="grid grid-cols-2 gap-x-4 sm:grid-cols-3" dusk="delete-summary">
                @foreach ([
                    'posts' => __('Posts (anonymised)'),
                    'topics' => __('Topics (anonymised)'),
                    'messages' => __('Private messages'),
                    'reactions' => __('Reactions given'),
                    'poll_votes' => __('Poll votes'),
                    'attachments' => __('Attachments'),
                ] as $key => $label)
                    <div class="flex items-baseline justify-between gap-2 border-b border-line py-1 text-sm">
                        <dt class="text-ink-subtle">{{ $label }}</dt>
                        <dd class="nums font-semibold text-ink">{{ number_format((int) ($summary[$key] ?? 0)) }}</dd>
                    </div>
                @endforeach
            </dl>

            @if (! $blocked)
                @if ($step === 1)
                    <x-ui.button type="button" variant="danger" wire:click="arm" dusk="delete-initiate">
                        {{ __('Delete my account…') }}
                    </x-ui.button>
                @else
                    <form wire:submit="deleteAccount" class="space-y-4 rounded-lg border border-line bg-surface-sunken p-4">
                        <p class="text-sm font-medium text-ink">{{ __('Confirm permanent deletion') }}</p>

                        <x-ui.input type="password" name="password" :label="__('Your password')"
                            wire:model="password" autocomplete="current-password" dusk="delete-password" required />

                        <label class="flex items-start gap-2 text-sm text-ink">
                            <input type="checkbox" wire:model="confirm" dusk="delete-confirm"
                                class="mt-0.5 rounded border-line">
                            <span>{{ __('I understand this permanently deletes my account and cannot be undone.') }}</span>
                        </label>

                        <div class="flex flex-wrap items-center gap-3">
                            <x-ui.button type="submit" variant="danger"
                                wire:loading.attr="disabled" wire:target="deleteAccount" dusk="delete-confirm-submit">
                                <span wire:loading.remove wire:target="deleteAccount">{{ __('Permanently delete my account') }}</span>
                                <span wire:loading wire:target="deleteAccount">{{ __('Deleting…') }}</span>
                            </x-ui.button>
                            <x-ui.button type="button" variant="subtle" wire:click="cancel"
                                wire:target="deleteAccount" wire:loading.attr="disabled">
                                {{ __('Cancel') }}
                            </x-ui.button>
                        </div>
                    </form>
                @endif
            @endif
        </div>
    </x-ui.card>
</div>
