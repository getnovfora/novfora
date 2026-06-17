<?php
// SPDX-License-Identifier: Apache-2.0
use App\AntiSpam\ContentRejectedException;
use App\Messaging\ConversationService;
use App\Messaging\PmException;
use App\Models\User;
use App\Permissions\Scope;
use Livewire\Component;

new class extends Component
{
    /** @var list<string> resolved recipient usernames (chips) */
    public array $recipients = [];

    public string $recipientInput = '';

    public string $subject = '';

    public string $format = 'tiptap_json';

    public array $canonicalJson = ['type' => 'doc', 'content' => []];

    public string $markdownSource = '';

    public function mount(): void
    {
        // Own-send gate — no user-id param; always auth()->user().
        $user = auth()->user();
        abort_unless(
            $user instanceof User && $user->canDo('pm.send', Scope::global()),
            403,
        );
    }

    public function addRecipient(): void
    {
        $username = trim($this->recipientInput);
        $max = (int) config('novfora.pm.max_recipients', 10);

        if ($username === '') {
            return;
        }

        if (in_array($username, $this->recipients, true)) {
            $this->recipientInput = '';

            return;
        }

        if (count($this->recipients) >= $max) {
            $this->addError('recipients', "You can add at most {$max} recipients.");

            return;
        }

        if (! User::where('username', $username)->exists()) {
            $this->addError('recipients', "No user found with username \"{$username}\".");

            return;
        }

        $this->recipients[] = $username;
        $this->recipientInput = '';
    }

    public function removeRecipient(int $index): void
    {
        unset($this->recipients[$index]);
        $this->recipients = array_values($this->recipients);
    }

    /** Autocomplete: users whose username starts with the query (up to 8). */
    public function recipientSuggestions(): array
    {
        $q = trim($this->recipientInput);
        if ($q === '' || mb_strlen($q) < 2) {
            return [];
        }

        return User::where('username', 'like', addcslashes($q, '%_').'%')
            ->orderBy('username')
            ->limit(8)
            ->get(['id', 'username', 'display_name'])
            ->map(fn (User $u): array => [
                'id' => (int) $u->id,
                'username' => (string) $u->username,
                'display_name' => (string) ($u->display_name ?? $u->username),
            ])
            ->all();
    }

    public function toggleFormat(): void
    {
        $this->format = $this->format === 'markdown' ? 'tiptap_json' : 'markdown';
    }

    public function save(ConversationService $service): mixed
    {
        // Re-assert as first statement — Livewire actions carry no route middleware.
        $user = auth()->user();
        abort_unless(
            $user instanceof User && $user->canDo('pm.send', Scope::global()),
            403,
        );

        if ($this->recipients === []) {
            $this->addError('recipients', 'Add at least one recipient.');

            return null;
        }

        if ($this->bodyIsEmpty()) {
            $this->addError('body', 'Please write a message before sending.');

            return null;
        }

        // Resolve usernames → user ids.
        $recipientIds = User::whereIn('username', $this->recipients)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($recipientIds === []) {
            $this->addError('recipients', 'None of the entered usernames could be resolved.');

            return null;
        }

        $subject = trim($this->subject) !== '' ? trim($this->subject) : null;
        [$format, $canonical] = $this->body();

        try {
            $conversation = $service->startConversation($user, $recipientIds, $subject, $format, $canonical);
        } catch (ContentRejectedException $e) {
            $this->addError('body', $e->getMessage());

            return null;
        } catch (PmException $e) {
            $this->addError('recipients', $e->getMessage());

            return null;
        }

        return $this->redirectRoute('pm.show', $conversation->id, navigate: true);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function body(): array
    {
        return $this->format === 'markdown'
            ? ['markdown', ['source' => $this->markdownSource]]
            : ['tiptap_json', $this->canonicalJson];
    }

    private function bodyIsEmpty(): bool
    {
        return $this->format === 'markdown'
            ? trim($this->markdownSource) === ''
            : empty($this->canonicalJson['content']);
    }
};
?>

<form wire:submit="save" dusk="pm-new" class="space-y-4">
    {{-- Recipient chips --}}
    <div class="space-y-1.5">
        <label for="pm-recipient" class="block text-sm font-medium text-ink">To</label>
        @error('recipients') <p class="text-xs text-danger">{{ $message }}</p> @enderror

        @if (! empty($recipients))
            <div class="flex flex-wrap gap-1.5 mb-1.5">
                @foreach ($recipients as $i => $recipient)
                    <span class="inline-flex items-center gap-1 rounded-full border border-line bg-surface-sunken px-2.5 py-0.5 text-xs font-medium text-ink-muted">
                        {{ $recipient }}
                        <button type="button" wire:click="removeRecipient({{ $i }})"
                                class="ml-0.5 rounded-full hover:text-danger focus:outline-none"
                                aria-label="Remove {{ $recipient }}">×</button>
                    </span>
                @endforeach
            </div>
        @endif

        <div class="relative" x-data="{ open: false }">
            <input type="text"
                   id="pm-recipient"
                   wire:model.live.debounce.200ms="recipientInput"
                   wire:keydown.enter.prevent="addRecipient"
                   wire:keydown.comma.prevent="addRecipient"
                   placeholder="Type a username and press Enter…"
                   autocomplete="off"
                   dusk="pm-recipient-input"
                   @focus="open = true"
                   @blur="setTimeout(() => open = false, 150)"
                   class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink border border-line focus:border-accent text-sm">

            @php($recipientSuggestions = $this->recipientSuggestions())
            @if (! empty($recipientSuggestions))
                <ul x-show="open"
                    class="absolute z-10 mt-1 w-full rounded-md border border-line bg-surface-raised shadow-md text-sm">
                    @foreach ($recipientSuggestions as $sug)
                        <li>
                            <button type="button"
                                    wire:click="$set('recipientInput', '{{ addslashes($sug['username']) }}')"
                                    @mousedown.prevent
                                    @click="$wire.call('addRecipient'); open = false"
                                    class="block w-full px-3 py-2 text-left hover:bg-surface-sunken text-ink">
                                {{ $sug['username'] }}
                                @if ($sug['display_name'] !== $sug['username'])
                                    <span class="text-xs text-ink-subtle"> · {{ $sug['display_name'] }}</span>
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        <x-ui.button type="button" variant="subtle" size="sm" wire:click="addRecipient" dusk="pm-recipient-add" class="mt-1.5">Add recipient</x-ui.button>
        <p class="text-xs text-ink-subtle">Max {{ config('novfora.pm.max_recipients', 10) }} recipients.</p>
    </div>

    {{-- Subject (optional) --}}
    <div class="space-y-1.5">
        <label for="pm-subject" class="block text-sm font-medium text-ink">
            Subject <span class="text-ink-subtle font-normal">(optional)</span>
        </label>
        <input id="pm-subject" type="text" wire:model.blur="subject" maxlength="150" dusk="pm-subject"
               class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink border border-line focus:border-accent text-sm">
    </div>

    {{-- Body composer --}}
    <div class="space-y-1.5">
        <div class="flex items-center justify-between gap-2">
            <label class="block text-sm font-medium text-ink">Message</label>
            <x-ui.button type="button" variant="ghost" size="sm" wire:click="toggleFormat" dusk="pm-format-toggle">
                {{ $format === 'markdown' ? 'Switch to rich text' : 'Switch to Markdown' }}
            </x-ui.button>
        </div>

        @if ($format === 'markdown')
            <textarea wire:model="markdownSource" rows="8" placeholder="Write Markdown…"
                      dusk="pm-body"
                      class="w-full px-3 py-2 rounded-md bg-surface-raised text-ink border border-line focus:border-accent font-mono text-sm"></textarea>
        @else
            <div dusk="pm-body-editor">
                <x-content-editor model="canonicalJson" :initial="$canonicalJson"
                                  :upload-url="route('attachments.store')" :mention-url="route('mentions')"
                                  placeholder="Write your message…" />
            </div>
        @endif
        @error('body') <p class="text-xs text-danger">{{ $message }}</p> @enderror
    </div>

    <x-ui.button type="submit" size="lg" dusk="pm-create">Send message</x-ui.button>
</form>
