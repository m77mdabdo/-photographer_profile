{{--
    The public paginator.

    Laravel's default is a Tailwind component, and the public bundle contains
    no Tailwind utilities — it is a hand-written stylesheet of semantic
    classes. Using the default here would have produced an unstyled row of
    grey buttons on every paged page.

    Previous / next only, with a position readout. A numbered pager invites a
    visitor to jump to page four of a body of work, which is not how anyone
    looks at photographs; the count tells them how far in they are, which is
    what they actually want to know.
--}}
@if ($paginator->hasPages())
    <nav class="pager" aria-label="{{ $ariaLabel ?? 'Pagination' }}">
        @if ($paginator->onFirstPage())
            <span class="pager__step pager__step--spent" aria-hidden="true">Previous</span>
        @else
            <a class="pager__step" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
        @endif

        <p class="pager__position">
            Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}
            <span class="pager__total">{{ $paginator->total() }} {{ Str::plural($unit ?? 'item', $paginator->total()) }}</span>
        </p>

        @if ($paginator->hasMorePages())
            <a class="pager__step" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
        @else
            <span class="pager__step pager__step--spent" aria-hidden="true">Next</span>
        @endif
    </nav>
@endif
