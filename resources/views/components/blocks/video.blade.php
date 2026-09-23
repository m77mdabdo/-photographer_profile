@props(['block', 'media', 'credits' => null, 'descriptions' => []])
@php($url = $block->data['url'] ?? null)
@if (filled($url))
    {{--
        A facade, not an iframe. Nothing third-party loads until the visitor
        asks for it: no tracking cookies on page load, no render-blocking
        script, and the link works perfectly with JavaScript disabled.
    --}}
    <figure class="block block--video">
        <a class="block__facade" href="{{ $url }}" rel="noopener noreferrer" target="_blank">
            @php($poster = $media->first())
            @if ($poster)
                <x-picture :media="$poster" sizes="(max-width: 900px) 100vw, 900px" img-class="block__image"
                           :alt="$descriptions[0] ?? ($block->data['poster_alt'] ?? null)" />
            @endif
            <span class="block__facade-label">
                <span class="block__facade-icon" aria-hidden="true">▶</span>
                Watch the video
                <span class="block__facade-host">{{ parse_url($url, PHP_URL_HOST) }}</span>
            </span>
        </a>
        @if (filled($block->data['caption'] ?? null))
            <figcaption class="block__caption">{{ $block->data['caption'] }}</figcaption>
        @endif
    </figure>
@endif
