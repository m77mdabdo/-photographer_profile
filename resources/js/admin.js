/*
 * Admin entry point.
 *
 * Shares no module with the public bundle: a module imported by both would be
 * hoisted into a chunk both entries load, which is what the split prevents.
 */

import { initFocalPoint } from './admin/focal-point';
import { initSidebar } from './admin/sidebar';
import { initStatusPoll } from './admin/status-poll';
import { initUploader } from './admin/upload';

document.addEventListener('DOMContentLoaded', () => {
    initSidebar();
    initUploader();
    initStatusPoll();
    initFocalPoint();
});
