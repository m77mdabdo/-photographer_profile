/**
 * Warns before leaving a form with unsaved changes.
 *
 * The flag is server state rendered into the DOM (data-dirty), so the warning
 * reflects what the component actually believes, and clears itself the moment
 * a save succeeds and re-renders.
 */

export function initUnsavedGuard() {
    const guard = document.querySelector('[data-unsaved-guard]');

    if (!guard) {
        return;
    }

    window.addEventListener('beforeunload', (event) => {
        const current = document.querySelector('[data-unsaved-guard]');

        if (current?.dataset.dirty === '1') {
            // The browser shows its own wording; returnValue just opts in.
            event.preventDefault();
            event.returnValue = '';
        }
    });
}
