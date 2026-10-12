{{--
    The BlogPosting and breadcrumb blocks travel to the layout, which emits
    every JSON-LD block on the page through one component. They used to be
    written out here by hand, which meant the escaping rules lived in a
    template and would have been re-derived — differently — by the next page
    that needed them.
--}}
@php use App\Support\Settings; @endphp
<x-layouts::public :seo="$seo" :structured-data="$structuredData">
    <article class="piece">
        <header class="piece__header">
            <p class="piece__eyebrow">
                @if ($article->category)
                    <a href="{{ route('journal.category', $article->category) }}">{{ $article->category->name }}</a>
                @else
                    {{ Settings::get('nav.journal') }}
                @endif
            </p>

            <h1 class="piece__title">{{ $article->title }}</h1>

            @if ($article->subtitle)
                <p class="piece__standfirst">{{ $article->subtitle }}</p>
            @endif

            <p class="piece__byline">
                {{--
                    The byline the structured data also reports: the article's
                    author, then the studio's attribution. One order, in two
                    places, so they cannot disagree.
                --}}
                @if ($author = $article->author?->name ?? Settings::get('attribution.name'))
                    <span class="piece__author">{{ $author }}</span>
                    <span aria-hidden="true">·</span>
                @endif

                <time datetime="{{ $article->published_at?->toDateString() }}">
                    {{ \App\Support\Dates::long($article->published_at) }}
                </time>

                <span aria-hidden="true">·</span>
                {{ $article->readingMinutes() }} {{ \App\Support\SiteCopy::get('shared.read_time_unit') }}
            </p>
        </header>

        @if ($article->featured)
            <figure class="piece__frame">
                {{-- The LCP on this page. Priority, never lazy. --}}
                <x-picture :media="$article->featured"
                           sizes="(max-width: 900px) 100vw, 72vw"
                           :priority="true"
                           img-class="piece__image" />

                @if ($article->featured->caption)
                    <figcaption>{{ $article->featured->caption }}</figcaption>
                @endif
            </figure>
        @endif

        {{--
            THE ONLY UNESCAPED OUTPUT ON THE PUBLIC SITE.

            body_html is written by exactly one thing — App\Support\RichText,
            through the saving hook on the Article model — and is outside
            $fillable, so no form, import or mass assignment can reach it. The
            column holds sanitiser output or nothing.

            `body` is NEVER rendered. It is the author's Markdown source, and
            printing it here unescaped would undo the whole arrangement.
        --}}
        <div class="piece__body">
            {!! $article->body_html !!}
        </div>

        @if ($article->tags->isNotEmpty())
            <footer class="piece__tags">
                <h2 class="piece__tags-title">{{ Settings::get('journal.tags_label') }}</h2>
                <ul role="list">
                    @foreach ($article->tags as $tag)
                        <li>{{ $tag->name }}</li>
                    @endforeach
                </ul>
            </footer>
        @endif

        <nav class="piece__onward" aria-label="{{ \App\Support\SiteCopy::get('shared.next_label') }}">
            <a class="enquiry__onward-link" href="{{ route('journal.index') }}">
                {{ Settings::get('journal.back_label') }}
                <span class="enquiry__submit-rule" aria-hidden="true"></span>
            </a>
            <a class="enquiry__onward-link" href="{{ route('contact.show') }}">
                {{ Settings::get('closing.cta') }}
                <span class="enquiry__submit-rule" aria-hidden="true"></span>
            </a>
        </nav>
    </article>
</x-layouts::public>
