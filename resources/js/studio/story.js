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
 * TWO EVENTS, FOR THE SAME REASON THE OLD ADMIN LISTENS TO TWO.
 *
 * The builder binds itself to the DOM, and Livewire replaces DOM on every
 * re-render — switching the editing locale re-renders every block. The
 * initialiser guards against double-binding with a data attribute, so calling
 * it again is cheap and calling it too few times leaves dead buttons.
 *
 * `livewire:navigated` covers Filament's SPA navigation; `livewire:update`
 * covers a re-render in place.
 */

import { initStoryBuilder } from '../admin/story-builder';

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

document.addEventListener('livewire:navigated', boot);
document.addEventListener('livewire:update', boot);
