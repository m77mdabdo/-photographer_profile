<div>
    <header class="module-header">
        <h1 class="admin-heading">Journal</h1>
        <p class="admin-note">{{ $articles->total() }} {{ Str::plural('piece', $articles->total()) }}</p>

        <a href="{{ route('legacy.articles.create') }}" class="admin-button">New piece</a>
    </header>

    @if ($message !== '')
        <p class="admin-flash__message" role="status" aria-live="polite">{{ $message }}</p>
    @endif

    <div class="filters">
        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" wire:model.live.debounce.400ms="search"
                   placeholder="Title, standfirst or excerpt">
        </div>

        <div class="field">
            <label for="status">Status</label>
            <select id="status" wire:model.live="status">
                <option value="">Any</option>
                @foreach ($statuses as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="category">Subject</label>
            <select id="category" wire:model.live="category">
                <option value="">Any</option>
                @foreach ($categories as $option)
                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </select>
        </div>

        @if ($search !== '' || $status !== '' || $category !== '')
            <button type="button" class="admin-button admin-button--quiet" wire:click="clearFilters">Clear</button>
        @endif
    </div>

    @if ($articles->isEmpty())
        <div class="empty-state">
            <h2>Nothing here</h2>
            <p>
                @if ($search !== '' || $status !== '' || $category !== '')
                    No pieces match that filter.
                @else
                    Nothing written yet. The journal is where the studio explains how the work gets made.
                @endif
            </p>
        </div>
    @else
        <ul class="category-list" role="list">
            @foreach ($articles as $article)
                <li class="project-row" wire:key="article-{{ $article->id }}">
                    @if ($article->featured)
                        <span class="project-row__cover">
                            <x-picture :media="$article->featured" sizes="80px" img-class="project-row__thumb" />
                        </span>
                    @else
                        <span class="project-row__placeholder" aria-hidden="true"></span>
                    @endif

                    <span class="project-row__main">
                        <a href="{{ route('legacy.articles.edit', $article) }}" class="project-row__title">
                            {{ $article->title }}
                        </a>
                        <x-admin.translation-state :record="$article" />

                        <span class="project-row__meta">
                            @if ($article->published_at)
                                {{ $article->published_at->format('j M Y') }}
                            @else
                                Not dated
                            @endif
                            @if ($article->category) · {{ $article->category->name }} @endif
                            · {{ $article->readingMinutes() }} min
                        </span>

                        @if ($summary = $article->summary(110))
                            <span class="project-row__meta">{{ $summary }}</span>
                        @endif
                    </span>

                    <span class="project-row__status">
                        <span class="badge badge--{{ $article->status->value }}">{{ $article->status->label() }}</span>
                    </span>

                    <span class="project-row__actions">
                        <button type="button" class="admin-link" wire:click="togglePublish({{ $article->id }})">
                            {{ $article->status === \App\Enums\PublishStatus::Published ? 'Unpublish' : 'Publish' }}
                        </button>

                        <button type="button" class="admin-link"
                                wire:click="delete({{ $article->id }})"
                                wire:confirm="Move “{{ $article->title }}” to the bin? It can be restored.">
                            Bin
                        </button>
                    </span>
                </li>
            @endforeach
        </ul>

        {{ $articles->links() }}
    @endif
</div>
