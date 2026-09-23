@props(['block', 'media', 'credits' => null, 'descriptions' => []])
@php($url = $block->data['url'] ?? null)
@if (filled($url))
    <figure class="block block--embed">
        <a class="block__facade block__facade--plain" href="{{ $url }}" rel="noopener noreferrer" target="_blank">
            <span class="block__facade-label">
                Open on {{ parse_url($url, PHP_URL_HOST) }}
            </span>
        </a>
        @if (filled($block->data['caption'] ?? null))
            <figcaption class="block__caption">{{ $block->data['caption'] }}</figcaption>
        @endif
    </figure>
@endif
