/**
 * Story builder: structure lives in the DOM, the server hears about it once.
 *
 * Deferring persistence bought interaction speed and created a hazard — work
 * that exists nowhere but this tab. Three things answer for that: an undo
 * stack, a session-local draft, and a save that never discards anything when
 * it is refused.
 */

const SCROLL_EDGE = 80;
const SCROLL_SPEED = 18;
const HISTORY_LIMIT = 30;
const DRAFT_DEBOUNCE = 2000;

let history = [];
let future = [];
let savedSnapshot = null;   // the story as the database last saw it
let draftTimer = null;
let scrollTimer = null;
let suspendHistory = false;

// ---------------------------------------------------------------- plumbing

const root = () => document.querySelector('[data-story-builder]');
const list = () => root()?.querySelector('[data-block-list]');
const blocks = () => Array.from(list()?.querySelectorAll('[data-block]') ?? []);
const projectKey = () => `story-draft:${root()?.dataset.projectId ?? 'unknown'}`;

function livewire() {
    const el = root()?.closest('[wire\\:id]') ?? root()?.querySelector('[wire\\:id]');
    return el ? window.Livewire?.find(el.getAttribute('wire:id')) : null;
}

/**
 * The story as the server wants it: ids, types, text, image order.
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
                descriptions[index] = description;
            }
        });

        if (Object.keys(descriptions).length > 0) {
            data.descriptions = descriptions;
        }

        return { id: card.dataset.id || null, key: card.dataset.key, type: card.dataset.type, data, media };
    });
}

/**
 * Everything needed to rebuild the page exactly, including the bits the
 * server never sees: thumbnails, placeholders, and which blocks are collapsed.
 */
function snapshot() {
    return {
        blocks: blocks().map((card) => ({
            id: card.dataset.id || null,
            key: card.dataset.key,
            type: card.dataset.type,
            collapsed: card.classList.contains('is-collapsed'),
            fields: Array.from(card.querySelectorAll('[data-field]'))
                .map((f) => [f.dataset.field, f.value]),
            media: Array.from(card.querySelectorAll('[data-media-item]')).map((item) => ({
                id: Number(item.dataset.mediaId),
                thumbnail: item.querySelector('img')?.getAttribute('src') ?? null,
                placeholder: item.querySelector('[data-description]')?.placeholder ?? '',
                description: item.querySelector('[data-description]')?.value ?? '',
            })),
        })),
    };
}

function restore(state) {
    suspendHistory = true;

    const container = list();
    container.replaceChildren();

    state.blocks.forEach((descriptor) => {
        const template = root().querySelector(`[data-block-template="${descriptor.type}"]`);

        if (!template) {
            return;
        }

        const card = template.content.firstElementChild.cloneNode(true);
        card.dataset.key = descriptor.key;
        card.dataset.id = descriptor.id ?? '';
        card.removeAttribute('data-bound');

        // cloneNode copies markup, not live values — restoring text means
        // assigning it, exactly as duplication does.
        const fields = card.querySelectorAll('[data-field]');
        descriptor.fields.forEach(([name, value]) => {
            const field = Array.from(fields).find((f) => f.dataset.field === name);
            if (field) {
                field.value = value;
            }
        });

        const items = card.querySelector('[data-media-items]');
        items?.replaceChildren();
        descriptor.media.forEach((entry) => appendMediaItem(card, entry));

        if (descriptor.collapsed) {
            card.classList.add('is-collapsed');
            card.querySelector('[data-body]').hidden = true;
            card.querySelector('[data-collapse-toggle]')?.setAttribute('aria-expanded', 'false');
        }

        container.append(card);
        bindCard(card);
    });

    suspendHistory = false;
    refresh();
}

// ---------------------------------------------------------------- history

function remember() {
    if (suspendHistory) {
        return;
    }

    history.push(JSON.stringify(snapshot()));

    if (history.length > HISTORY_LIMIT) {
        history.shift();   // bounded, so a long session cannot grow forever
    }

    future = [];
}

function undo() {
    if (history.length === 0) {
        return;
    }

    future.push(JSON.stringify(snapshot()));
    restore(JSON.parse(history.pop()));
    announce('Undone');
}

