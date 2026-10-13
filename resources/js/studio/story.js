/*
 * The story builder's behaviour, for the Filament panel.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * A SEPARATE ENTRY, NOT A SECOND COPY.
 *
 * This imports the module the custom admin has used since Task 17 and calls
 * the same initialiser. There is one implementation of undo, crash recovery,
 * local-state drag and keyboard parity; this file is a different way in, not
 * a different behaviour.
 *
 * WHY AN ENTRY OF ITS OWN
 *
 * The panel could not import resources/js/admin.js: that also boots the
 * sidebar, the uploader, the focal-point control and the reorder bindings,
 * all of which expect the custom admin's markup and none of which exist here.
 * And it must not go into the public bundle, which is byte-for-byte pinned.
 *
 * So: its own Vite entry, loaded by @vite in the story page's view alone.
 * ───────────────────────────────────────────────────────────────────────────
 *
 * ───────────────────────────────────────────────────────────────────────────
 * REBINDING AFTER A RE-RENDER, AND THE DEAD EVENT THAT USED TO DO IT.
 *
 * The builder binds itself to the DOM once and marks the root with
 * data-builder-bound. Livewire REPLACES that DOM on a re-render — switching
 * the editing language re-renders every block — and the flag goes with it, so
 * the buttons are inert until something binds again.
 *
 * The first version of this file listened for `livewire:update`, copied from
 * admin.js. That event does not exist. Livewire 4 dispatches exactly six DOM
 * events — init, initializing, initialized, navigate, navigating, navigated —
 * and `livewire:update` was a LIVEWIRE 2 name. Measured in a browser: after
 * clicking the Arabic tab, data-builder-bound came back `undefined` and undo,
 * drag and save were all dead until a reload.
 *
 * (The same dead listener is still in resources/js/admin.js, where it has
 * been silently doing nothing to initReorder() since the Livewire 3 upgrade.
 * Reported rather than fixed: that file is deleted at step 10.)
 *
 * `morphed` is the real hook — Livewire's own name for "this component's DOM
 * has just been replaced". Registered inside `livewire:init`, because
 * Livewire must exist before a hook can be attached to it.
 * ───────────────────────────────────────────────────────────────────────────
 */

import { initStoryBuilder } from './story-builder';

function boot() {
    initStoryBuilder();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    // Filament loads scripts at the end of the body, so the DOM is often
    // already parsed by the time this runs.
    boot();
}

// Filament's SPA navigation.
document.addEventListener('livewire:navigated', boot);

// A re-render in place: the locale switch, a validation round trip, a save.
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('morphed', () => boot());
});
