@props(['testimonials'])

{{--
    ═══════════════════════════════════════════════════════════════════════════
    WHAT CLIENTS SAID, IMMEDIATELY BEFORE THE INVITATION.

    Placed here rather than anywhere else because of what sits after it: the
    closing section is "the page the whole site leads to", and somebody else's
    words are the last thing a visitor reads before being asked to get in
    touch. A testimonial strip halfway up a portfolio interrupts the work; at
    the end it answers the question the work has just raised.

    ABSENT RATHER THAN EMPTY. A studio with no testimonials yet shows no
    section — not a heading over nothing, and certainly not a placeholder
    quote. There is no demo seeder for this table for the same reason: a
    fabricated client quote is a different kind of thing from a stock
    photograph standing in for a portfolio.
    ═══════════════════════════════════════════════════════════════════════════
--}}
@if ($testimonials->isNotEmpty())
    <section class="voices" aria-labelledby="voices-title" data-motion="reveal rule">
        <h2 class="voices__title" id="voices-title">
            {{ \App\Support\SiteCopy::line('testimonials.heading') }}
        </h2>

        <ul class="voices__list" role="list">
            @foreach ($testimonials as $testimonial)
                <li class="voice" data-reveal-item>
                    <figure class="voice__figure">
                        {{--
                            blockquote inside figure, with the attribution in
                            figcaption: the words are the quote and the name is
                            not. Putting the name inside the blockquote would
                            make a screen reader announce it as part of what
                            the client said.
                        --}}
                        <blockquote class="voice__quote">
                            <p><x-t :of="$testimonial" field="quote" /></p>
                        </blockquote>

                        <figcaption class="voice__who">
                            @if ($testimonial->portrait)
                                <x-picture :media="$testimonial->portrait"
                                           sizes="64px"
                                           img-class="voice__portrait" />
                            @endif

                            <span class="voice__name"><x-t :of="$testimonial" field="attribution" /></span>

                            @if ($role = $testimonial->role)
                                <span class="voice__role">{{ $role }}</span>
                            @endif
                        </figcaption>
                    </figure>
                </li>
            @endforeach
        </ul>
    </section>
@endif
