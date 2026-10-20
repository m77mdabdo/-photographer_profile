@props(['media' => null])
{{--
    The spec table — Task 38 Part E1. Key/value, camera data only.

    READS REAL EXIF AND RENDERS NOTHING WHERE THERE IS NONE. A row exists
    only when its field does; when no field does, there is no table, no
    heading, no wrapper — not a row of dashes, not "Unknown", not an empty
    frame waiting to be filled (Task 34's rule). Today the library carries
    no EXIF at all, so the empty case is the one every page shows, and it is
    the one SpecTableTest proves first.

    Values are camera data, so their figures are Western in both languages
    and set in the mono face's figures (ADR 0005; ADR 0003's split by
    content). The labels are settings, per locale.
--}}
@php
    $rows = [];

    if ($media) {
        $aperture = $media->exif_aperture !== null
            ? 'f/'.rtrim(rtrim(number_format((float) $media->exif_aperture, 2, '.', ''), '0'), '.')
            : null;

        foreach ([
            'spec.camera' => $media->exif_camera,
            'spec.lens' => $media->exif_lens,
            'spec.focal_length' => $media->exif_focal_length !== null ? $media->exif_focal_length.'mm' : null,
            'spec.aperture' => $aperture,
            'spec.shutter' => $media->exif_shutter,
            'spec.iso' => $media->exif_iso !== null ? (string) $media->exif_iso : null,
        ] as $label => $value) {
            if (filled($value)) {
                $rows[] = [\App\Support\SiteCopy::get($label), $value];
            }
        }
    }
@endphp
@if ($rows !== [])
    <dl {{ $attributes->class(['spec']) }}>
        @foreach ($rows as [$label, $value])
            <div class="spec__row">
                <dt class="spec__label">{{ $label }}</dt>
                <dd class="spec__value"><bdi dir="ltr">{{ $value }}</bdi></dd>
            </div>
        @endforeach
    </dl>
@endif
