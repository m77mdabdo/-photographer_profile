# Media pipeline — operational notes

Measured on this machine (macOS, Apple Silicon, PHP 8.5.2, Homebrew). These figures
come from real runs, not estimates; see the sections below before changing worker
counts or quality settings.

## Encoder availability

GD 2.3.3 is the **only** image encoder installed. There is no Imagick extension and no
libvips, so GD is the single code path for every resize and encode.

| Format | Supported |
| ------ | --------- |
| JPEG   | yes |
| PNG    | yes |
| WebP   | yes |
| AVIF   | yes |
| HEIC / HEIF | **no** |

HEIC and HEIF are unsupported. iPhone photos in their native format cannot be decoded
at all, which is why `StoreMediaRequest` returns a message telling the user to export
as JPEG first. Adding HEIC support means installing Imagick or libvips, not a config
change.

GD also discards EXIF on write, so orientation is read separately through the `exif`
extension and baked into the pixels during processing.

## Throughput

One 4000×2667 JPEG (6.7 MB) produces **14 variants in roughly 4 seconds** of job time
(6 AVIF + 6 WebP + 2 JPEG). Wall clock for a `queue:work --stop-when-empty` cycle
including framework boot is about 7 seconds.

Output for that image totals roughly 830 KB across all 14 files.

## Memory — the important constraint

Peak resident set size per worker process is **approximately 302 MB** while processing
a 4000×2667 source.

**PHP's `memory_limit` does not constrain GD.** GD allocates its pixel buffers outside
PHP's allocator, so `memory_get_peak_usage()` reports about 15 MB for work that really
consumes around 300 MB of RSS. Raising or lowering `memory_limit` will neither prevent
nor cause an out-of-memory kill here.

The practical consequence: **cap media worker concurrency by available RAM, not by
`memory_limit`.**

> Recommended: **at most one media worker per 512 MB of available RAM.**

A 2 GB container should run no more than 3 media workers, leaving headroom for the web
process. Exceeding this produces OOM kills that PHP cannot report, because the kernel
terminates the process before any PHP error handler runs.

## Retuning quality

Quality settings live in `config/media.php`:

```php
'formats' => [
    'avif' => ['quality' => 50, 'widths' => 'all'],
    'webp' => ['quality' => 78, 'widths' => 'all'],
    'jpeg' => ['quality' => 82, 'widths' => [1080, 1920]],
],
```

To retune, change the values and re-dispatch `ProcessMediaVariants` for the affected
rows. **The job is idempotent**: variants are written with `updateOrCreate` keyed on
`[media_id, format, width]`, so a rerun overwrites the files and updates the existing
rows in place. It never duplicates rows and never orphans them. Widths removed from the
config are not deleted by a rerun — prune those separately if it matters.

The job also never upscales: any configured width larger than the oriented source width
is skipped, so a 800px original yields only the 480 and 768 rungs.
