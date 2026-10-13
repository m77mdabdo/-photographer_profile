<div>
    <header class="module-header">
        <h1 class="admin-heading">{{ $inquiry->name }}</h1>
        <p class="admin-note">
            <a href="{{ route('legacy.inquiries.index') }}" class="admin-link">All enquiries</a>
        </p>
    </header>

    @if ($message !== '')
        <p class="admin-flash__message" role="status" aria-live="polite">{{ $message }}</p>
    @endif

    {{--
        Everything they sent. Blade escapes every one of these by default, so
        a message containing markup is shown as the characters that were
        typed rather than rendered — see the XSS test.
    --}}
    <dl class="facts">
        <dt>Email</dt>
        <dd><a href="mailto:{{ $inquiry->email }}">{{ $inquiry->email }}</a></dd>

        @if ($inquiry->phone)
            <dt>Phone</dt><dd>{{ $inquiry->phone }}</dd>
        @endif
        @if ($inquiry->company)
            <dt>Company</dt><dd>{{ $inquiry->company }}</dd>
        @endif
        @if ($inquiry->project_type)
            <dt>Kind of work</dt><dd>{{ $inquiry->project_type }}</dd>
        @endif
        @if ($inquiry->shootDateForHumans())
            {{--
                As they typed it. The parsed shoot_date is shown alongside
                only when it adds something, because "the week of the 3rd"
                is the useful sentence and "3 May" is an interpretation.
            --}}
            <dt>Date</dt>
            <dd>
                {{ $inquiry->shootDateForHumans() }}
                @if ($inquiry->shoot_date && $inquiry->shoot_date_text)
                    <span class="admin-note">(read as {{ $inquiry->shoot_date->format('j F Y') }})</span>
                @endif
            </dd>
        @endif
        @if ($inquiry->location)
            <dt>Location</dt><dd>{{ $inquiry->location }}</dd>
        @endif
        @if ($inquiry->budget_range)
            <dt>Budget</dt><dd>{{ $inquiry->budget_range }}</dd>
        @endif

        <dt>Arrived</dt>
        <dd>{{ $inquiry->created_at?->format('j F Y, H:i') }} ({{ $inquiry->created_at?->diffForHumans() }})</dd>

        <dt>Source</dt>
        <dd>{{ Str::headline($inquiry->source) }}</dd>

        <dt>Consent</dt>
        <dd>
            @if ($inquiry->consented_at)
                Given {{ $inquiry->consented_at->format('j F Y, H:i') }}
            @else
                Not recorded
            @endif
        </dd>

        {{--
            The sender's address is stored as a one-way hash and there is
            nothing to display. Saying so is better than omitting the row:
            somebody looking for an IP should find out it does not exist
            rather than assume it is elsewhere.
        --}}
        <dt>Origin</dt>
        <dd class="admin-note">
            {{ $inquiry->ip_hash ? 'Recorded as a one-way hash. The address itself is not stored and cannot be shown.' : 'Not recorded.' }}
        </dd>
    </dl>

    <h2 class="admin-subheading">Message</h2>
    <div class="inquiry-message">
        @foreach (preg_split('/\R\s*\R/', trim($inquiry->message)) as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach
    </div>

    {{--
        HEARD VIA — now the studio's field, not the sender's.

        It was a question on the public form, asked of somebody who had not
        been replied to yet. It is genuinely useful to a studio, so the column
        stayed and the answer is recorded here after a conversation, where it
        is a note rather than a toll gate.
    --}}
    <h2 class="admin-subheading">Heard via</h2>
    <form wire:submit="saveHeardFrom" class="filters">
        <div class="field">
            <label for="heard-from">How they found the studio</label>
            <input type="text" id="heard-from" wire:model="heardFrom"
                   placeholder="A previous client, an editor, a search — once you know">
            @error('heardFrom') <p class="field__error" role="alert">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="admin-button">Save</button>
    </form>

    <h2 class="admin-subheading">Status</h2>
    <form wire:submit="updateStatus" class="filters">
        <div class="field">
            <label for="inquiry-status">Where this has got to</label>
            <select id="inquiry-status" wire:model="status">
                @foreach ($statuses as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }} — {{ $case->description() }}</option>
                @endforeach
            </select>
            @error('status') <p class="field__error" role="alert">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="admin-button">Save status</button>
    </form>

    <h2 class="admin-subheading">Notes</h2>
    <form wire:submit="addNote" class="admin-form">
        <div class="field">
            <label for="inquiry-note">Add a note</label>
            <textarea id="inquiry-note" rows="3" wire:model="note"
                      placeholder="What was agreed, what was quoted, what to do next."></textarea>
            @error('note') <p class="field__error" role="alert">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="admin-button">Add note</button>
    </form>

    @if ($notes->isNotEmpty())
        <ul class="category-list" role="list">
            @foreach ($notes as $entry)
                <li class="category-row" wire:key="note-{{ $entry->id }}">
                    <span class="project-row__main">
                        <span>{{ $entry->body }}</span>
                        <span class="project-row__meta">
                            {{ $entry->author?->name ?? 'A deleted account' }}
                            · {{ $entry->created_at?->format('j M Y, H:i') }}
                        </span>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif

    <h2 class="admin-subheading">Removing this</h2>

    <div class="danger-zone">
        <button type="button" class="admin-button admin-button--quiet" wire:click="archive">
            Move to the bin
        </button>
        <p class="admin-note">A soft delete. The enquiry stops appearing in lists and can be restored.</p>

        @can('forceDelete', $inquiry)
            @if (! $confirmingErasure)
                <button type="button" class="admin-button admin-button--danger"
                        wire:click="$set('confirmingErasure', true)">
                    Erase permanently
                </button>
                <p class="admin-note">
                    Owner only. Destroys the message and the record that it was ever sent. This is the action to
                    use when somebody asks for their data to be deleted.
                </p>
            @else
                <form wire:submit="erase" class="admin-form">
                    <div class="field">
                        <label for="erase-confirm">
                            Type <strong>{{ $inquiry->email }}</strong> to confirm
                        </label>
                        <input type="text" id="erase-confirm" wire:model="confirmation" autocomplete="off">
                        @error('confirmation') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <button type="submit" class="admin-button admin-button--danger">Erase permanently</button>
                    <button type="button" class="admin-button admin-button--quiet"
                            wire:click="$set('confirmingErasure', false)">Cancel</button>
                </form>
            @endif
        @endcan
    </div>
</div>
