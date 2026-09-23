<div data-story-builder data-unsaved-guard data-dirty="0">
    <p class="breadcrumb">
        <a href="{{ route('admin.projects.edit', $project) }}">← {{ $project->title }}</a>
    </p>

    <header class="module-header">
        <h1 class="admin-heading">Story</h1>
        <p class="admin-note"><span data-block-count>{{ count($blocks) }}</span> blocks</p>

        <span class="badge" data-dirty-badge role="status" aria-live="polite" hidden>Unsaved changes</span>

        <a href="{{ route('admin.projects.preview', $project) }}" class="admin-link" target="_blank" rel="noopener">Preview</a>

        <button type="button" class="admin-button" data-save
                wire:loading.attr="disabled" wire:target="saveStory">
            <span wire:loading.remove wire:target="saveStory">Save story</span>
            <span wire:loading wire:target="saveStory">Saving…</span>
        </button>
    </header>

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

                    @include('livewire.admin.blocks.card', ['block' => $block, 'type' => $type, 'mediaById' => $mediaById])
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
            @include('livewire.admin.blocks.card', [
                'block' => ['key' => '__KEY__', 'id' => '', 'type' => $case->value, 'data' => [], 'media' => []],
                'type' => $case,
                'mediaById' => collect(),
            ])
        </template>
    @endforeach

    {{-- Server-side validation problems, handed to the browser to place. --}}
    <script type="application/json" data-block-errors>@json($blockErrors)</script>

    <livewire:admin.media-picker />
</div>
