<?php
// SPDX-License-Identifier: Apache-2.0
use App\AntiSpam\ContentRejectedException;
use App\AntiSpam\PostRateLimiter;
use App\Forum\Concerns\ManagesDrafts;
use App\Forum\PollService;
use App\Forum\PostService;
use App\Forum\TagException;
use App\Forum\TagService;
use App\Models\Forum;
use App\Models\Prefix;
use App\Models\Tag;
use App\Models\User;
use App\Permissions\Scope;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use ManagesDrafts;

    #[Locked]
    public int $forumId;

    public string $title = '';

    public string $format = 'tiptap_json';

    public array $canonicalJson = ['type' => 'doc', 'content' => []];

    public string $markdownSource = '';

    // Prefix selector (P2-M1). The available prefixes are loaded once in mount; null means no prefix.
    public ?int $prefixId = null;

    // Poll composition (P2-M1). The block is only offered to authors who hold poll.create at this forum
    // (resolved once in mount); $canCreatePoll is #[Locked] so the client cannot toggle the gate on.
    #[Locked]
    public bool $canCreatePoll = false;

    // Tag composition (P2-M1). $canApplyTags gates visibility of the tag input; $canCreateTags gates
    // minting. Both are #[Locked] so the client cannot toggle the permission flags.
    #[Locked]
    public bool $canApplyTags = false;

    #[Locked]
    public bool $canCreateTags = false;

    /** @var list<string> tag names typed/selected by the user */
    public array $tags = [];

    public string $tagInput = '';

    public bool $addPoll = false;

    public string $pollQuestion = '';

    /** @var list<string> */
    public array $pollOptions = ['', ''];

    public bool $pollMultiple = false;

    public ?int $pollMaxChoices = null;

    public function mount(int $forumId): void
    {
        $this->forumId = $forumId;
        $this->ensureCanCreate();
        $this->canCreatePoll = auth()->user()?->canDo('poll.create', $this->forum()->permissionScope()) ?? false;
        $this->canApplyTags = auth()->user()?->canDo('tag.apply', $this->forum()->permissionScope()) ?? false;
        $this->canCreateTags = auth()->user()?->canDo('tag.create', Scope::global()) ?? false;
        $this->restoreDraft(); // restore any autosaved new-topic draft for this forum (own-only)
    }

    /** @return array{0:string,1:int} */
    protected function draftContext(): array
    {
        return ['topic', $this->forumId];
    }

    public function addPollOption(): void
    {
        if (count($this->pollOptions) < 20) {
            $this->pollOptions[] = '';
        }
    }

    public function removePollOption(int $index): void
    {
        unset($this->pollOptions[$index]);
        $this->pollOptions = array_values($this->pollOptions);
        if ($this->pollOptions === []) {
            $this->pollOptions = ['', ''];
        }
    }

    /** Add a tag name from the text input (called on Enter / comma). */
    public function addTag(): void
    {
        $name = trim($this->tagInput);
        if ($name !== '' && ! in_array($name, $this->tags, true) && count($this->tags) < 10) {
            $this->tags[] = $name;
        }
        $this->tagInput = '';
    }

    public function removeTag(int $index): void
    {
        unset($this->tags[$index]);
        $this->tags = array_values($this->tags);
    }

    /**
     * Autocomplete suggestions: tags whose name contains the query (case-insensitive), up to 8.
     *
     * @return list<array{id:int,name:string,slug:string}>
     */
    public function tagSuggestions(): array
    {
        $q = trim($this->tagInput);
        if ($q === '' || mb_strlen($q) < 2) {
            return [];
        }

        return Tag::where('name', 'like', '%'.addcslashes($q, '%_').'%')
            ->orderByDesc('usage_count')
            ->limit(8)
            ->get(['id', 'name', 'slug'])
            ->map(fn (Tag $t): array => ['id' => (int) $t->id, 'name' => (string) $t->name, 'slug' => (string) $t->slug])
            ->all();
    }

    public function toggleFormat(): void
    {
        $this->format = $this->format === 'markdown' ? 'tiptap_json' : 'markdown';
    }

    public function save(PostService $service, PostRateLimiter $limiter, PollService $polls, TagService $tags)
    {
        $this->ensureCanCreate();
        $this->validate(['title' => ['required', 'string', 'min:3', 'max:160']]);

        if ($this->bodyIsEmpty()) {
            $this->addError('body', 'Please write something before posting.');

            return null;
        }

        // Validate the poll BEFORE creating the topic so a structural error never leaves a poll-less topic.
        $pollOptions = [];
        if ($this->addPoll && $this->canCreatePoll) {
            $pollOptions = array_values(array_unique(array_filter(array_map('trim', $this->pollOptions), fn ($o) => $o !== '')));
            if (trim($this->pollQuestion) === '') {
                $this->addError('poll', 'Give your poll a question, or turn the poll off.');

                return null;
            }
            if (count($pollOptions) < 2) {
                $this->addError('poll', 'A poll needs at least two distinct options.');

                return null;
            }
        }

        if (! $limiter->attempt(auth()->user())) {
            $this->addError('body', 'You are posting too quickly — please wait a moment and try again.');

            return null;
        }

        [$format, $canonical] = $this->body();

        try {
            $topic = $service->createTopic(auth()->user(), $this->forum(), $this->title, $format, $canonical, $this->prefixId);
        } catch (ContentRejectedException $e) {
            $this->addError('body', $e->getMessage());

            return null;
        }

        if ($pollOptions !== []) {
            // Re-assert poll.create server-side at the action (not only via the #[Locked] $canCreatePoll
            // mount flag) — the caller is where authorisation lives (PollService does no HTTP auth, matching
            // PostService). A moderator-rejected poll (spam in the option text) is the only residual failure;
            // the topic is already valid, so we attach what we can and proceed rather than failing the post.
            abort_unless(auth()->user()?->canDo('poll.create', $this->forum()->permissionScope()) ?? false, 403);
            try {
                $polls->createPoll(auth()->user(), $topic, $this->pollQuestion, $pollOptions, $this->pollMultiple, $this->pollMaxChoices);
            } catch (ContentRejectedException|InvalidArgumentException) {
                // poll dropped; topic stands
            }
        }

        // Tags — re-assert both perms server-side (never trust the #[Locked] mount flags alone for a write).
        // Split submitted names into EXISTING (by slug) vs NEW (not yet in the namespace):
        //   • EXISTING: applied if the user holds tag.apply at this forum.
        //   • NEW: minted + applied only if the user ALSO holds tag.create at global scope (hard NEVER for TL0).
        //   • Unknown names from a user without tag.create are silently dropped — not an error.
        if ($this->tags !== [] && (auth()->user()?->canDo('tag.apply', $this->forum()->permissionScope()) ?? false)) {
            $canCreate = auth()->user()?->canDo('tag.create', Scope::global()) ?? false;

            $existingBySlug = $tags->existing($this->tags)->keyBy('slug');
            $tagIds = [];

            foreach ($this->tags as $name) {
                $slug = $tags->slugFor($tags->normalizeName($name));
                if ($existingBySlug->has($slug)) {
                    $tagIds[] = (int) $existingBySlug->get($slug)->id;
                } elseif ($canCreate) {
                    try {
                        $tagIds[] = (int) $tags->create($name)->id;
                    } catch (TagException) {
                        // malformed name — skip
                    }
                }
                // else: new name + no mint permission → silently dropped
            }

            if ($tagIds !== []) {
                $tags->syncTopicTags($topic, array_values(array_unique($tagIds)));
            }
        }

        $this->discardDraft(); // published — drop the autosaved draft

        return $this->redirectRoute('topics.show', $topic, navigate: true);
    }

    /** Available prefixes for this forum: global + forum-specific, ordered by position then label.
     *  @return list<array{id:int,label:string,color_token:string|null}> */
    public function prefixOptions(): array
    {
        return Prefix::query()
            ->where(function ($q) {
                $q->whereNull('forum_id')->orWhere('forum_id', $this->forumId);
            })
            ->orderBy('position')
            ->orderBy('label')
            ->get()
            ->map(fn (Prefix $p): array => [
                'id' => (int) $p->id,
                'label' => (string) $p->label,
                'color_token' => $p->color_token,
            ])->all();
    }

    private function ensureCanCreate(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canDo('topic.create', $this->forum()->permissionScope()), 403);
    }

    private function forum(): Forum
    {
        return Forum::findOrFail($this->forumId);
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

<form wire:submit="save" class="space-y-4">
    <div class="space-y-1.5">
        <label for="ct-title" class="block text-sm font-medium text-ink">Title</label>
        {{-- .blur syncs on blur (not deferred-until-submit): a value typed after a validation-error morph then
             reliably reaches the server on resubmit. No autofocus — it re-fires on every morph and fights the
             editor below for focus. --}}
        <input id="ct-title" type="text" wire:model.blur="title" maxlength="160" dusk="topic-title"
               class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink border border-line focus:border-accent text-lg">
        @error('title') <p class="text-xs text-danger">{{ $message }}</p> @enderror
    </div>

    @php($prefixOptions = $this->prefixOptions())
    @if (! empty($prefixOptions))
        <div class="space-y-1.5">
            <label for="ct-prefix" class="block text-sm font-medium text-ink">Prefix <span class="text-ink-subtle font-normal">(optional)</span></label>
            <select id="ct-prefix" wire:model="prefixId" dusk="topic-prefix"
                    class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink border border-line focus:border-accent text-sm">
                <option value="">— No prefix —</option>
                @foreach ($prefixOptions as $opt)
                    <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                @endforeach
            </select>
        </div>
    @endif

    <div class="flex items-center justify-between gap-2">
        <span class="text-sm text-ink-muted">Body</span>
        <x-ui.button type="button" variant="ghost" size="sm" wire:click="toggleFormat">
            {{ $format === 'markdown' ? 'Switch to rich text' : 'Switch to Markdown' }}
        </x-ui.button>
    </div>

    @if ($format === 'markdown')
        <textarea wire:model="markdownSource" rows="12" placeholder="Write Markdown…"
                  class="w-full px-3 py-2 rounded-md bg-surface-raised text-ink border border-line focus:border-accent font-mono text-sm"></textarea>
    @else
        <x-content-editor model="canonicalJson" :initial="$canonicalJson" draft
                          :upload-url="route('attachments.store')" :mention-url="route('mentions')" />
    @endif
    @error('body') <p class="text-xs text-danger">{{ $message }}</p> @enderror

    @if ($canCreatePoll)
        <div class="space-y-3 rounded-md border border-line p-3" dusk="create-poll-block">
            <label class="flex items-center gap-2 text-sm font-medium text-ink">
                <input type="checkbox" wire:model.live="addPoll" dusk="create-poll-toggle">
                Add a poll
            </label>

            @if ($addPoll)
                <div class="space-y-3">
                    <x-ui.input label="Poll question" name="pollQuestion" wire:model="pollQuestion" maxlength="255" dusk="poll-question" />
                    @error('poll') <p class="text-xs text-danger">{{ $message }}</p> @enderror

                    <div class="space-y-2">
                        <span class="block text-sm font-medium text-ink">Options</span>
                        @foreach ($pollOptions as $i => $option)
                            <div class="flex items-center gap-2">
                                <input type="text" wire:model="pollOptions.{{ $i }}" maxlength="255" placeholder="Option {{ $i + 1 }}"
                                       dusk="poll-option-{{ $i }}"
                                       class="min-h-10 w-full rounded-md border border-line bg-surface-raised px-3 text-sm text-ink focus:border-accent">
                                @if (count($pollOptions) > 2)
                                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="removePollOption({{ $i }})" dusk="poll-remove-{{ $i }}">Remove</x-ui.button>
                                @endif
                            </div>
                        @endforeach
                        <x-ui.button type="button" variant="subtle" size="sm" wire:click="addPollOption" dusk="poll-add-option">Add option</x-ui.button>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-ink">
                        <input type="checkbox" wire:model.live="pollMultiple" dusk="poll-multiple">
                        Allow choosing multiple options
                    </label>
                    @if ($pollMultiple)
                        <x-ui.input type="number" label="Maximum choices (optional)" name="pollMaxChoices" wire:model="pollMaxChoices" min="1" dusk="poll-max-choices" />
                    @endif
                </div>
            @endif
        </div>
    @endif

    @if ($canApplyTags)
        <div class="space-y-2" dusk="create-tags-block">
            <label for="create-tag-input" class="block text-sm font-medium text-ink">
                Tags <span class="text-ink-subtle font-normal">(optional)</span>
            </label>

            {{-- Current tag chips --}}
            @if (! empty($tags))
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($tags as $i => $tagName)
                        <span class="inline-flex items-center gap-1 rounded-full border border-line bg-surface-sunken px-2.5 py-0.5 text-xs font-medium text-ink-muted"
                              dusk="tag-chip-{{ $i }}">
                            {{ $tagName }}
                            <button type="button" wire:click="removeTag({{ $i }})"
                                    class="ml-0.5 rounded-full hover:text-danger focus:outline-none"
                                    aria-label="Remove tag {{ $tagName }}"
                                    dusk="remove-tag-{{ $i }}">×</button>
                        </span>
                    @endforeach
                </div>
            @endif

            {{-- Tag autocomplete input --}}
            <div class="relative" x-data="{ open: false }">
                <input type="text"
                       id="create-tag-input"
                       wire:model.live.debounce.200ms="tagInput"
                       wire:keydown.enter.prevent="addTag"
                       wire:keydown.comma.prevent="addTag"
                       placeholder="Type a tag and press Enter…"
                       autocomplete="off"
                       dusk="tag-input"
                       @focus="open = true"
                       @blur="setTimeout(() => open = false, 150)"
                       class="w-full min-h-10 px-3 rounded-md bg-surface-raised text-ink border border-line focus:border-accent text-sm">

                @php($suggestions = $this->tagSuggestions())
                @if (! empty($suggestions))
                    <ul x-show="open"
                        class="absolute z-10 mt-1 w-full rounded-md border border-line bg-surface-raised shadow-md text-sm"
                        dusk="tag-suggestions">
                        @foreach ($suggestions as $sug)
                            <li>
                                <button type="button"
                                        wire:click="$set('tagInput', '{{ addslashes($sug['name']) }}')"
                                        @mousedown.prevent
                                        @click="$wire.call('addTag'); open = false"
                                        dusk="tag-suggestion-{{ $loop->index }}"
                                        class="block w-full px-3 py-2 text-left hover:bg-surface-sunken text-ink">
                                    {{ $sug['name'] }}
                                    <span class="ml-1 text-xs text-ink-subtle nums">{{ $sug['name'] !== '' ? '' : '' }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($canCreateTags)
                <p class="text-xs text-ink-subtle">Enter existing or new tags. New tags will be created in the global namespace.</p>
            @else
                <p class="text-xs text-ink-subtle">You can apply existing tags. Typing an unknown tag name will have no effect.</p>
            @endif
        </div>
    @endif

    <div class="flex items-center gap-3">
        <x-ui.button type="submit" size="lg">Post topic</x-ui.button>
        @if ($draftRestored)
            <span class="text-xs text-ink-subtle" dusk="topic-draft-restored">
                Draft restored ·
                <button type="button" wire:click="discardDraft" class="text-accent hover:underline" dusk="topic-draft-discard">Discard</button>
            </span>
        @endif
    </div>
</form>
