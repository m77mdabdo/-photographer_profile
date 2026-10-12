<x-layouts::public :seo="$seo">
    {{--
        A flat editorial page: privacy, terms, whatever the studio adds.

        Deliberately plain. This is the one kind of page on the site where a
        reader is looking for a specific sentence rather than reading for
        pleasure, so it gets a narrow measure, generous headings and nothing
        else competing for attention.
    --}}
    <article class="document">
        <header class="document__header">
            <h1 class="document__title">{{ $page->title }}</h1>

            @if ($page->standfirst)
                <p class="document__standfirst">{{ $page->standfirst }}</p>
            @endif

            @if ($page->updated_at)
                <p class="document__updated">
                    {{ \App\Support\SiteCopy::get('shared.last_updated') }}
                    <time datetime="{{ $page->updated_at->toDateString() }}">{{ \App\Support\Dates::long($page->updated_at) }}</time>
                </p>
            @endif
        </header>

        {{-- Sanitiser output, and nothing else, for the same reasons as the journal. --}}
        <div class="document__body">
            {!! $page->body_html !!}
        </div>
    </article>
</x-layouts::public>
