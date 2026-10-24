<div data-story-builder data-project-id="{{ $project->id }}" data-unsaved-guard data-dirty="0"
     data-strings="{{ json_encode(array_merge(__('studio.story.js'), ['alt_for_image' => __('studio.story.alt_for_image')]), JSON_UNESCAPED_UNICODE) }}">

    {{--
        WHICH LANGUAGE THIS EDITOR IS POINTED AT.

        The builder edits one locale at a time and merges what it saves into
        the others — see StoryBuilder::$editingLocale for why that is the
        honest answer rather than per-locale fields inside every block.
    --}}
    <div class="story-locale" role="group" aria-label="{{ __('studio.story.editing_language') }}">
        <span class="story-locale__label">{{ __('studio.story.editing') }}</span>

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
            {{ __('studio.story.locale_note') }}
        </p>
    </div>


    <header class="module-header">
        <h1 class="admin-heading">{{ __('studio.story.story') }}</h1>
        {{--
            The word follows the number in English and precedes it in Arabic,
            so it cannot be a count with a noun glued after it. trans_choice
            gives each language its own sentence — and Arabic's plural rules
            are not English's, which is the whole reason this is not
            "{{ $n }} blocks".
        --}}
        <p class="admin-note"><span data-block-count>{{ count($blocks) }}</span>
            {{ trans_choice('studio.story.block_count', count($blocks)) }}</p>

        <span class="badge" data-dirty-badge role="status" aria-live="polite" hidden>{{ __('studio.story.unsaved') }}</span>

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
                    title="{{ __('studio.story.undo_hint') }}">{{ __('studio.story.undo') }}</button>
            <button type="button" class="admin-link" data-redo disabled
                    title="{{ __('studio.story.redo_hint') }}">{{ __('studio.story.redo') }}</button>
        </span>

        <button type="button" class="admin-button" data-save
                wire:loading.attr="disabled" wire:target="saveStory">
            <span wire:loading.remove wire:target="saveStory">{{ __('studio.story.save') }}</span>
            <span wire:loading wire:target="saveStory">{{ __('studio.story.saving') }}</span>
        </button>
    </header>

    {{-- Announcements for screen readers: undo, drag refusals, saves. --}}
    <p class="sr-only" role="status" aria-live="polite" data-announce></p>

    {{--
        A restorable draft from this tab's session. Never restored silently:
        the editor is told it exists and chooses.
    --}}
    <div class="draft-prompt" data-draft-prompt hidden role="alert">
        <p>{!! str_replace(':count', '<span data-draft-count>0</span>', e(__('studio.story.draft_found'))) !!}</p>
        <button type="button" class="admin-button" data-draft-restore>{{ __('studio.story.restore_draft') }}</button>
        <button type="button" class="admin-link" data-draft-discard>{{ __('studio.story.discard_draft') }}</button>
    </div>

    {{-- Another tab holds an unsaved draft of this same story. --}}
    <p class="warning-strip" data-other-tab hidden role="status">
        {{ __('studio.story.other_tab') }}
    </p>

    {{-- The save was refused because the story moved underneath us. --}}
    @if ($stale)
        <div class="warning-strip warning-strip--stale" role="alert">
            <span>{{ __('studio.story.stale') }}</span>
            <button type="button" class="admin-button" wire:click="reloadStory">
                {{ __('studio.story.discard_reload') }}
            </button>
        </div>
    @endif

    {{-- A dropped connection, distinct from a story that needs fixing. --}}
    <p class="warning-strip warning-strip--network" data-network-error hidden role="alert">
        {{ __('studio.story.offline') }} <strong>{{ __('studio.story.offline_safe') }}</strong> {{ __('studio.story.offline_retry') }}
    </p>

    {{-- What is wrong, before Save is pressed. Clicking jumps to the first. --}}
    <button type="button" class="save-notice" data-save-notice hidden>
        <span data-invalid-count></span> — {{ __('studio.story.go_to_first') }}
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
            <h2 id="outline-heading" class="outline__heading">{{ __('studio.story.outline') }}</h2>
            <ol class="outline__list" data-outline></ol>

            <div class="outline__actions">
                <button type="button" class="admin-link" data-collapse-all>{{ __('studio.story.collapse_all') }}</button>
                <button type="button" class="admin-link" data-expand-all>{{ __('studio.story.expand_all') }}</button>
            </div>
        </nav>

        <div class="builder__main">
            <section class="type-picker" aria-labelledby="add-block-heading">
                <h2 id="add-block-heading" class="form-section__heading">{{ __('studio.story.add_block') }}</h2>

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
                {{ __('studio.story.empty_story') }}
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
                <button type="button" class="admin-button" data-save>{{ __('studio.story.save') }}</button>
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
