@props(['clients'])
@if ($clients->isNotEmpty())
    {{--
        Accessibility, stated plainly.

        The animated track is duplicated to make the loop seamless, and a
        duplicate is a screen reader hearing every client twice and a keyboard
        user tabbing through the same links twice. So:

          - the real, authoritative list is the first track. It is a normal
            <ul> of normal links: focusable, in order, announced once.
          - the second track is aria-hidden and its links are removed from the
            tab order entirely. It exists only to fill the right-hand side of
            the loop.
          - the whole strip pauses on :hover and on :focus-within, so tabbing
            into it stops the motion rather than moving a link out from under
            the focus ring.
    --}}
    <section class="marquee" aria-labelledby="clients-heading">
        {{--
            The heading is now visible rather than screen-reader-only: a small
            mono label naming what the moving row is. Its text comes from the
            settings table like every other string on the page.
        --}}
        <h2 class="marquee__label" id="clients-heading">{{ \App\Support\SiteCopy::get('marquee.label') }}</h2>

        <div class="marquee__viewport">
            <ul class="marquee__track" role="list">
                @foreach ($clients as $client)
                    <li class="marquee__item">
                        @if ($client->url)
                            <a href="{{ $client->url }}" rel="noopener"><x-t :of="$client" field="name" /></a>
                        @else
                            <span><x-t :of="$client" field="name" /></span>
                        @endif
                    </li>
                @endforeach
            </ul>

            <ul class="marquee__track" role="list" aria-hidden="true">
                @foreach ($clients as $client)
                    <li class="marquee__item">
                        @if ($client->url)
                            <a href="{{ $client->url }}" tabindex="-1" rel="noopener"><x-t :of="$client" field="name" /></a>
                        @else
                            <span><x-t :of="$client" field="name" /></span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endif
