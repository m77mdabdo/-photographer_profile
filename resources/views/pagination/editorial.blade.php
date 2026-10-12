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
@php
    $copy = static fn (string $key, array $replace = []): string
        => \App\Support\SiteCopy::line($key, $replace);

    $previous = $copy('shared.pagination_previous');
    $next = $copy('shared.pagination_next');

    /*
     * The unit, named by the caller and resolved by PagerUnits — which owns
     * the mapping from a unit to its two settings keys, so the keys stay
     * greppable and the grammar stays out of the template.
     */
    $total = $paginator->total();
@endphp

@if ($paginator->hasPages())
    <nav class="pager" aria-label="{{ $copy($ariaLabelKey ?? 'shared.pagination_label') }}">
        @if ($paginator->onFirstPage())
            <span class="pager__step pager__step--spent" aria-hidden="true">{{ $previous }}</span>
        @else
            <a class="pager__step" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ $previous }}</a>
        @endif

        <p class="pager__position">
            {{ $copy('shared.pagination_position', [
                'current' => $paginator->currentPage(),
                'total' => $paginator->lastPage(),
            ]) }}
            <span class="pager__total">{{ \App\Support\PagerUnits::label($unit ?? null, $total) }}</span>
        </p>

        @if ($paginator->hasMorePages())
            <a class="pager__step" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ $next }}</a>
        @else
            <span class="pager__step pager__step--spent" aria-hidden="true">{{ $next }}</span>
        @endif
    </nav>
@endif
