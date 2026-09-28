<div>
    <header class="module-header">
        <h1 class="admin-heading">Enquiries</h1>
        <p class="admin-note">
            {{ $inquiries->total() }} shown
            @if ($unread > 0) · <strong>{{ $unread }} unread</strong> @endif
        </p>
    </header>

    <div class="filters">
        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" wire:model.live.debounce.400ms="search"
                   placeholder="Name, email, company or message">
        </div>

        <div class="field">
            <label for="status">Status</label>
            <select id="status" wire:model.live="status">
                <option value="open">Open</option>
                <option value="all">All</option>
                @foreach ($statuses as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </select>
        </div>

        @if ($search !== '' || $status !== 'open')
            <button type="button" class="admin-button admin-button--quiet" wire:click="clearFilters">Clear</button>
        @endif
    </div>

    @if ($inquiries->isEmpty())
        <div class="empty-state">
            <h2>Nothing here</h2>
            <p>
                @if ($search !== '' || $status !== 'open')
                    No enquiries match that filter.
                @else
                    No open enquiries. New ones arrive from the contact page.
                @endif
            </p>
        </div>
    @else
        <ul class="category-list" role="list">
            @foreach ($inquiries as $inquiry)
                {{--
                    The status appears once, on the right, with every other
                    row's. An unread row is marked by the rule down its edge
                    instead — a second "New" badge beside the name was saying
                    the same thing twice in the same line of sight.
                --}}
                <li @class(['project-row', 'project-row--unread' => $inquiry->status->isUnread()])
                    wire:key="inquiry-{{ $inquiry->id }}">
                    <span class="project-row__main">
                        <a href="{{ route('admin.inquiries.show', $inquiry) }}" class="project-row__title">
                            {{ $inquiry->name }}
                        </a>
                        <span class="project-row__meta">
                            {{ $inquiry->email }}
                            @if ($inquiry->company) · {{ $inquiry->company }} @endif
                            @if ($inquiry->project_type) · {{ $inquiry->project_type }} @endif
                        </span>
                        <span class="project-row__meta">{{ Str::limit($inquiry->message, 120) }}</span>
                    </span>

                    <span class="project-row__status">
                        <span class="badge badge--{{ $inquiry->status->value }}">{{ $inquiry->status->label() }}</span>
                        <span class="admin-note">{{ $inquiry->created_at?->diffForHumans() }}</span>
                    </span>
                </li>
            @endforeach
        </ul>

        {{ $inquiries->links() }}
    @endif
</div>
