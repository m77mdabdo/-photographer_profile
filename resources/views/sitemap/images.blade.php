{{--
    The image sitemap.

    One <url> per page, with every photograph that page shows nested inside it —
    which is the shape the protocol wants: an image belongs to the page it
    appears on, and the same frame on two pages is two entries.

    Blade escapes every value, which is what keeps an ampersand in a caption
    from producing malformed XML.
--}}
<?php echo '<?xml version="1.0" encoding="UTF-8"?>'."\n"; ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
@foreach ($entries as $entry)
    <url>
        <loc>{{ $entry['loc'] }}</loc>
@if ($entry['lastmod'])
        <lastmod>{{ $entry['lastmod'] }}</lastmod>
@endif
@foreach ($entry['images'] as $image)
        <image:image>
            <image:loc>{{ $image['loc'] }}</image:loc>
@if (isset($image['title']))
            <image:title>{{ $image['title'] }}</image:title>
@endif
@if (isset($image['caption']))
            <image:caption>{{ $image['caption'] }}</image:caption>
@endif
        </image:image>
@endforeach
    </url>
@endforeach
</urlset>
