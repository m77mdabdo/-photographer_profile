/**
 * The focal-point picker, for the panel's media detail page.
 *
 * One line of its own, exactly like resources/js/studio/story.js: it imports
 * the module the custom admin has used since Task 14 and starts it. There is
 * one copy of the behaviour, so there is nothing to keep in step.
 *
 * Its own Vite entry rather than a Filament registered asset, because a
 * registered asset loads on every page of the panel and only this one has an
 * image to point at.
 */
import { initFocalPoint } from '../admin/focal-point.js';

initFocalPoint();

/*
 * Livewire replaces this page's DOM on every round trip — saving the point
 * re-renders the form — and the listeners above are bound to elements that no
 * longer exist afterwards. Re-initialising on `morphed` is what keeps the
 * handle draggable after a save.
 *
 * `morphed` and not `update`: `livewire:update` is a Livewire 2 event, and
 * listening for it is how the story builder went dead after a locale switch
 * in Task 32.
 */
document.addEventListener('livewire:initialized', () => {
    window.Livewire?.hook('morphed', () => initFocalPoint());
});
