<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Media preview — dev</title>
    <style>
        :root { color-scheme: light dark; }
        body { font: 14px/1.5 ui-monospace, SFMono-Regular, Menlo, monospace; margin: 0; padding: 24px; }
        h1 { font-size: 18px; }
        h2 { font-size: 15px; margin-top: 2.5rem; }
        .meta { background: #0001; padding: 8px 12px; border-radius: 4px; margin-bottom: 16px; }
        .meta span { margin-right: 18px; }
        .swatch { display: inline-block; width: 12px; height: 12px; vertical-align: -1px; border: 1px solid #0003; }
        .row { display: flex; gap: 16px; align-items: flex-start; margin-bottom: 24px; flex-wrap: wrap; }
        .w100 { width: 100%; }
        .w50 { width: calc(50% - 8px); }
        .w25 { width: calc(25% - 12px); }
        .label { font-size: 12px; opacity: .7; margin-bottom: 4px; }
        pre { background: #0001; padding: 12px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
        hr { border: 0; border-top: 1px solid #0002; margin: 32px 0; }

        /* resources/css/components/picture.css, inlined — no build step yet. */
        picture { display: block; }
        picture img { display: block; width: 100%; height: auto; background-repeat: no-repeat; background-size: cover; background-position: center; }
        [data-media-placeholder] { display: block; width: 100%; }
    </style>
</head>
<body>
    <h1>Media preview ({{ $items->count() }} ready)</h1>

    @forelse ($items as $item)
        <h2>#{{ $item->id }} — {{ $item->uuid }}</h2>

        <div class="meta">
            <span>{{ $item->width }}×{{ $item->height }}</span>
            <span>status: {{ $item->status->value }} ({{ $item->status->label() }})</span>
            <span>dominant: <span class="swatch" style="background: {{ $item->dominant_color }}"></span> {{ $item->dominant_color }}</span>
            <span>variants: {{ $item->variants->count() }}</span>
            <span>formats: {{ $item->variants->pluck('format')->unique()->sort()->implode(', ') }}</span>
            <span>lqip: {{ strlen((string) $item->lqip) }} bytes</span>
        </div>

        <div class="label">full width — sizes="100vw"</div>
        <div class="row"><div class="w100"><x-picture :media="$item" sizes="100vw" /></div></div>

        <div class="label">50% — sizes="50vw"</div>
        <div class="row"><div class="w50"><x-picture :media="$item" sizes="50vw" /></div></div>

        <div class="label">25% — sizes="25vw"</div>
        <div class="row"><div class="w25"><x-picture :media="$item" sizes="25vw" /></div></div>

        <div class="label">priority=true (LCP candidate: no loading attribute, fetchpriority=high, decoding=sync)</div>
        <div class="row"><div class="w50"><x-picture :media="$item" sizes="50vw" :priority="true" /></div></div>

        <div class="label">decorative=true (alt="")</div>
        <div class="row"><div class="w50"><x-picture :media="$item" sizes="50vw" :decorative="true" /></div></div>

        <div class="label">generated markup (full width instance)</div>
        <pre>{{ $markup[$item->id] }}</pre>

        <hr>
    @empty
        <p>No ready media rows. Upload one and run <code>php artisan queue:work --queue=media</code>.</p>
    @endforelse
</body>
</html>
