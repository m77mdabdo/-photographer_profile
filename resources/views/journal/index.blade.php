@php use App\Support\Settings; @endphp
<x-layouts::public :seo="$seo">
    <section class="journal" aria-labelledby="journal-title">
        <header class="journal__header">
            <p class="journal__eyebrow">{{ Settings::get('nav.journal') }}</p>

            <h1 class="journal__title" id="journal-title">
                {{ $category?->name ?? Settings::get('journal.heading') }}
            </h1>

            @if ($standfirst = $category?->description ?? Settings::get('journal.standfirst'))
                <p class="journal__standfirst">{{ $standfirst }}</p>
            @endif

            <p class="journal__count">
                {{ \App\Support\PagerUnits::label('piece', $articles->total()) }}
            </p>
        </header>

        {{--
            Category filtering. Plain links to real addresses — each category
            is an indexable landing page, not a state this page can be put
            into, so there is nothing here for JavaScript to do. The same
            reasoning as the work index, and the same markup.
        --}}
        @if ($categories->isNotEmpty())
            <nav class="work-filter" aria-label="{{ \App\Support\SiteCopy::get('journal.filter_label') }}">
                <a href="{{ route('journal.index') }}"
                   @class(['work-filter__option', 'is-current' => $category === null])
                   @if ($category === null) aria-current="page" @endif>
                    {{ \App\Support\SiteCopy::get('journal.filter_all') }}
                </a>

                @foreach ($categories as $option)
                    <a href="{{ route('journal.category', $option) }}"
                       @class(['work-filter__option', 'is-current' => $category?->is($option)])
                       @if ($category?->is($option)) aria-current="page" @endif>
                        <x-t :of="$option" field="name" />
                    </a>
                @endforeach
            </nav>
        @endif

        @if ($articles->isEmpty())
            {{-- An empty state that reads as a decision rather than a fault. --}}
            <div class="work-empty">
                <p class="work-empty__line">
                    @if ($category !== null)
                        {{ \App\Support\SiteCopy::line('journal.empty_filtered', ['category' => \App\Support\Fallback::isolate($category->name, $category, 'name')]) }}
                    @else
                        {{ \App\Support\SiteCopy::get('journal.empty') }}
                    @endif
                </p>
                <p class="work-empty__note">{{ \App\Support\SiteCopy::get('journal.empty_note') }}</p>

                @if ($category !== null)
                    <a class="work-empty__back" href="{{ route('journal.index') }}">{{ \App\Support\SiteCopy::get('journal.read_everything') }}</a>
                @endif
            </div>
        @else
            <ol class="entries" role="list">
                @foreach ($articles as $article)
                    <li class="entry">
                        <article>
                            @if ($article->featured)
                                <a class="entry__frame" href="{{ route('journal.show', $article) }}" tabindex="-1" aria-hidden="true">
                                    {{--
                                        The first entry's image is the LCP on
                                        this page; everything below the fold
                                        waits. Never lazy-load the LCP.
                                    --}}
                                    <x-picture :media="$article->featured"
                                               sizes="(max-width: 700px) 100vw, 34vw"
                                               :priority="$loop->first"
                                               img-class="entry__image" />
                                </a>
                            @endif

                            <div class="entry__body">
                                <p class="entry__meta">
                                    <time datetime="{{ $article->published_at?->toDateString() }}">
                                        {{ \App\Support\Dates::long($article->published_at) }}
                                    </time>
                                    @if ($article->category)
                                        <span aria-hidden="true">·</span>
                                        <x-t :of="$article->category" field="name" />
                                    @endif
                                    <span aria-hidden="true">·</span>
                                    {{ $article->readingMinutes() }} {{ \App\Support\SiteCopy::get('shared.read_time_unit') }}
                                </p>

                                <h2 class="entry__title">
                                    <a href="{{ route('journal.show', $article) }}"><x-t :of="$article" field="title" /></a>
                                </h2>

                                @if ($summary = $article->summary(180))
                                    <p class="entry__excerpt"{!! \App\Support\Fallback::attributes($article, $article->summarySource()) !!}>{{ $summary }}</p>
                                @endif
                            </div>
                        </article>
                    </li>
                @endforeach
            </ol>

            {{ $articles->links() }}
        @endif
    </section>
</x-layouts::public>
