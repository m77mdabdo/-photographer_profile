<?php echo '<?xml version="1.0" encoding="UTF-8"?>'."\n"; ?>
{{-- xmlns:xhtml is what makes the alternates below legal, not decorative. --}}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($urls as $url)
    <url>
        <loc>{{ $url['loc'] }}</loc>
@foreach ($url['alternates'] ?? [] as $code => $href)
        <xhtml:link rel="alternate" hreflang="{{ \App\Support\Locale::html($code) }}" href="{{ $href }}"/>
@endforeach
@if (isset($url['alternates'][\App\Support\Locale::DEFAULT]))
        <xhtml:link rel="alternate" hreflang="x-default" href="{{ $url['alternates'][\App\Support\Locale::DEFAULT] }}"/>
@endif
@if ($url['lastmod'])
        <lastmod>{{ $url['lastmod'] }}</lastmod>
@endif
        <changefreq>{{ $url['changefreq'] }}</changefreq>
        <priority>{{ $url['priority'] }}</priority>
    </url>
@endforeach
</urlset>
