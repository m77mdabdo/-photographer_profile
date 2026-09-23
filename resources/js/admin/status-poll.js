/**
 * Keeps the status badges of unfinished media in step with the queue.
 *
 * One request every three seconds, carrying only the ids that are still
 * unfinished. It stops entirely once nothing is pending, and pauses while the
 * tab is hidden — a background tab has no business polling a server.
 */

const INTERVAL = 3000;
const UNFINISHED = ['pending', 'processing'];

export function initStatusPoll() {
    const grid = document.querySelector('[data-media-grid]');

    if (!grid) {
        return;
    }

    let timer = null;

    const pendingCards = () =>
        Array.from(grid.querySelectorAll('[data-media-id]')).filter((card) => {
            const badge = card.querySelector('[data-status-badge]');
            return badge && UNFINISHED.includes(badge.textContent.trim().toLowerCase().replace('in queue', 'pending'));
        });

    async function poll() {
        const cards = pendingCards();

        if (cards.length === 0) {
            stop();
            return;
        }

        const params = new URLSearchParams();
        cards.forEach((card) => params.append('ids[]', card.dataset.mediaId));

        try {
            const response = await fetch(`${grid.dataset.statusEndpoint}?${params}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                return;
            }

            const payload = await response.json();
            let changed = false;

            payload.media.forEach((item) => {
                const card = grid.querySelector(`[data-media-id="${item.id}"]`);
                const badge = card?.querySelector('[data-status-badge]');

                if (!badge) {
                    return;
                }

                if (!UNFINISHED.includes(item.status)) {
                    changed = true;
                }

                badge.textContent = item.label;
                badge.className = `badge badge--${item.status}`;
            });

            // A finished image has renditions the page has not rendered yet.
            if (changed) {
                window.location.reload();
            }
        } catch {
            // A failed poll is not worth reporting; the next tick retries.
        }
    }

    function start() {
        if (timer === null && pendingCards().length > 0) {
            timer = window.setInterval(poll, INTERVAL);
        }
    }

    function stop() {
        if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
    }

    document.addEventListener('visibilitychange', () => {
        document.hidden ? stop() : start();
    });

    start();
}
