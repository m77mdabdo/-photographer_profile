<x-filament-panels::page>
    @php
        $locale = $this->locale();
        $url = fn (array $query = []): string => \App\Filament\Pages\Help::getUrl($query);
        $article = $this->current();
        $categories = $this->categories();
    @endphp

    <div class="studio-help" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">

        {{-- ── The question and the search box: on every view but an article ── --}}
        @if ($article === null)
            <header class="studio-help__hero">
                <h2 class="studio-help__question">{{ __('studio.help.heading') }}</h2>
                <p class="studio-help__lede">{{ __('studio.help.subheading') }}</p>

                <form class="studio-help__search" method="get" action="{{ $url() }}" role="search">
                    <label for="help-q" class="sr-only">{{ __('studio.help.search_label') }}</label>
                    <x-filament::icon icon="heroicon-o-magnifying-glass" class="studio-help__search-icon" />
                    <input id="help-q" type="search" name="q"
                           wire:model.live.debounce.300ms="q"
                           value="{{ $q }}"
                           placeholder="{{ __('studio.help.search_placeholder') }}"
                           autocomplete="off" enterkeyhint="search" spellcheck="false">
                </form>
            </header>
        @endif

        {{-- ── Search results ── --}}
        @if (trim($q) !== '')
            @php($found = $this->results())

            <section class="studio-help__results" aria-labelledby="help-results-heading" wire:key="results-{{ md5($q) }}">
                <h3 id="help-results-heading" class="studio-help__count" role="status" aria-live="polite">
                    {{ trans_choice('studio.help.results', count($found['results']), ['count' => count($found['results']), 'q' => trim($q)]) }}
                </h3>

                @if ($found['choices'] !== [])
                    <div class="studio-help__choices">
                        <p class="studio-help__choices-heading">{{ __('studio.help.choices_heading') }}</p>
                        <ul role="list">
                            @foreach ($found['choices'] as $choice)
                                <li>
                                    <a href="{{ $url(['article' => $choice['id']]) }}" class="studio-help__choice">
                                        <strong>{{ $choice['title'] }}</strong>
                                        <span>{{ $choice['summary'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($found['results'] === [])
                    <p class="studio-help__empty" role="status">{{ __('studio.help.no_results_tasks', ['q' => trim($q)]) }}</p>
                @else
                    <ol class="studio-help__cards" role="list">
                        @foreach ($found['results'] as $card)
                            @include('filament.pages.partials.help-card', ['card' => $card, 'href' => $url(['article' => $card['id']])])
                        @endforeach
                    </ol>
                @endif
            </section>

        {{-- ── One article ── --}}
        @elseif ($article !== null)
            @include('filament.pages.partials.help-article', ['article' => $article, 'locale' => $locale, 'url' => $url, 'categories' => $categories])

        {{-- ── An article that does not exist ── --}}
        @elseif ($this->article !== null)
            <p class="studio-help__empty" role="status">{{ __('studio.help.unknown_article') }}</p>

        {{-- ── One topic ── --}}
        @elseif ($topic !== null && isset($categories[$topic]))
            <section class="studio-help__topic" aria-labelledby="help-topic-heading">
                <nav class="studio-help__crumbs" aria-label="{{ __('studio.help.nav') }}">
                    <a href="{{ $url() }}">{{ __('studio.help.nav') }}</a>
                </nav>
                <h3 id="help-topic-heading" class="studio-help__section-heading">{{ $categories[$topic]['title'] }}</h3>
                <p class="studio-help__section-lede">{{ $categories[$topic]['about'] }}</p>
                <ol class="studio-help__cards" role="list">
                    @foreach ($this->topicCards($topic) as $card)
                        @include('filament.pages.partials.help-card', ['card' => $card, 'href' => $url(['article' => $card['id']])])
                    @endforeach
                </ol>
            </section>

        {{-- ── The full guide ── --}}
        @elseif ($guide)
            <section class="studio-help__guide" aria-labelledby="help-guide-heading">
                <nav class="studio-help__crumbs" aria-label="{{ __('studio.help.nav') }}">
                    <a href="{{ $url() }}">{{ __('studio.help.nav') }}</a>
                </nav>
                <h3 id="help-guide-heading" class="studio-help__section-heading">{{ __('studio.help.full_guide') }}</h3>
                <nav class="studio-help__toc" aria-label="{{ __('studio.help.contents') }}">
                    <ol>
                        @foreach ($this->guideSections() as $section)
                            <li><a href="#{{ $section['id'] }}">{{ $section['title'] }}</a></li>
                        @endforeach
                    </ol>
                </nav>
                @forelse ($this->guideSections() as $section)
                    <section id="{{ $section['id'] }}" class="studio-help__section">
                        <h2>{{ $section['title'] }}</h2>
                        <div class="studio-help__body">{!! $section['html'] !!}</div>
                    </section>
                @empty
                    <p>{{ __('studio.help.missing') }}</p>
                @endforelse
            </section>

        {{-- ── Home ── --}}
        @else
            <section class="studio-help__block" aria-labelledby="help-popular">
                <h3 id="help-popular" class="studio-help__section-heading">{{ __('studio.help.popular') }}</h3>
                <ul class="studio-help__chips" role="list">
                    @foreach ($this->cards(\App\Support\Help\KnowledgeBase::popular()) as $card)
                        <li><a href="{{ $url(['article' => $card['id']]) }}" class="studio-help__chip">{{ $card['title'] }}</a></li>
                    @endforeach
                </ul>
            </section>

            <section class="studio-help__block" aria-labelledby="help-browse">
                <h3 id="help-browse" class="studio-help__section-heading">{{ __('studio.help.browse') }}</h3>
                <ul class="studio-help__topics" role="list">
                    @foreach ($categories as $category)
                        <li>
                            <a href="{{ $url(['topic' => $category['key']]) }}" class="studio-help__topic-card">
                                <x-filament::icon :icon="$category['icon']" class="studio-help__topic-icon" />
                                <span class="studio-help__topic-title">{{ $category['title'] }}</span>
                                <span class="studio-help__topic-about">{{ $category['about'] }}</span>
                                <span class="studio-help__topic-count">{{ trans_choice('studio.help.topic_count', $category['count'], ['count' => $category['count']]) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="studio-help__block" aria-labelledby="help-path">
                <h3 id="help-path" class="studio-help__section-heading">{{ __('studio.help.path_heading') }}</h3>
                <p class="studio-help__section-lede">{{ __('studio.help.path_intro') }}</p>
                <ol class="studio-help__path" role="list">
                    @foreach ($this->cards(\App\Support\Help\KnowledgeBase::path()) as $i => $card)
                        <li>
                            <a href="{{ $url(['article' => $card['id']]) }}" class="studio-help__lesson">
                                <span class="studio-help__lesson-number" aria-hidden="true">{{ $i + 1 }}</span>
                                <span class="studio-help__lesson-text">
                                    <span class="sr-only">{{ __('studio.help.lesson', ['n' => $i + 1]) }}: </span>
                                    <strong>{{ $card['title'] }}</strong>
                                    <span>{{ __('studio.help.minutes', ['n' => $card['minutes']]) }}</span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            </section>

            <section class="studio-help__block" aria-labelledby="help-trouble">
                <h3 id="help-trouble" class="studio-help__section-heading">{{ __('studio.help.trouble_heading') }}</h3>
                <ul class="studio-help__list" role="list">
                    @foreach ($this->topicCards('trouble') as $card)
                        <li><a href="{{ $url(['article' => $card['id']]) }}">{{ $card['title'] }}</a></li>
                    @endforeach
                </ul>
            </section>

            <section class="studio-help__block studio-help__block--quiet">
                <a href="{{ $url(['guide' => 1]) }}" class="studio-help__guide-link">
                    <strong>{{ __('studio.help.full_guide') }}</strong>
                    <span>{{ __('studio.help.full_guide_intro') }}</span>
                </a>
            </section>
        @endif
    </div>
</x-filament-panels::page>