function redo() {
    if (future.length === 0) {
        return;
    }

    history.push(JSON.stringify(snapshot()));
    restore(JSON.parse(future.pop()));
    announce('Redone');
}

function announce(text) {
    const region = root()?.querySelector('[data-announce]');

    if (region) {
        region.textContent = text;
    }
}

// ---------------------------------------------------------------- state

function isDirty() {
    return JSON.stringify(collect()) !== savedSnapshot;
}

function sync() {
    const container = root();

    if (!container) {
        return;
    }

    // Comparing against the saved snapshot means undoing back to where you
    // started reports clean, rather than "dirty because something happened".
    const dirty = isDirty();
    container.dataset.dirty = dirty ? '1' : '0';
    container.querySelector('[data-dirty-badge]')?.toggleAttribute('hidden', !dirty);

    container.querySelector('[data-undo]').disabled = history.length === 0;
    container.querySelector('[data-redo]').disabled = future.length === 0;

    scheduleDraft();
}

// ---------------------------------------------------------------- drafts

function scheduleDraft() {
    window.clearTimeout(draftTimer);
    draftTimer = window.setTimeout(writeDraft, DRAFT_DEBOUNCE);
}

/**
 * A client-side draft only: ids, order and text. No binaries, and nothing
 * reaches the database until the editor presses Save.
 */
function writeDraft() {
    try {
        if (!isDirty()) {
            window.sessionStorage.removeItem(projectKey());
            return;
        }

        window.sessionStorage.setItem(projectKey(), JSON.stringify(snapshot()));
    } catch {
        // Private mode, quota, blocked storage: a draft is a convenience.
    }
}

function clearDraft() {
    try {
        window.sessionStorage.removeItem(projectKey());
    } catch { /* nothing to do */ }
}

