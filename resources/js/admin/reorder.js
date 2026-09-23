/**
 * Drag-to-reorder for admin lists.
 *
 * The whole point: dragging mutates the DOM and nothing else. The server hears
 * about it exactly once, when the order is committed — a request per drop
 * position would be dozens of writes for one rearrangement, and would leave
 * the table half-sorted if the editor wandered off mid-drag.
 *
 * Keyboard parity is not an afterthought: the handle is a real button, and
 * Up/Down move the row the same way a drag would.
 */

function idsOf(list) {
    return Array.from(list.querySelectorAll('[data-id]')).map((row) => row.dataset.id);
}

function notify(list) {
    const handler = list.dataset.reorderHandler;
    const component = window.Livewire?.find(
        list.closest('[wire\\:id]')?.getAttribute('wire:id')
    );

    // One call, carrying the complete order.
    component?.call(handler, idsOf(list));
}

function rowsOf(list) {
    return Array.from(list.querySelectorAll('[data-id]'));
}

export function initReorder() {
    document.querySelectorAll('[data-reorderable]').forEach((list) => {
        if (list.dataset.reorderBound === '1') {
            return;
        }

        list.dataset.reorderBound = '1';

        let dragged = null;

        rowsOf(list).forEach((row) => {
            const handle = row.querySelector('[data-drag-handle]');

            if (!handle) {
                return;
            }

            handle.addEventListener('pointerdown', () => {
                row.draggable = true;
            });

            row.addEventListener('dragstart', (event) => {
                dragged = row;
                row.classList.add('is-dragging');
                event.dataTransfer.effectAllowed = 'move';
            });

            row.addEventListener('dragend', () => {
                row.classList.remove('is-dragging');
                row.draggable = false;

                if (dragged) {
                    dragged = null;
                    notify(list);
                }
            });

            row.addEventListener('dragover', (event) => {
                event.preventDefault();

                if (!dragged || dragged === row) {
                    return;
                }

                const box = row.getBoundingClientRect();
                const below = event.clientY > box.top + box.height / 2;

                row.parentNode.insertBefore(dragged, below ? row.nextSibling : row);
            });

            // Keyboard equivalent. Same outcome, same single notification.
            handle.addEventListener('keydown', (event) => {
                if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
                    return;
                }

                event.preventDefault();

                const sibling = event.key === 'ArrowUp'
                    ? row.previousElementSibling
                    : row.nextElementSibling;

                if (!sibling) {
                    return;
                }

                if (event.key === 'ArrowUp') {
                    row.parentNode.insertBefore(row, sibling);
                } else {
                    row.parentNode.insertBefore(sibling, row);
                }

                handle.focus();
                notify(list);
            });
        });
    });
}
