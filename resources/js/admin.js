/*
 * Admin entry point.
 *
 * Shares no module with the public bundle: a module imported by both would be
 * hoisted into a chunk both entries load, which is what the split prevents.
 *
 * Status polling used to live here. It is now a wire:poll on a wrapper that
 * only exists while something is encoding, so there is no interval to manage.
 */

import { initFocalPoint } from './admin/focal-point';
import { initReorder } from './admin/reorder';
import { initSidebar } from './admin/sidebar';
import { initUnsavedGuard } from './admin/unsaved-guard';
import { initUploader } from './admin/upload';

function boot() {
    initSidebar();
    initUploader();
    initFocalPoint();
    initReorder();
    initUnsavedGuard();
}

document.addEventListener('DOMContentLoaded', boot);

// Livewire replaces DOM on every re-render, so newly rendered rows need
// binding again. initReorder() guards against double-binding.
document.addEventListener('livewire:navigated', boot);
document.addEventListener('livewire:update', () => initReorder());
