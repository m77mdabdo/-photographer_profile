/**
 * Story builder: structure lives here, in the DOM.
 *
 * Adding, removing, duplicating, moving, collapsing and receiving images from
 * the picker all mutate the page and nothing else. The server is contacted
 * exactly once, when Save is pressed, and receives the entire story.
 *
 * The keyboard path is deliberately identical to the pointer path: the ↑/↓
 * buttons perform the same local move a drag does, so reordering forty blocks
 * by keyboard costs the same as reordering them by hand — nothing.
 */

const SCROLL_EDGE = 80;      // px from the viewport edge that triggers autoscroll
const SCROLL_SPEED = 18;     // px per frame

let dirty = false;
let scrollTimer = null;

// ---------------------------------------------------------------- helpers

const root = () => document.querySelector('[data-story-builder]');
const list = () => root()?.querySelector('[data-block-list]');
const blocks = () => Array.from(list()?.querySelectorAll('[data-block]') ?? []);

function livewire() {
    const el = root()?.closest('[wire\\:id]') ?? root()?.querySelector('[wire\\:id]');
    return el ? window.Livewire?.find(el.getAttribute('wire:id')) : null;
}

function markDirty(value = true) {
    dirty = value;
    const container = root();

    if (!container) {
        return;
    }

    // B2: the indicator is local, so it flips the instant something changes.
    container.dataset.dirty = value ? '1' : '0';
    container.querySelector('[data-dirty-badge]')?.toggleAttribute('hidden', !value);
}

/**
 * Reads the whole story out of the DOM.
 */
function collect() {
    return blocks().map((card) => {
        const data = {};

        card.querySelectorAll('[data-field]').forEach((field) => {
            const value = field.value.trim();

            if (value !== '') {
                data[field.dataset.field] = value;
            }
        });

        const media = [];
        const descriptions = {};

        card.querySelectorAll('[data-media-item]').forEach((item, index) => {
            media.push(Number(item.dataset.mediaId));

            const description = item.querySelector('[data-description]')?.value.trim();

            if (description) {
                // Keyed by position, under a name the media guard permits.
                descriptions[index] = description;
            }
        });

        if (Object.keys(descriptions).length > 0) {
            data.descriptions = descriptions;
        }

        return {
            id: card.dataset.id || null,
            key: card.dataset.key,
            type: card.dataset.type,
            data,
            media,
        };
    });
}

function refresh() {
    const all = blocks();

    root().querySelector('[data-block-count]').textContent = String(all.length);
    root().querySelector('[data-empty]')?.toggleAttribute('hidden', all.length > 0);

    // Outline, rebuilt locally (D2).
    const outline = root().querySelector('[data-outline]');

    if (outline) {
        outline.replaceChildren(...all.map((card, index) => {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'outline__item';
            button.textContent = `${index + 1}. ${card.querySelector('.block-card__type')?.textContent.trim() ?? ''}`;
            button.addEventListener('click', () => {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                card.querySelector('[data-collapse-toggle]')?.focus();
            });
            li.append(button);
            return li;
        }));
    }

    all.forEach((card) => {
        updateSummary(card);
        updateMediaControls(card);
    });
}

function updateSummary(card) {
    const images = card.querySelectorAll('[data-media-item]').length;
    const firstText = Array.from(card.querySelectorAll('[data-field]'))
        .map((f) => f.value.trim())
        .find((v) => v !== '') ?? '';

    const parts = [];

    if (images > 0) {
        parts.push(`${images} image${images === 1 ? '' : 's'}`);
    }

    if (firstText !== '') {
        parts.push(firstText.length > 60 ? `${firstText.slice(0, 60)}…` : firstText);
    }

    card.querySelector('[data-summary]').textContent = parts.join(' · ');
}

/**
 * Hides the add-images control once a bounded block is full.
 */
function updateMediaControls(card) {
    const zone = card.querySelector('[data-media-zone]');

    if (!zone) {
        return;
    }

    const max = card.dataset.max === '' ? null : Number(card.dataset.max);
    const count = card.querySelectorAll('[data-media-item]').length;

    zone.querySelector('[data-add-media]')?.toggleAttribute('hidden', max !== null && count >= max);

    card.querySelectorAll('[data-media-item]').forEach((item, index, items) => {
        item.querySelector('[data-media-move="-1"]').disabled = index === 0;
        item.querySelector('[data-media-move="1"]').disabled = index === items.length - 1;
    });
}

// ---------------------------------------------------------------- structure

