<div data-story-builder data-project-id="{{ $project->id }}" data-unsaved-guard data-dirty="0">

    {{--
        WHICH LANGUAGE THIS EDITOR IS POINTED AT.

        The builder edits one locale at a time and merges what it saves into
        the others — see StoryBuilder::$editingLocale for why that is the
        honest answer rather than per-locale fields inside every block.
    --}}
    <div class="story-locale" role="group" aria-label="Editing language">
        <span class="story-locale__label">Editing</span>

        @foreach ($this->locales() as $code)
            <button type="button"
                    class="story-locale__option"
                    wire:click="switchLocale('{{ $code }}')"
                    @if ($code === $editingLocale) aria-current="true" @endif
                    lang="{{ \App\Support\Locale::html($code) }}">
                {{ \App\Support\Locale::native($code) }}
            </button>
        @endforeach

        <p class="story-locale__note">
            Fields show this language only. Saving leaves the other languages untouched.
        </p>
    </div>


    <header class="module-header">
        <h1 class="admin-heading">Story</h1>
        <p class="admin-note"><span data-block-count>{{ count($blocks) }}</span> blocks</p>

        <span class="badge" data-dirty-badge role="status" aria-live="polite" hidden>Unsaved changes</span>

        {{--
            Preview, through the panel's own route. This was a ternary on
            $embedded while two admins hosted this component; one of them is
            gone, so the branch is too. App\Support\ProjectPreview is still
            the single implementation behind both.
        --}}
        <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('preview', ['record' => $project]) }}"
           class="admin-link" target="_blank" rel="noopener">{{ __('studio.story.preview') }}</a>

        {{-- Visible undo: a keyboard-only affordance is not discoverable. --}}
        <span class="undo-group">
            <button type="button" class="admin-link" data-undo disabled
                    title="Undo (Ctrl/Cmd+Z)">Undo</button>
            <button type="button" class="admin-link" data-redo disabled
                    title="Redo (Ctrl/Cmd+Shift+Z)">Redo</button>
        </span>

        <button type="button" class="admin-button" data-save
                wire:loading.attr="disabled" wire:target="saveStory">
            <span wire:loading.remove wire:target="saveStory">Save story</span>
            <span wire:loading wire:target="saveStory">Saving…</span>
        </button>
    </header>

    {{-- Announcements for screen readers: undo, drag refusals, saves. --}}
    <p class="sr-only" role="status" aria-live="polite" data-announce></p>

    {{--
        A restorable draft from this tab's session. Never restored silently:
        the editor is told it exists and chooses.
    --}}
    <div class="draft-prompt" data-draft-prompt hidden role="alert">
        <p>
            An unsaved draft of this story from this session was found
            (<span data-draft-count>0</span> blocks). It has not been saved to the server.
        </p>
        <button type="button" class="admin-button" data-draft-restore>Restore the draft</button>
        <button type="button" class="admin-link" data-draft-discard>Discard it</button>
    </div>

    {{-- Another tab holds an unsaved draft of this same story. --}}
    <p class="warning-strip" data-other-tab hidden role="status">
        This story is open in another tab. Whichever saves last will be refused rather than
        overwrite the other — reload there, or finish here first.
    </p>

    {{-- The save was refused because the story moved underneath us. --}}
    @if ($stale)
        <div class="warning-strip warning-strip--stale" role="alert">
            <span>This story changed elsewhere after you opened it. Nothing has been overwritten.</span>
            <button type="button" class="admin-button" wire:click="reloadStory">
                Discard my changes and reload
            </button>
        </div>
    @endif

    {{-- A dropped connection, distinct from a story that needs fixing. --}}
    <p class="warning-strip warning-strip--network" data-network-error hidden role="alert">
        Could not reach the server. <strong>Your work is safe — nothing was lost.</strong> Try again.
    </p>

    {{-- What is wrong, before Save is pressed. Clicking jumps to the first. --}}
    <button type="button" class="save-notice" data-save-notice hidden>
        <span data-invalid-count></span> — go to the first one
    </button>

    @if ($message !== '')
        <p class="admin-flash__message" role="status" aria-live="polite">{{ $message }}</p>
    @endif

    @error('story')
        <p class="admin-flash__message admin-flash__message--error" role="alert">{{ $message }}</p>
    @enderror

    <div class="builder">
        {{-- D2: a compact outline, rebuilt locally on every structural change. --}}
        <nav class="outline" aria-labelledby="outline-heading">
            <h2 id="outline-heading" class="outline__heading">Outline</h2>
            <ol class="outline__list" data-outline></ol>

            <div class="outline__actions">
                <button type="button" class="admin-link" data-collapse-all>Collapse all</button>
                <button type="button" class="admin-link" data-expand-all>Expand all</button>
            </div>
        </nav>

        <div class="builder__main">
            <section class="type-picker" aria-labelledby="add-block-heading">
                <h2 id="add-block-heading" class="form-section__heading">Add a block</h2>

                @foreach ($types as $group => $cases)
                    <div class="type-picker__group">
                        <p class="type-picker__label">{{ $group }}</p>
                        <ul class="type-picker__list" role="list">
                            @foreach ($cases as $case)
                                <li>
                                    <button type="button" class="admin-button admin-button--quiet"
                                            data-add-block="{{ $case->value }}">
                                        {{ $case->label() }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </section>

            <p class="empty-state" data-empty @if ($blocks !== []) hidden @endif>
                This story is empty. Add a block above — blocks stack down the page in the order you arrange them here.
            </p>

            <ul class="block-list" role="list" data-block-list>
                @foreach ($blocks as $index => $block)
                    @php($type = \App\Enums\BlockType::from($block['type']))

                    @if (isset($blockErrors[$index]))
                        {{-- Errors are attached to their block below, by key. --}}
                    @endif

                    @include('livewire.studio.blocks.card', ['block' => $block, 'type' => $type, 'mediaById' => $mediaById])
                @endforeach
            </ul>

            <div class="form-actions">
                <button type="button" class="admin-button" data-save>Save story</button>
            </div>
        </div>
    </div>

    {{--
        One hidden template per block type. Adding a block clones the matching
        template — no markup is duplicated in JavaScript, and a new type needs
        no JS change at all.
    --}}
    @foreach ($allTypes as $case)
        <template data-block-template="{{ $case->value }}">
            @include('livewire.studio.blocks.card', [
                'block' => ['key' => '__KEY__', 'id' => '', 'type' => $case->value, 'data' => [], 'media' => []],
                'type' => $case,
                'mediaById' => collect(),
            ])
        </template>
    @endforeach

    {{-- Server-side validation problems, handed to the browser to place. --}}
    <script type="application/json" data-block-errors>@json($blockErrors)</script>

    <livewire:studio.media-picker />
</div>
