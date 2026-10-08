@props(['page' => null])

@php
    /** @var \App\Support\SeoData $seo */
    $seo = app(\App\Services\Seo\SeoResolver::class)->resolve($page);

    // The Open Graph image is a real rendition, not the original: prefer the
    // widest JPEG (the format every scraper can read), else the widest variant.
    $ogImage = $seo->ogImage;
    $ogVariant = null;

    if ($ogImage !== null) {
        $ogVariant = $ogImage->variantsFor('jpeg')->last()
            ?? $ogImage->variants->sortBy('width')->last();
    }

    /*
         * ABSOLUTE, deliberately, and the only image on the site that is.
         *
         * Every <img> carries a root-relative src so it resolves against
         * whatever host served the page. A scraper reading this meta tag has
         * no page to resolve against, so this one is absolutised here rather
         * than by making the whole site absolute for its sake.
         */
    $ogImageUrl = $ogVariant !== null
        ? \App\Support\AbsoluteUrl::for(
            \Illuminate\Support\Facades\Storage::disk($ogVariant->disk)->url($ogVariant->path)
        )
        : null;

    $twitterHandle = config('seo.twitter_handle');
@endphp
<title>{{ $seo->title }}</title>

@if ($seo->description)
<meta name="description" content="{{ $seo->description }}">
@endif

@if ($seo->canonical)
<link rel="canonical" href="{{ $seo->canonical }}">
@endif

@if ($seo->robots)
<meta name="robots" content="{{ $seo->robots }}">
@endif

@if ($seo->ogTitle)
<meta property="og:title" content="{{ $seo->ogTitle }}">
@endif

@if ($seo->ogDescription)
<meta property="og:description" content="{{ $seo->ogDescription }}">
@endif

@if ($seo->ogType)
<meta property="og:type" content="{{ $seo->ogType }}">
@endif

@if ($seo->canonical)
<meta property="og:url" content="{{ $seo->canonical }}">
@endif

<meta property="og:site_name" content="{{ config('seo.site_name') }}">
{{--
    og:locale follows the page, and og:locale:alternate names the other one.
    Both come from App\Support\Locale rather than config('seo.locale'), which
    was a single hard-coded value from before the site had two.
--}}
<meta property="og:locale" content="{{ \App\Support\Locale::openGraph() }}">
@foreach (\App\Support\Locale::codes() as $otherLocale)
@if ($otherLocale !== \App\Support\Locale::current())
<meta property="og:locale:alternate" content="{{ \App\Support\Locale::openGraph($otherLocale) }}">
@endif
@endforeach

@if ($ogImageUrl)
<meta property="og:image" content="{{ $ogImageUrl }}">
<meta property="og:image:width" content="{{ $ogVariant->width }}">
<meta property="og:image:height" content="{{ $ogVariant->height }}">
@if ($ogImage->alt)
<meta property="og:image:alt" content="{{ $ogImage->alt }}">
@endif
@endif

@if ($seo->twitterCard)
<meta name="twitter:card" content="{{ $seo->twitterCard }}">
@endif

@if ($twitterHandle)
<meta name="twitter:site" content="{{ $twitterHandle }}">
@endif

@if ($seo->ogTitle)
<meta name="twitter:title" content="{{ $seo->ogTitle }}">
@endif

@if ($seo->ogDescription)
<meta name="twitter:description" content="{{ $seo->ogDescription }}">
@endif

@if ($ogImageUrl)
<meta name="twitter:image" content="{{ $ogImageUrl }}">
@endif
