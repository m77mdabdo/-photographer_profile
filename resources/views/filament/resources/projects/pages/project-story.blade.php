<x-filament-panels::page>
    {{--
        ═══════════════════════════════════════════════════════════════════════
        THE TWO SAVE BUTTONS, AND WHY THERE IS ONLY ONE ON THIS SCREEN.

        Every other resource page in this panel has Filament's form save at the
        bottom. The story builder has its own, because it writes the whole
        story in a single request of its own design — that is the feature, not
        an accident of mounting.

        Two save buttons on one screen reads as a bug, so this page has no form
        and no save action: the only save control here is the story's. The
        notice below says which is which in one sentence, and the link back to
        the project's details is a LINK rather than a second submit, because
        following it is navigation and not a save.
        ═══════════════════════════════════════════════════════════════════════
    --}}
    <div class="fi-section rounded-none border border-gray-200 p-4 text-sm dark:border-white/10">
        <p class="font-medium text-gray-950 dark:text-white">
            {{ __('studio.story.scope_heading') }}
        </p>

        <p class="mt-1 text-gray-600 dark:text-gray-400">
            {{ __('studio.story.scope_body') }}
        </p>

        <a
            class="fi-link mt-2 inline-flex items-center gap-1 text-sm font-medium text-primary-600 dark:text-primary-400"
            href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('edit', ['record' => $this->getRecord()]) }}"
        >
            {{ __('studio.story.back_to_details') }}
        </a>
    </div>

    {{--
        THE COMPONENT, UNCHANGED.

        Addressed by its class rather than by a Livewire alias: the alias is a
        convention derived from the namespace, and naming the class means this
        breaks loudly if the component is ever moved rather than rendering an
        empty div.

        A :key is required — without one, Livewire reuses the component
        instance across a navigation between two projects' story pages and the
        second one shows the first one's blocks.
    --}}
    @livewire(
        \App\Livewire\Admin\StoryBuilder::class,
        ['project' => $this->getRecord(), 'embedded' => true],
        key('story-builder-'.$this->getRecord()->getKey())
    )

    {{--
        ═══════════════════════════════════════════════════════════════════════
        THE BUILDER'S JAVASCRIPT, ON THIS PAGE AND NO OTHER.

        The panel's assets are Filament's, not Vite's public build — which is
        exactly why this is an @vite call for a SEPARATE ENTRY rather than
        anything touching resources/js/admin.js.

        resources/js/studio/story.js imports the existing module and calls
        initStoryBuilder(). It is its own Vite entry, so:
          · it is a separate chunk, loaded only by this page
          · the public bundle is untouched — verified by checksum
          · admin.js keeps importing the same module, so there is one copy of
            the 993 lines and no second behaviour to keep in step

        @vite is used rather than FilamentAsset::register(), because a
        registered Filament asset loads on EVERY page of the panel. This page
        is the only one with a story on it.
        ═══════════════════════════════════════════════════════════════════════
    --}}
    @vite('resources/js/studio/story.js')
</x-filament-panels::page>
