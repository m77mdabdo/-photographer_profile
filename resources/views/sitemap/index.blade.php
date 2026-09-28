<?php echo '<?xml version="1.0" encoding="UTF-8"?>'."\n"; ?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($segments as $segment)
    <sitemap>
        <loc>{{ $segment['loc'] }}</loc>
@if ($segment['lastmod'])
        <lastmod>{{ $segment['lastmod'] }}</lastmod>
@endif
    </sitemap>
@endforeach
</sitemapindex>
