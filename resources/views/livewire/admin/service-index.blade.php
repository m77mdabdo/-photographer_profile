<div>
    <header class="module-header">
        <h1 class="admin-heading">Services</h1>
        <p class="admin-note">{{ $services->count() }} {{ Str::plural('service', $services->count()) }}</p>
        <a href="{{ route('admin.services.create') }}" class="admin-button">New service</a>
    </header>

    @if ($message !== '')
        <p class="admin-flash__message" role="status" aria-live="polite">{{ $message }}</p>
    @endif

    <div class="filters">
        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" wire:model.live.debounce.400ms="search" placeholder="Name or summary">
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

        @if ($search !== '' || $status !== '')
            <button type="button" class="admin-button admin-button--quiet" wire:click="clearFilters">Clear</button>
        @endif
    </div>

    @if ($services->isEmpty())
        <div class="empty-state">
            <h2>Nothing here</h2>
            <p>
                @if ($search !== '' || $status !== '')
                    No services match that filter.
                @else
                    Nothing listed yet. Services are the ways the studio works, and what each one starts at.
                @endif
            </p>
        </div>
    @else
        <ul class="category-list" role="list">
            @foreach ($services as $service)
                <li class="project-row" wire:key="service-{{ $service->id }}">
                    @if ($service->cover)
                        <span class="project-row__cover">
                            <x-picture :media="$service->cover" sizes="80px" img-class="project-row__thumb" />
                        </span>
                    @else
                        <span class="project-row__placeholder" aria-hidden="true"></span>
                    @endif

                    <span class="project-row__main">
                        <a href="{{ route('admin.services.edit', $service) }}" class="project-row__title">
                            {{ $service->name }}
                        </a>
                        <x-admin.translation-state :record="$service" />

                        <span class="project-row__meta">
                            {{ $service->priceFrom() ? 'From '.$service->priceFrom() : 'Quoted on request' }}
                            @if ($service->price_note) · {{ $service->price_note }} @endif
                        </span>

                        @if ($summary = $service->summaryText(110))
                            <span class="project-row__meta">{{ $summary }}</span>
                        @endif
                    </span>

                    <span class="project-row__status">
                        <span class="badge badge--{{ $service->status->value }}">{{ $service->status->label() }}</span>
                    </span>

                    <span class="project-row__actions">
                        {{-- Arrows rather than a drag handle: a rate card is five
                             rows, and this needs no JavaScript at all. --}}
                        <button type="button" class="admin-link" wire:click="move({{ $service->id }}, -1)"
                                @disabled($loop->first) aria-label="Move up">↑</button>
                        <button type="button" class="admin-link" wire:click="move({{ $service->id }}, 1)"
                                @disabled($loop->last) aria-label="Move down">↓</button>

                        <button type="button" class="admin-link" wire:click="togglePublish({{ $service->id }})">
                            {{ $service->status === \App\Enums\PublishStatus::Published ? 'Unpublish' : 'Publish' }}
                        </button>

                        <a href="{{ route('admin.services.edit', $service) }}" class="admin-link">Edit</a>

                        <button type="button" class="admin-link"
                                wire:click="delete({{ $service->id }})"
                                wire:confirm="Move “{{ $service->name }}” to the bin? It can be restored.">Bin</button>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