function addBlock(type) {
    const template = root().querySelector(`[data-block-template="${type}"]`);

    if (!template) {
        return;
    }

    const card = template.content.firstElementChild.cloneNode(true);
    card.dataset.key = `n${crypto.randomUUID()}`;
    card.dataset.id = '';

    list().append(card);
    bindCard(card);
    markDirty();
    refresh();

    card.querySelector('[data-collapse-toggle]')?.focus();
}

function duplicateBlock(card) {
    const copy = card.cloneNode(true);

    copy.dataset.key = `n${crypto.randomUUID()}`;
    copy.dataset.id = '';   // a new row, with its own usage rows

    // cloneNode copies the DOM but not the current values of form controls.
    const sources = card.querySelectorAll('[data-field], [data-description]');
    const targets = copy.querySelectorAll('[data-field], [data-description]');
    sources.forEach((source, i) => { targets[i].value = source.value; });

    card.after(copy);
    bindCard(copy);
    markDirty();
    refresh();

    copy.querySelector('[data-collapse-toggle]')?.focus();
}

function moveBlock(card, direction) {
    const sibling = direction < 0 ? card.previousElementSibling : card.nextElementSibling;

    if (!sibling) {
        return;
    }

    if (direction < 0) {
        sibling.before(card);
    } else {
        sibling.after(card);
    }

    markDirty();
    refresh();

    // Focus follows the block, so repeated presses keep working.
    card.querySelector(`[data-move="${direction}"]`)?.focus();
}

function removeBlock(card) {
    card.remove();
    markDirty();
    refresh();
}

// ---------------------------------------------------------------- media

function mediaItemFrom(card, entry) {
    const template = card.querySelector('[data-media-items]');
    const li = document.createElement('li');
    li.className = 'block-media__item';
    li.dataset.mediaItem = '';
    li.dataset.mediaId = String(entry.id);

    li.innerHTML = `
        <img alt="" class="block-media__image" loading="lazy">
        <label class="block-media__alt">
            <span class="sr-only">Alt text for this image</span>
            <input type="text" data-description>
        </label>
        <span class="block-media__controls">
            <button type="button" class="admin-link" data-media-move="-1" aria-label="Move image earlier">←</button>
            <button type="button" class="admin-link" data-media-move="1" aria-label="Move image later">→</button>
            <button type="button" class="admin-link" data-media-remove aria-label="Remove image">×</button>
        </span>
    `;

    const img = li.querySelector('img');

    if (entry.thumbnail) {
        img.src = entry.thumbnail;
    } else {
        img.remove();
    }

    li.querySelector('[data-description]').placeholder =
        entry.alt ? `Using: ${entry.alt}` : 'Describe this image';

    template.append(li);
}

// ---------------------------------------------------------------- binding

function bindCard(card) {
    if (card.dataset.bound === '1') {
        return;
    }

    card.dataset.bound = '1';

    card.querySelector('[data-collapse-toggle]')?.addEventListener('click', () => {
        const body = card.querySelector('[data-body]');
        const collapsed = card.classList.toggle('is-collapsed');
        body.hidden = collapsed;
        card.querySelector('[data-collapse-toggle]').setAttribute('aria-expanded', String(!collapsed));
        // D1: collapsing is a view preference, never a change to the story.
    });

    card.querySelectorAll('[data-move]').forEach((button) => {
        button.addEventListener('click', () => moveBlock(card, Number(button.dataset.move)));
    });

    card.querySelector('[data-duplicate]')?.addEventListener('click', () => duplicateBlock(card));

    card.querySelector('[data-remove]')?.addEventListener('click', () => {
        card.querySelector('[data-confirm]').hidden = false;
        card.querySelector('[data-remove-confirm]')?.focus();
    });

    card.querySelector('[data-remove-cancel]')?.addEventListener('click', () => {
        card.querySelector('[data-confirm]').hidden = true;
        card.querySelector('[data-remove]')?.focus();
    });

    card.querySelector('[data-remove-confirm]')?.addEventListener('click', () => removeBlock(card));

    card.querySelector('[data-add-media]')?.addEventListener('click', () => {
        const max = card.dataset.max === '' ? null : Number(card.dataset.max);
        const used = card.querySelectorAll('[data-media-item]').length;

        livewire()?.dispatch('open-picker', {
            slot: card.dataset.key,
            multiple: max === null || max > 1,
            max: max === null ? null : Math.max(0, max - used),
        });
    });

    // Field edits mark dirty and refresh the collapsed summary.
    card.addEventListener('input', (event) => {
        if (event.target.matches('[data-field], [data-description]')) {
            markDirty();
            updateSummary(card);
        }
    });

    card.addEventListener('click', (event) => {
        const item = event.target.closest('[data-media-item]');

        if (!item) {
            return;
        }

        if (event.target.matches('[data-media-remove]')) {
            item.remove();
            markDirty();
            refresh();
        }

        const move = event.target.closest('[data-media-move]');

        if (move) {
            const direction = Number(move.dataset.mediaMove);
            const sibling = direction < 0 ? item.previousElementSibling : item.nextElementSibling;

            if (sibling) {
                direction < 0 ? sibling.before(item) : sibling.after(item);
                markDirty();
                refresh();
                item.querySelector(`[data-media-move="${direction}"]`)?.focus();
            }
        }
    });

    bindDrag(card);
}

