@php use App\Support\Settings; @endphp
<x-layouts::public :seo="$seo">
    {{--
        BlogPosting, built in App\Support\StructuredData from the same record
        this template renders. JSON_UNESCAPED_SLASHES keeps URLs readable;
        JSON_HEX_TAG escapes < and > to < and >, so a title
        containing "</script>" cannot break out of this block — the one XSS
        that JSON-LD is actually prone to.
    --}}
    <script type="application/ld+json">
        {!! json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
    </script>

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
                    {{ $article->published_at?->format('j F Y') }}
                </time>

                <span aria-hidden="true">·</span>
                {{ $article->readingMinutes() }} min
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

        <nav class="piece__onward" aria-label="Where to go next">
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
