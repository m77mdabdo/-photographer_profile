/**
 * Media uploader: drag-and-drop plus file input, one file per request.
 *
 * Serial by design. A worker peaks near 300 MB while encoding, so firing a
 * dozen uploads at once is how you exhaust the box rather than how you go
 * faster. XMLHttpRequest is used instead of fetch purely because it reports
 * upload progress, which fetch still cannot.
 */

const CSRF = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

function humanSize(bytes) {
    return bytes > 1048576
        ? `${(bytes / 1048576).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function createRow(queue, file) {
    const row = document.createElement('li');
    row.className = 'upload-row';
    row.innerHTML = `
        <span class="upload-row__name"></span>
        <span class="upload-row__size"></span>
        <progress class="upload-row__progress" max="100" value="0"></progress>
        <span class="upload-row__state">Waiting</span>
    `;
    row.querySelector('.upload-row__name').textContent = file.name;
    row.querySelector('.upload-row__size').textContent = humanSize(file.size);
    queue.append(row);

    return {
        setState(text, modifier) {
            row.querySelector('.upload-row__state').textContent = text;
            row.className = `upload-row${modifier ? ` upload-row--${modifier}` : ''}`;
        },
        setProgress(percent) {
            row.querySelector('.upload-row__progress').value = percent;
        },
        removeProgress() {
            row.querySelector('.upload-row__progress')?.remove();
        },
    };
}

function uploadOne(endpoint, file, row) {
    return new Promise((resolve) => {
        const body = new FormData();
        body.append('files[]', file);
        body.append('_token', CSRF());

        const request = new XMLHttpRequest();
        request.open('POST', endpoint);
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        request.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                row.setProgress(Math.round((event.loaded / event.total) * 100));
            }
        });

        request.addEventListener('load', () => {
            row.removeProgress();

            if (request.status === 201) {
                row.setState('Uploaded — queued for processing', 'done');
                resolve(true);
                return;
            }

            // Surface the server's own words rather than a generic failure.
            let message = `Upload failed (${request.status})`;

            try {
                const payload = JSON.parse(request.responseText);
                message = payload.errors?.['files.0']?.[0]
                    ?? payload.errors?.files?.[0]
                    ?? payload.message
                    ?? message;
            } catch { /* response was not JSON; keep the status message */ }

            row.setState(message, 'failed');
            resolve(false);
        });

        request.addEventListener('error', () => {
            row.removeProgress();
            row.setState('Network error. Check your connection and try again.', 'failed');
            resolve(false);
        });

        request.send(body);
    });
}

async function uploadAll(form, files) {
    const endpoint = form.dataset.endpoint;
    const queue = document.querySelector('[data-upload-queue]');
    const submit = form.querySelector('[data-fallback-submit]');
    const rows = Array.from(files).map((file) => [file, createRow(queue, file)]);

    if (submit) {
        submit.disabled = true;
    }

    let uploaded = 0;

    // Strictly one at a time.
    for (const [file, row] of rows) {
        row.setState('Uploading…');
        if (await uploadOne(endpoint, file, row)) {
            uploaded += 1;
        }
    }

    if (submit) {
        submit.disabled = false;
    }

    if (uploaded > 0) {
        // Reload so the grid shows the new rows and the poller picks them up.
        window.location.reload();
    }
}

export function initUploader() {
    const form = document.querySelector('[data-uploader]');

    if (!form) {
        return;
    }

    const input = form.querySelector('[data-file-input]');
    const dropzone = form.querySelector('[data-dropzone]');

    // Intercept the normal submit only once JS is running; without it the form
    // posts normally and the server handles it.
    form.addEventListener('submit', (event) => {
        if (input.files.length === 0) {
            return;
        }

        event.preventDefault();
        uploadAll(form, input.files);
    });

    input.addEventListener('change', () => {
        if (input.files.length > 0) {
            uploadAll(form, input.files);
        }
    });

    if (!dropzone) {
        return;
    }

    ['dragenter', 'dragover'].forEach((name) => {
        dropzone.addEventListener(name, (event) => {
            event.preventDefault();
            dropzone.classList.add('is-dragging');
        });
    });

    ['dragleave', 'drop'].forEach((name) => {
        dropzone.addEventListener(name, (event) => {
            event.preventDefault();
            dropzone.classList.remove('is-dragging');
        });
    });

    dropzone.addEventListener('drop', (event) => {
        const dropped = event.dataTransfer?.files;

        if (dropped?.length) {
            uploadAll(form, dropped);
        }
    });
}