// ---------------------------------------------------------------- drag

function autoScroll(clientY) {
    stopAutoScroll();

    const top = clientY < SCROLL_EDGE;
    const bottom = clientY > window.innerHeight - SCROLL_EDGE;

    if (!top && !bottom) {
        return;
    }

    // B3: without this, dragging block 38 to position 2 is impossible —
    // the drop target is simply off screen.
    scrollTimer = window.setInterval(() => {
        window.scrollBy(0, top ? -SCROLL_SPEED : SCROLL_SPEED);
    }, 16);
}

function stopAutoScroll() {
    if (scrollTimer !== null) {
        window.clearInterval(scrollTimer);
        scrollTimer = null;
    }
}

function bindDrag(card) {
    const handle = card.querySelector('[data-drag-handle]');

    if (!handle) {
        return;
    }

    let dragging = false;

    handle.addEventListener('pointerdown', (event) => {
        dragging = true;
        handle.setPointerCapture(event.pointerId);
        card.classList.add('is-dragging');
        event.preventDefault();
    });

    handle.addEventListener('pointermove', (event) => {
        if (!dragging) {
            return;
        }

        autoScroll(event.clientY);

        const over = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-block]');

        if (over && over !== card && over.parentElement === card.parentElement) {
            const box = over.getBoundingClientRect();
            const below = event.clientY > box.top + box.height / 2;
            below ? over.after(card) : over.before(card);
            markDirty();
        }
    });

    const end = (event) => {
        if (!dragging) {
            return;
        }

        dragging = false;
        stopAutoScroll();
        card.classList.remove('is-dragging');

        if (handle.hasPointerCapture(event.pointerId)) {
            handle.releasePointerCapture(event.pointerId);
        }

        refresh();
    };

    handle.addEventListener('pointerup', end);
    handle.addEventListener('pointercancel', end);

    // Keyboard parity: the same local move, no round trip.
    handle.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            event.preventDefault();
            moveBlock(card, event.key === 'ArrowUp' ? -1 : 1);
            handle.focus();
        }
    });
}

// ---------------------------------------------------------------- init

export function initStoryBuilder() {
    const container = root();

    if (!container || container.dataset.builderBound === '1') {
        return;
    }

    container.dataset.builderBound = '1';

    blocks().forEach(bindCard);
    refresh();
    markDirty(false);

    container.querySelectorAll('[data-add-block]').forEach((button) => {
        button.addEventListener('click', () => addBlock(button.dataset.addBlock));
    });

    container.querySelector('[data-collapse-all]')?.addEventListener('click', () => {
        blocks().forEach((card) => {
            card.classList.add('is-collapsed');
            card.querySelector('[data-body]').hidden = true;
            card.querySelector('[data-collapse-toggle]').setAttribute('aria-expanded', 'false');
        });
    });

    container.querySelector('[data-expand-all]')?.addEventListener('click', () => {
        blocks().forEach((card) => {
            card.classList.remove('is-collapsed');
            card.querySelector('[data-body]').hidden = false;
            card.querySelector('[data-collapse-toggle]').setAttribute('aria-expanded', 'true');
        });
    });

    container.querySelectorAll('[data-save]').forEach((button) => {
        button.addEventListener('click', () => {
            livewire()?.call('saveStory', collect());
        });
    });

    // The picker hands back its whole selection at once.
    window.addEventListener('media-selected', (event) => {
        const { slot, media } = event.detail ?? {};
        const card = blocks().find((c) => c.dataset.key === slot);

        if (!card || !Array.isArray(media)) {
            return;
        }

        media.forEach((entry) => mediaItemFrom(card, entry));
        markDirty();
        refresh();
    });

    window.addEventListener('story-saved', () => markDirty(false));
}
