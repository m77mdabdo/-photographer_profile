<div>
    <header class="module-header">
        <h1 class="admin-heading">Projects</h1>
        <p class="admin-note">{{ $projects->total() }} {{ Str::plural('project', $projects->total()) }}</p>
        <a href="{{ route('legacy.projects.create') }}" class="admin-button">New project</a>
    </header>

    @if ($message !== '')
        <p class="admin-flash__message" role="status" aria-live="polite">{{ $message }}</p>
    @endif

    <div class="filters" role="search">
        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" wire:model.live.debounce.400ms="search"
                   placeholder="Title, client or location">
        </div>

        <div class="field">
            <label for="status">Status</label>
            <select id="status" wire:model.live="status">
                <option value="">All statuses</option>
                @foreach ($statuses as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="category">Category</label>
            <select id="category" wire:model.live="category">
                <option value="">All categories</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                @endforeach
            </select>
        </div>

        @if ($search !== '' || $status !== '' || $category !== '')
            <button type="button" class="admin-link" wire:click="clearFilters">Clear</button>
        @endif
    </div>

    @if ($projects->isEmpty())
        <div class="empty-state">
            <h2>No projects yet</h2>

            @if ($search !== '' || $status !== '' || $category !== '')
                <p>No project matches those filters.</p>
                <button type="button" class="admin-button" wire:click="clearFilters">Clear filters</button>
            @else
                <p>
                    A project is one body of work: a shoot, a commission, a series. Create one,
                    give it a cover image from the media library, then build its story out of
                    blocks. Nothing is visible publicly until you publish it.
                </p>
                <a href="{{ route('legacy.projects.create') }}" class="admin-button">Create the first project</a>
            @endif
        </div>
    @else
        @if ($orderDirty)
            {{-- The order lives in the browser until this is pressed. --}}
            <div class="order-bar" role="status" aria-live="polite">
                <span>Order changed but not saved.</span>
                <button type="button" class="admin-button" wire:click="saveOrder">Save order</button>
            </div>
        @endif

        <ul class="project-list" role="list" data-reorderable data-reorder-handler="reorder">
            @foreach ($projects as $project)
                <li class="project-row" wire:key="project-{{ $project->id }}" data-id="{{ $project->id }}">
                    {{--
                        The drag handle is a button so it is reachable by keyboard;
                        Up/Down move the row without a pointer.
                    --}}
                    <button type="button" class="project-row__handle" data-drag-handle
                            aria-label="Reorder {{ $project->title }}. Use the up and down arrow keys to move it.">
                        ⠿
                    </button>

                    <span class="project-row__cover">
                        @if ($project->cover && $project->cover->variants->isNotEmpty())
                            <x-picture :media="$project->cover" sizes="120px" img-class="project-row__image" />
                        @else
                            <span class="project-row__placeholder" aria-hidden="true"></span>
                        @endif
                    </span>

                    <span class="project-row__main">
                        <a href="{{ route('legacy.projects.edit', $project) }}" class="project-row__title">
                            {{ $project->title }}
                            @if ($project->is_demo)
                                {{--
                                    Seeded demonstration content. Marked at the
                                    title rather than in a corner, because the
                                    thing a client needs to tell apart is the
                                    series itself, not a row of a table — and a
                                    plausible client name attached to a series
                                    nobody was paid for is exactly the kind of
                                    thing that survives into a launch.
                                --}}
                                <span class="badge badge--demo" title="Seeded demonstration content">Demo</span>
                            @endif
                        </a>
                        <x-admin.translation-state :record="$project" />
                        <span class="project-row__meta">
                            {{ $project->slug }}
                            @if ($project->year) · {{ $project->year }} @endif
                            @if ($project->client) · {{ $project->client }} @endif
                        </span>
                        <span class="project-row__cats">
                            @foreach ($project->categories as $cat)
                                <span class="badge">{{ $cat->name }}</span>
                            @endforeach
                        </span>
                    </span>

                    <span class="project-row__status">
                        <span class="badge badge--{{ $project->status->value }}"
                              data-status="{{ $project->status->value }}">
                            {{ $project->status->label() }}
                        </span>
                        @if ($project->is_featured)
                            <span class="badge badge--used">Featured</span>
                        @endif
                    </span>

                    <span class="project-row__actions">
                        <button type="button" class="admin-link" wire:click="togglePublish({{ $project->id }})">
                            {{ $project->status === \App\Enums\PublishStatus::Published ? 'Unpublish' : 'Publish' }}
                        </button>

                        <button type="button" class="admin-link" wire:click="toggleFeatured({{ $project->id }})">
                            {{ $project->is_featured ? 'Unfeature' : 'Feature' }}
                        </button>

                        <a href="{{ route('legacy.projects.edit', $project) }}" class="admin-link">Edit</a>

                        {{--
                            The story builder had no link anywhere in the admin.
                            The route and the component both existed and the only
                            way to reach it was to type the URL — found when the
                            browser harness could not discover a link to a page it
                            was meant to check.
                        --}}
                        <a href="{{ route('legacy.projects.story', $project) }}" class="admin-link">Story</a>
                    </span>
                </li>
            @endforeach
        </ul>

        {{ $projects->links() }}
    @endif
</div>
