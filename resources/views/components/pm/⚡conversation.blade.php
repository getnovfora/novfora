<?php
// SPDX-License-Identifier: Apache-2.0
use App\AntiSpam\ContentRejectedException;
use App\Messaging\ConversationService;
use App\Messaging\PmException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $conversationId;

    // Reply composer state
    public string $format = 'tiptap_json';

    public array $canonicalJson = ['type' => 'doc', 'content' => []];

    public string $markdownSource = '';

    // Invite control
    public string $inviteInput = '';

    public function mount(int $conversationId): void
    {
        $this->conversationId = $conversationId;
        abort_unless(auth()->user()?->can('view', $this->conversation()), 403);
        app(ConversationService::class)->markRead(auth()->user(), $this->conversation());
    }

    public function toggleFormat(): void
    {
        $this->format = $this->format === 'markdown' ? 'tiptap_json' : 'markdown';
    }

    public function reply(ConversationService $service): void
    {
        // Re-assert on every action — Livewire actions carry no route middleware.
        abort_unless(auth()->user()?->can('reply', $this->conversation()), 403);

        if ($this->bodyIsEmpty()) {
            $this->addError('body', 'Please write a message before sending.');

            return;
        }

        [$format, $canonical] = $this->body();

        try {
            $service->reply(auth()->user(), $this->conversation(), $format, $canonical);
        } catch (ContentRejectedException $e) {
            $this->addError('body', $e->getMessage());

            return;
        } catch (PmException $e) {
            $this->addError('body', $e->getMessage());

            return;
        }

        // Reset composer state after a successful reply.
        $this->canonicalJson = ['type' => 'doc', 'content' => []];
        $this->markdownSource = '';
    }

    public function reportMessage(int $messageId, ConversationService $service): void
    {
        // Re-assert view gate — reporter must be an active participant.
        abort_unless(auth()->user()?->can('view', $this->conversation()), 403);

        $message = Message::findOrFail($messageId);
        // Defence-in-depth: verify the message belongs to THIS conversation.
        abort_unless((int) $message->conversation_id === $this->conversationId, 403);

        $service->report(auth()->user(), $message);
    }

    public function leave(ConversationService $service): mixed
    {
        // Re-assert view (participant must be active to leave).
        abort_unless(auth()->user()?->can('view', $this->conversation()), 403);

        $service->leave(auth()->user(), $this->conversation());

        return $this->redirectRoute('pm.inbox');
    }

    public function addParticipant(ConversationService $service): void
    {
        // Re-assert invite gate as first statement.
        abort_unless(auth()->user()?->can('invite', $this->conversation()), 403);

        $username = trim($this->inviteInput);
        if ($username === '') {
            $this->addError('invite', 'Enter a username to add.');

            return;
        }

        $target = User::where('username', $username)->first();
        if (! $target instanceof User) {
            $this->addError('invite', "No user found with username \"{$username}\".");

            return;
        }

        try {
            $service->invite(auth()->user(), $this->conversation(), (int) $target->id);
        } catch (PmException $e) {
            $this->addError('invite', $e->getMessage());

            return;
        }

        $this->inviteInput = '';
    }

    /** Autocomplete: users whose username starts with the query (up to 8). */
    public function inviteSuggestions(): array
    {
        $q = trim($this->inviteInput);
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

    private function conversation(): Conversation
    {
        return Conversation::findOrFail($this->conversationId);
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

@php
    $conversation = \App\Models\Conversation::with(['messages.author', 'participantRows.user'])->findOrFail($conversationId);
    $authUser = auth()->user();
    $canInvite = $authUser?->can('invite', $conversation) ?? false;
    $participants = $conversation->participantRows->where('left_at', null)->map(fn ($r) => $r->user)->filter();
@endphp

<div dusk="pm-conversation" class="space-y-6">
    {{-- Conversation header --}}
    <div class="flex items-start justify-between gap-4">
        <div>
            @if ($conversation->subject)
                <h1 class="text-xl font-semibold text-ink">{{ $conversation->subject }}</h1>
            @endif
            <p class="mt-1 text-sm text-ink-muted">
                With:
                @foreach ($participants->reject(fn ($u) => $u && (int) $u->id === (int) auth()->id()) as $i => $p)
                    @if ($i > 0), @endif
                    <x-ui.user-name :user="$p" />
                @endforeach
            </p>
        </div>
        <x-ui.button type="button" variant="ghost" size="sm" wire:click="leave"
                     wire:confirm="Leave this conversation? You will stop receiving new messages."
                     dusk="pm-leave">
            Leave
        </x-ui.button>
    </div>

    {{-- Messages --}}
    <div class="space-y-4">
        @forelse ($conversation->messages as $message)
            @php
                /** @var \App\Models\Message $message */
                $authorName = $message->author?->display_name ?? $message->author?->username ?? '[Deleted]';
                $isDeleted = $message->author === null;
            @endphp
            <div dusk="pm-message-{{ $message->id }}" class="flex items-start gap-3">
                <x-ui.avatar :user="$message->author" :guest="$isDeleted" :name="$authorName" size="sm" class="mt-0.5 shrink-0" />
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline gap-2 mb-1">
                        <span class="text-sm font-medium text-ink">
                            @if ($isDeleted)
                                <span class="text-ink-subtle italic">[Deleted]</span>
                            @else
                                <x-ui.user-name :user="$message->author" />
                            @endif
                        </span>
                        <span class="text-xs text-ink-subtle nums" title="{{ $message->created_at->toDateTimeString() }}">
                            {{ $message->created_at->diffForHumans() }}
                        </span>
                    </div>
                    <div class="prose prose-sm max-w-none text-ink">
                        {!! $message->body_html_cache !!}
                    </div>
                    @if (! $isDeleted && (int) $message->user_id !== (int) auth()->id())
                        <div class="mt-1">
                            <button type="button"
                                    wire:click="reportMessage({{ $message->id }})"
                                    wire:confirm="Report this message to moderators?"
                                    dusk="pm-report-{{ $message->id }}"
                                    class="text-xs text-ink-subtle hover:text-danger hover:underline">
                                Report
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <x-ui.empty title="No messages yet">Be the first to write something.</x-ui.empty>
        @endforelse
    </div>

    {{-- Reply composer --}}
    <div class="border-t border-line pt-4">
        @error('body') <x-ui.alert variant="danger" class="mb-3">{{ $message }}</x-ui.alert> @enderror

        <form wire:submit="reply" class="space-y-3">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-ink">Reply</h2>
                <x-ui.button type="button" variant="ghost" size="sm" wire:click="toggleFormat" dusk="pm-format-toggle">
                    {{ $format === 'markdown' ? 'Switch to rich text' : 'Switch to Markdown' }}
                </x-ui.button>
            </div>

            @if ($format === 'markdown')
                <textarea wire:model="markdownSource" rows="5" placeholder="Write Markdown…"
                          dusk="pm-reply-body"
                          class="w-full px-3 py-2 rounded-md bg-surface-raised text-ink border border-line focus:border-accent font-mono text-sm"></textarea>
            @else
                <div dusk="pm-reply-editor">
                    <x-content-editor model="canonicalJson" :initial="$canonicalJson"
                                      :upload-url="route('attachments.store')" :mention-url="route('mentions')"
                                      placeholder="Write a reply…" />
                </div>
            @endif

            <x-ui.button type="submit" dusk="pm-reply-send">Send reply</x-ui.button>
        </form>
    </div>

    {{-- Invite control (only for participants who hold can_invite) --}}
    @if ($canInvite)
        <div class="border-t border-line pt-4 space-y-2">
            <h3 class="text-sm font-semibold text-ink">Add participant</h3>
            @error('invite') <x-ui.alert variant="danger">{{ $message }}</x-ui.alert> @enderror

            <div class="relative" x-data="{ open: false }">
                <input type="text"
                       wire:model.live.debounce.200ms="inviteInput"
                       wire:keydown.enter.prevent="addParticipant"
                       placeholder="Username…"
                       autocomplete="off"
                       dusk="pm-invite-input"
                       @focus="open = true"
                       @blur="setTimeout(() => open = false, 150)"
                       class="w-full min-h-10 px-3 rounded-md bg-surface-raised text-ink border border-line focus:border-accent text-sm">

                @php($inviteSuggestions = $this->inviteSuggestions())
                @if (! empty($inviteSuggestions))
                    <ul x-show="open"
                        class="absolute z-10 mt-1 w-full rounded-md border border-line bg-surface-raised shadow-md text-sm">
                        @foreach ($inviteSuggestions as $sug)
                            <li>
                                <button type="button"
                                        wire:click="$set('inviteInput', '{{ addslashes($sug['username']) }}')"
                                        @mousedown.prevent
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

            <x-ui.button type="button" wire:click="addParticipant" dusk="pm-invite-add">Add</x-ui.button>
        </div>
    @endif
</div>
