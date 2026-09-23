/**
 * Sidebar collapse.
 *
 * Progressive enhancement only: the toggle button is hidden in the markup and
 * revealed here. Without JavaScript there is no button and the sidebar simply
 * stays visible, so navigation is never trapped behind a script.
 */

const NARROW = '(max-width: 820px)';

export function initSidebar() {
    const toggle = document.querySelector('#sidebar-toggle');
    const sidebar = document.querySelector('#admin-sidebar');

    if (!toggle || !sidebar) {
        return;
    }

    const narrow = window.matchMedia(NARROW);

    function sync() {
        toggle.hidden = !narrow.matches;

        if (!narrow.matches) {
            sidebar.classList.remove('is-collapsed');
            toggle.setAttribute('aria-expanded', 'true');
        } else {
            sidebar.classList.add('is-collapsed');
            toggle.setAttribute('aria-expanded', 'false');
        }
    }

    toggle.addEventListener('click', () => {
        const open = sidebar.classList.toggle('is-collapsed') === false;
        toggle.setAttribute('aria-expanded', String(open));
    });

    narrow.addEventListener('change', sync);
    sync();
}