function readDraft() {
    try {
        const raw = window.sessionStorage.getItem(projectKey());
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

function offerDraft() {
    const draft = readDraft();

    if (!draft) {
        return;
    }

    // Only offer it when it actually differs from what the server just sent.
    const current = JSON.stringify(snapshot());

    if (JSON.stringify(draft) === current) {
        clearDraft();
        return;
    }

    const prompt = root().querySelector('[data-draft-prompt]');

    if (!prompt) {
        return;
    }

    prompt.hidden = false;
    prompt.querySelector('[data-draft-count]').textContent = String(draft.blocks.length);

    prompt.querySelector('[data-draft-restore]').addEventListener('click', () => {
        remember();
        restore(draft);
        prompt.hidden = true;
        announce('Draft restored');
    }, { once: true });

    prompt.querySelector('[data-draft-discard]').addEventListener('click', () => {
        clearDraft();
        prompt.hidden = true;
    }, { once: true });
}

// ---------------------------------------------------------------- validation

/**
 * The same rules the server enforces, read from attributes the enum rendered.
 */
function problemsFor(card) {
    const problems = [];
    const label = card.dataset.label;
    const min = Number(card.dataset.min || 0);
    const max = card.dataset.max === '' ? null : Number(card.dataset.max);
    const count = card.querySelectorAll('[data-media-item]').length;

    if (count < min) {
        problems.push(`${label} needs ${min} image${min === 1 ? '' : 's'}; it has ${count}.`);
    }

    if (max !== null && count > max) {
        problems.push(`${label} takes at most ${max} image${max === 1 ? '' : 's'}; it has ${count}.`);
    }

    (card.dataset.required || '').split(',').filter(Boolean).forEach((key) => {
        const field = card.querySelector(`[data-field="${key}"]`);

        if (!field || field.value.trim() === '') {
            problems.push(`${label} needs ${key === 'quote' ? 'the quote itself' : key === 'body' ? 'some body text' : `a ${key}`}.`);
        }
    });

    return problems;
}

function validate() {
    let invalid = 0;
    let first = null;

    blocks().forEach((card) => {
        const problems = problemsFor(card);
        const slot = card.querySelector('[data-errors]');

        slot.replaceChildren(...problems.map((text) => {
            const li = document.createElement('li');
            li.textContent = text;
            return li;
        }));

        slot.hidden = problems.length === 0;
        card.classList.toggle('block-card--invalid', problems.length > 0);
        card.querySelector('[data-flag]').hidden = problems.length === 0;

        if (problems.length > 0) {
            invalid += 1;
            first ??= card;
        }
    });

    // B3: say what is wrong before the button is pressed.
    const notice = root().querySelector('[data-save-notice]');

    if (notice) {
        notice.hidden = invalid === 0;
        notice.querySelector('[data-invalid-count]').textContent =
            `${invalid} block${invalid === 1 ? '' : 's'} need${invalid === 1 ? 's' : ''} attention`;
        notice.dataset.target = first?.dataset.key ?? '';
    }

    return invalid;
}

function refresh() {
    const all = blocks();

    root().querySelector('[data-block-count]').textContent = String(all.length);
    root().querySelector('[data-empty]')?.toggleAttribute('hidden', all.length > 0);

    const outline = root().querySelector('[data-outline]');

    if (outline) {
        outline.replaceChildren(...all.map((card, index) => {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'outline__item';
            button.textContent = `${index + 1}. ${card.dataset.label}`;
            button.addEventListener('click', () => focusBlock(card));
            li.append(button);
            return li;
        }));
    }

    all.forEach((card) => {
        updateSummary(card);
        updateMediaControls(card);
    });

    validate();
    sync();
}

function focusBlock(card) {
    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    card.querySelector('[data-collapse-toggle]')?.focus();
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

function hasRoom(card) {
    const max = card.dataset.max === '' ? null : Number(card.dataset.max);
    return max === null || card.querySelectorAll('[data-media-item]').length < max;
}

// ---------------------------------------------------------------- structure

function addBlock(type) {
    const template = root().querySelector(`[data-block-template="${type}"]`);

    if (!template) {
        return;
    }

    remember();

    const card = template.content.firstElementChild.cloneNode(true);
    card.dataset.key = `n${crypto.randomUUID()}`;
    card.dataset.id = '';
    card.removeAttribute('data-bound');

    list().append(card);
    bindCard(card);
    refresh();
    focusBlock(card);
}

function duplicateBlock(card) {
    remember();

    const copy = card.cloneNode(true);
    copy.dataset.key = `n${crypto.randomUUID()}`;
    copy.dataset.id = '';
    copy.removeAttribute('data-bound');

    const sources = card.querySelectorAll('[data-field], [data-description]');
    const targets = copy.querySelectorAll('[data-field], [data-description]');
    sources.forEach((source, i) => { targets[i].value = source.value; });

    card.after(copy);
    bindCard(copy);
    refresh();
    focusBlock(copy);
}

function moveBlock(card, direction) {
    const sibling = direction < 0 ? card.previousElementSibling : card.nextElementSibling;

    if (!sibling) {
        return;
    }

    remember();
    direction < 0 ? sibling.before(card) : sibling.after(card);
    refresh();
    card.querySelector(`[data-move="${direction}"]`)?.focus();
}

function removeBlock(card) {
    remember();
    card.remove();
    refresh();
    announce('Block removed. Press Control or Command Z to undo.');
}

// ---------------------------------------------------------------- media

function appendMediaItem(card, entry) {
    const items = card.querySelector('[data-media-items]');

    if (!items) {
        return;
    }

    const li = document.createElement('li');
    li.className = 'block-media__item';
    li.dataset.mediaItem = '';
    li.dataset.mediaId = String(entry.id);
    li.tabIndex = 0;

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

    const description = li.querySelector('[data-description]');
    description.placeholder = entry.placeholder ?? (entry.alt ? `Using: ${entry.alt}` : 'Describe this image');
    description.value = entry.description ?? '';

    items.append(li);
}

function moveImage(item, direction) {
    const sibling = direction < 0 ? item.previousElementSibling : item.nextElementSibling;

    if (!sibling) {
        return;
    }

    remember();
    direction < 0 ? sibling.before(item) : sibling.after(item);
    refresh();
    item.focus();
}

// ---------------------------------------------------------------- drag

function autoScroll(clientY) {
    stopAutoScroll();

    const up = clientY < SCROLL_EDGE;
    const down = clientY > window.innerHeight - SCROLL_EDGE;

    if (!up && !down) {
        return;
    }

    scrollTimer = window.setInterval(() => {
        window.scrollBy(0, up ? -SCROLL_SPEED : SCROLL_SPEED);
    }, 16);
}

function stopAutoScroll() {
    if (scrollTimer !== null) {
        window.clearInterval(scrollTimer);
        scrollTimer = null;
    }
}

function bindImageDrag(item) {
    let dragging = false;
    let remembered = false;

    item.addEventListener('pointerdown', (event) => {
        if (event.target.closest('button, input, label')) {
            return;   // controls keep working
        }

        dragging = true;
        remembered = false;
        item.setPointerCapture(event.pointerId);
        item.classList.add('is-dragging');
    });

    item.addEventListener('pointermove', (event) => {
        if (!dragging) {
            return;
        }

        autoScroll(event.clientY);

        const target = document.elementFromPoint(event.clientX, event.clientY);
        const overItem = target?.closest('[data-media-item]');
        const overBlock = target?.closest('[data-block]');
        const sourceBlock = item.closest('[data-block]');

        if (!overBlock) {
            return;
        }

        // D3: a full destination refuses, and says so.
        if (overBlock !== sourceBlock && !hasRoom(overBlock)) {
            overBlock.classList.add('is-rejecting');
            announce(`${overBlock.dataset.label} is full.`);
            return;
        }

        overBlock.classList.remove('is-rejecting');

        if (!remembered) {
            remember();
            remembered = true;
        }

        if (overItem && overItem !== item) {
            const box = overItem.getBoundingClientRect();
            event.clientX > box.left + box.width / 2 ? overItem.after(item) : overItem.before(item);
        } else if (overBlock !== sourceBlock) {
            overBlock.querySelector('[data-media-items]')?.append(item);
        }
    });

    const end = (event) => {
        if (!dragging) {
            return;
        }

        dragging = false;
        stopAutoScroll();
        item.classList.remove('is-dragging');
        blocks().forEach((b) => b.classList.remove('is-rejecting'));

        if (item.hasPointerCapture(event.pointerId)) {
            item.releasePointerCapture(event.pointerId);
        }

        if (remembered) {
            refresh();
        }
    };

    item.addEventListener('pointerup', end);
    item.addEventListener('pointercancel', end);

    // D2: keyboard parity — Alt+←/→ repeats as fast as you can hold it.
    item.addEventListener('keydown', (event) => {
        if (!event.altKey || (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight')) {
            return;
        }

        event.preventDefault();
        moveImage(item, event.key === 'ArrowLeft' ? -1 : 1);
    });
}

function bindBlockDrag(card) {
    const handle = card.querySelector('[data-drag-handle]');

    if (!handle) {
        return;
    }

    let dragging = false;
    let remembered = false;

    handle.addEventListener('pointerdown', (event) => {
        dragging = true;
        remembered = false;
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
            if (!remembered) {
                remember();
                remembered = true;
            }

            const box = over.getBoundingClientRect();
            event.clientY > box.top + box.height / 2 ? over.after(card) : over.before(card);
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

        if (remembered) {
            refresh();
        }
    };

    handle.addEventListener('pointerup', end);
    handle.addEventListener('pointercancel', end);

    handle.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            event.preventDefault();
            moveBlock(card, event.key === 'ArrowUp' ? -1 : 1);
            handle.focus();
        }
    });
}

// ---------------------------------------------------------------- binding

function bindCard(card) {
    if (card.dataset.bound === '1') {
        return;
    }

    card.dataset.bound = '1';

    card.querySelector('[data-collapse-toggle]')?.addEventListener('click', () => {
        const collapsed = card.classList.toggle('is-collapsed');
        card.querySelector('[data-body]').hidden = collapsed;
        card.querySelector('[data-collapse-toggle]').setAttribute('aria-expanded', String(!collapsed));
        // C2: remembered, but never part of the story — no remember(), no dirty.
        writeDraft();
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
            projectId: Number(root().dataset.projectId),
        });
    });

    card.addEventListener('input', (event) => {
        if (event.target.matches('[data-field], [data-description]')) {
            updateSummary(card);
            validate();
            sync();
        }
    });

    /*
     * Text is remembered once per visit to a field, not once per keystroke:
     * thirty characters typed should cost one undo step, not thirty. The
     * snapshot is taken on focus, while the field still holds its old value,
     * and pushed on blur only if it actually changed.
     */
    let snapshotOnEntry = null;

    card.addEventListener('focusin', (event) => {
        if (event.target.matches('[data-field], [data-description]')) {
            snapshotOnEntry = { value: event.target.value, state: JSON.stringify(snapshot()) };
        }
    });

    card.addEventListener('focusout', (event) => {
        if (!event.target.matches('[data-field], [data-description]') || snapshotOnEntry === null) {
            return;
        }

        if (snapshotOnEntry.value !== event.target.value) {
            history.push(snapshotOnEntry.state);

            if (history.length > HISTORY_LIMIT) {
                history.shift();
            }

            future = [];
            sync();
        }

        snapshotOnEntry = null;
    });

    card.addEventListener('click', (event) => {
        const item = event.target.closest('[data-media-item]');

        if (!item) {
            return;
        }

        if (event.target.matches('[data-media-remove]')) {
            remember();
            item.remove();
            refresh();
            return;
        }

        const move = event.target.closest('[data-media-move]');

        if (move) {
            moveImage(item, Number(move.dataset.mediaMove));
        }
    });

    card.querySelectorAll('[data-media-item]').forEach(bindImageDrag);

    // Images added later need binding too.
    new MutationObserver((records) => {
        records.forEach((record) => {
            record.addedNodes.forEach((node) => {
                if (node.nodeType === 1 && node.matches?.('[data-media-item]')) {
                    bindImageDrag(node);
                }
            });
        });
    }).observe(card.querySelector('[data-media-items]') ?? card, { childList: true });

    bindBlockDrag(card);
}

