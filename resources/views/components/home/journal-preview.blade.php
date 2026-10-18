@props(['articles'])
@php use App\Support\Settings; @endphp
@if ($articles->isNotEmpty())
    {{--
        Three most recent pieces, as a strip of text.

        Deliberately without images, even though every one of these has a
        featured frame. The home page is already a page of photographs; a
        fourth grid of pictures would read as more of the same and would bury
        the one section whose point is that there are words here. Set as type,
        it changes register, which is the only way a reader notices it at all.
    --}}
    <section class="journal-strip" data-motion="reveal rule" aria-labelledby="journal-strip-title">
        <header class="journal-strip__header">
            <h2 class="journal-strip__title" id="journal-strip-title">
                {{ Settings::get('journal.home_heading') }}
            </h2>
        </header>

        <ol class="journal-strip__list" role="list">
            @foreach ($articles as $article)
                <li class="journal-strip__item" data-reveal-item>
                    <article>
                        <p class="journal-strip__meta">
                            <time datetime="{{ $article->published_at?->toDateString() }}">
                                {{ \App\Support\Dates::long($article->published_at) }}
                            </time>
                            @if ($article->category)
                                <span aria-hidden="true">·</span>
                                <x-t :of="$article->category" field="name" />
                            @endif
                        </p>

                        <h3 class="journal-strip__name">
                            <a href="{{ route('journal.show', $article) }}"><x-t :of="$article" field="title" /></a>
                        </h3>

                        @if ($summary = $article->summary(120))
                            <p class="journal-strip__excerpt"{!! \App\Support\Fallback::attributes($article, $article->summarySource()) !!}>{{ $summary }}</p>
                        @endif
                    </article>
                </li>
            @endforeach
        </ol>

        <a class="journal-strip__cta" href="{{ route('journal.index') }}">
            {{ Settings::get('journal.home_cta') }}
            <span class="journal-strip__rule" aria-hidden="true"></span>
        </a>
    </section>
@endif
