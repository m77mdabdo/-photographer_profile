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
import { initSidebar } from './admin/sidebar';
import { initUploader } from './admin/upload';

document.addEventListener('DOMContentLoaded', () => {
    initSidebar();
    initUploader();
    initFocalPoint();
});