// ---------------------------------------------------------------- init

export function initStoryBuilder() {
    const container = root();

    if (!container || container.dataset.builderBound === '1') {
        return;
    }

    container.dataset.builderBound = '1';

    blocks().forEach(bindCard);

    savedSnapshot = JSON.stringify(collect());
    history = [];
    future = [];

    refresh();
    offerDraft();

    container.querySelectorAll('[data-add-block]').forEach((button) => {
        button.addEventListener('click', () => addBlock(button.dataset.addBlock));
    });

    const setCollapsed = (collapsed) => {
        blocks().forEach((card) => {
            card.classList.toggle('is-collapsed', collapsed);
            card.querySelector('[data-body]').hidden = collapsed;
            card.querySelector('[data-collapse-toggle]').setAttribute('aria-expanded', String(!collapsed));
        });
        writeDraft();
    };

    container.querySelector('[data-collapse-all]')?.addEventListener('click', () => setCollapsed(true));
    container.querySelector('[data-expand-all]')?.addEventListener('click', () => setCollapsed(false));

    container.querySelector('[data-undo]')?.addEventListener('click', undo);
    container.querySelector('[data-redo]')?.addEventListener('click', redo);

    container.querySelector('[data-save-notice]')?.addEventListener('click', (event) => {
        const key = event.currentTarget.dataset.target;
        const card = blocks().find((c) => c.dataset.key === key);

        if (card) {
            focusBlock(card);
        }
    });

    container.querySelectorAll('[data-save]').forEach((button) => {
        button.addEventListener('click', () => livewire()?.call('saveStory', collect()));
    });

    document.addEventListener('keydown', (event) => {
        if (!(event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== 'z') {
            return;
        }

        if (document.activeElement?.matches('input, textarea') && !event.shiftKey && history.length === 0) {
            return;   // let the browser undo typing when we have nothing
        }

        event.preventDefault();
        event.shiftKey ? redo() : undo();
    });

    window.addEventListener('media-selected', (event) => {
        const { slot, media } = event.detail ?? {};
        const card = blocks().find((c) => c.dataset.key === slot);

        if (!card || !Array.isArray(media)) {
            return;
        }

        remember();
        media.forEach((entry) => appendMediaItem(card, entry));
        refresh();
    });

    window.addEventListener('story-saved', () => {
        savedSnapshot = JSON.stringify(collect());
        history = [];
        future = [];
        clearDraft();
        sync();
        announce('Story saved');
    });
}
