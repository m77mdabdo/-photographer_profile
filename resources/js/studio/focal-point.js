/**
 * Focal point picker.
 *
 * Three ways to set the point, all equal citizens:
 *   - drag the handle (pointer events, so mouse, pen and touch all work)
 *   - click anywhere on the image
 *   - focus the handle and use the arrow keys: 1% a step, 10% with Shift
 *
 * The value is a 0..1 fraction, so it survives every crop and rendition. The
 * number inputs stay in sync and remain editable on their own, which is what
 * keeps this usable with no JavaScript at all.
 */

const clamp = (value) => Math.min(1, Math.max(0, value));
const round = (value) => Math.round(value * 1000) / 1000;

export function initFocalPoint() {
    const root = document.querySelector('[data-focal]');

    if (!root) {
        return;
    }

    const handle = root.querySelector('[data-focal-handle]');
    const inputX = document.querySelector('[data-focal-input-x]');
    const inputY = document.querySelector('[data-focal-input-y]');
    const readout = document.querySelector('[data-focal-readout]');

    let x = parseFloat(root.dataset.focalX) || 0.5;
    let y = parseFloat(root.dataset.focalY) || 0.5;

    function apply() {
        handle.style.left = `${x * 100}%`;
        handle.style.top = `${y * 100}%`;
        inputX.value = round(x);
        inputY.value = round(y);

        /*
         * ───────────────────────────────────────────────────────────────────
         * TELL THE PAGE THE VALUE MOVED.
         *
         * Assigning .value fires nothing. In the custom admin that was fine —
         * the form reads the inputs on submit — but the panel's page binds
         * them, and a binding only learns about a change from an `input`
         * event. Without this, dragging the handle moved the dot and saved
         * the OLD coordinates, which is the worst shape of bug: the thing you
         * did is on screen and not in the database.
         *
         * Dispatched on the element and allowed to bubble, so it works for
         * whatever is listening — Livewire here, nothing in the old admin.
         * The inputs' own `input` listener below only moves the handle and
         * never calls apply(), so there is no loop.
         * ───────────────────────────────────────────────────────────────────
         */
        inputX.dispatchEvent(new Event('input', { bubbles: true }));
        inputY.dispatchEvent(new Event('input', { bubbles: true }));

        if (readout) {
            // The sentence comes from the page (studio.media.focal_readout), in the dashboard's language.
            readout.textContent = (readout.dataset.template || 'Focal point: :x, :y').replace(':x', round(x).toFixed(3)).replace(':y', round(y).toFixed(3));
        }
    }

    function pointFrom(event) {
        const box = root.getBoundingClientRect();
        x = clamp((event.clientX - box.left) / box.width);
        y = clamp((event.clientY - box.top) / box.height);
        apply();
    }

    root.addEventListener('click', (event) => {
        if (event.target === handle) {
            return;
        }

        pointFrom(event);
        handle.focus();
    });

    /*
     * Dragging. Pointer events cover mouse, pen and touch in one path, and
     * setPointerCapture keeps the drag alive when the pointer leaves the image,
     * so releasing outside the frame does not strand the handle.
     */
    let dragging = false;

    handle.addEventListener('pointerdown', (event) => {
        dragging = true;
        handle.setPointerCapture(event.pointerId);
        handle.classList.add('is-dragging');
        event.preventDefault();
    });

    handle.addEventListener('pointermove', (event) => {
        if (dragging) {
            pointFrom(event);
        }
    });

    function endDrag(event) {
        if (!dragging) {
            return;
        }

        dragging = false;

        if (handle.hasPointerCapture(event.pointerId)) {
            handle.releasePointerCapture(event.pointerId);
        }

        handle.classList.remove('is-dragging');
    }

    handle.addEventListener('pointerup', endDrag);
    handle.addEventListener('pointercancel', endDrag);

    // Stops the browser treating a drag on the image as a native image drag.
    root.addEventListener('dragstart', (event) => event.preventDefault());

    handle.addEventListener('keydown', (event) => {
        const step = event.shiftKey ? 0.1 : 0.01;
        const moves = {
            ArrowLeft: () => { x = clamp(x - step); },
            ArrowRight: () => { x = clamp(x + step); },
            ArrowUp: () => { y = clamp(y - step); },
            ArrowDown: () => { y = clamp(y + step); },
            Home: () => { x = 0.5; y = 0.5; },
        };

        if (moves[event.key]) {
            event.preventDefault();
            moves[event.key]();
            apply();
        }
    });

    // Typing in the number fields moves the handle too.
    [inputX, inputY].forEach((input) => {
        input?.addEventListener('input', () => {
            x = clamp(parseFloat(inputX.value) || 0);
            y = clamp(parseFloat(inputY.value) || 0);
            handle.style.left = `${x * 100}%`;
            handle.style.top = `${y * 100}%`;
        });
    });

    apply();
}
