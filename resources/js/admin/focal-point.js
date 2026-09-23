/**
 * Focal point picker.
 *
 * Click anywhere on the image to place the point, or focus the handle and use
 * the arrow keys: 1% a step, 10% with Shift. The value is a 0..1 fraction, so
 * it survives every crop and rendition. The number inputs stay in sync and
 * remain editable on their own, which is what makes this work with no JS at
 * all.
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

        if (readout) {
            readout.textContent = `Focal point: ${round(x).toFixed(3)}, ${round(y).toFixed(3)}`;
        }
    }

    root.addEventListener('click', (event) => {
        if (event.target === handle) {
            return;
        }

        const box = root.getBoundingClientRect();
        x = clamp((event.clientX - box.left) / box.width);
        y = clamp((event.clientY - box.top) / box.height);
        apply();
        handle.focus();
    });

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
