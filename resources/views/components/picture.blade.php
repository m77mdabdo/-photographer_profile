@if (! $isRenderable())
    {{-- Nothing to show yet: hold the space so the page will not reflow. --}}
    <div
        style="{{ $placeholderStyle() }}"
        @if ($class) class="{{ $class }}" @endif
        aria-hidden="true"
        data-media-placeholder="{{ $media?->status?->value ?? 'missing' }}"
    ></div>
@else
<picture @if ($class) class="{{ $class }}" @endif>
    @if ($media->hasFormat('avif'))
    <source type="image/avif" srcset="{{ $media->srcset('avif') }}" sizes="{{ $sizes }}">
    @endif
    @if ($media->hasFormat('webp'))
    <source type="image/webp" srcset="{{ $media->srcset('webp') }}" sizes="{{ $sizes }}">
    @endif
    <img
        src="{{ $fallbackUrl() }}"
        @if ($media->hasFormat('jpeg')) srcset="{{ $media->srcset('jpeg') }}" @endif
        sizes="{{ $sizes }}"
        width="{{ $media->width }}"
        height="{{ $media->height }}"
        alt="{{ $resolvedAlt() }}"
        @unless ($priority) loading="{{ $loading() }}" @endunless
        fetchpriority="{{ $fetchPriority() }}"
        decoding="{{ $decoding() }}"
        style="{{ $styleAttributes() }}"
        {{ $attributes }}
    >
</picture>
@endif
