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
a 4000×2667 (10.7 MP) source.

**That figure was measured before the working-copy guard existed, and the guard
changed it substantially.**

The job used to decode the full source once per rendition — fourteen decodes of the
same file. It now decodes once, scales to a working budget, and generates every
rendition from that copy. Same output, thirteen fewer full decodes.

| Source | Megapixels | Peak RSS before | Peak RSS after |
| ------ | ---------: | --------------: | -------------: |
| 4000×2667 | 10.7 | ~302 MB | ~302 MB (inside the budget, unchanged) |
| 5464×8196 | 44.8 | **1.32 GB** | **565 MB** |

Two settings govern this, both in `config/media.php`:

- **`max_megapixels: 50`** — the upload ceiling. This is a memory ceiling in disguise:
  GD decodes the whole bitmap before anything can measure it, at roughly 29.5 MB of
  resident memory per megapixel. 50 MP bounds a single decode near 1.5 GB and refuses
  anything larger at the door rather than in the worker. It accommodates every current
  full-frame body and most medium format; a 61 MP A7R file would be refused.
- **`working_megapixels: 16`** — the in-memory working budget. ~4900×3266 for a 3:2
  frame, which is 1.9× the longest variant edge (2560px) in linear terms: ample
  oversampling for a clean downsample. **The file on disk is never altered.**

> Recommended: **at most one media worker per 768 MB of available RAM, and the worker
> MUST be restarted on a job count — see below.**
>
> A 2 GB container can now run two media workers where it could previously run one,
> and the previous advice of one per 512 MB was unsafe at any size above about 12 MP.

Raising `max_megapixels` without also raising the RAM per worker is how this comes
back: the decode happens before the guard can help.

## Neither `memory_limit` nor `queue:work --memory` constrains GD

Stated plainly, because both look like they should:

- **`php.ini` `memory_limit` does not apply.** It bounds PHP's own allocator. GD's
  image buffers are allocated by the C library, outside it. A worker with
  `memory_limit=256M` will happily hold 756 MB of resident memory and never raise
  "Allowed memory size exhausted".
- **`queue:work --memory` does not apply either**, for the same reason: it compares
  `memory_get_usage()` against the limit, and that function reports the same PHP heap.

Measured in one process, processing a single 48.8 MP image:

| | |
| --- | --- |
| PHP heap after the job | **32 MB** ← what both limits see |
| PHP peak heap | 39 MB |
| Actual process RSS | **756 MB** ← what the OS sees |

So the only limit that works is one that does not ask PHP how much memory it is using:
`--max-jobs`, which counts jobs. This is why `composer dev` runs the media worker as
`queue:work --queue=media --max-jobs=25` rather than relying on any memory setting.

(`queue:listen` is immune, because it forks a fresh process per job and the memory goes
back when that process exits. It is also several times slower, which is why the media
queue uses `queue:work` with a job ceiling instead.)

## The worker leaks, and `--memory` cannot see it

Measured while processing 145 images in one serial run, and then isolated with a
ten-job run sampling RSS every second:

| | |
| --- | --- |
| Worker RSS at start | 117 MB |
| After 10 jobs | **1386 MB**, climbing monotonically, never released |
| Peak across the real 145-job run | **1022 MB** |

The per-job figure of ~565 MB is still correct. What is new is that **the memory is not
returned between jobs**, so a long-lived worker grows until the OS kills it.

`queue:work --memory` does not help, and this is the important part:

```
48.8 MP image, one job, measured in the same process:
  PHP heap after job  :  32 MB   ← what queue:work --memory checks
  PHP peak heap       :  39 MB
  actual process RSS  : 756 MB
```

GD allocates its image buffers through the C library, outside PHP's allocator.
`memory_get_usage()` therefore reports 32 MB while the process is holding 756 MB, and
the worker's built-in memory guard — whatever you set it to — will never fire.

**Run media workers with an explicit job ceiling:**

```
php artisan queue:work --queue=media --max-jobs=25
```

`--max-jobs` restarts the worker after a fixed number of jobs regardless of what
`memory_get_usage()` claims, which is the only limit that sees this. Under Supervisor
or Horizon, pair it with `autorestart=true` so the exit is immediately replaced.

For a one-off backfill, `--stop-when-empty` is fine if the queue is short. The 145-image
run in this project peaked at 1022 MB on a machine with room for it; the same run on a
1 GB container would have been OOM-killed part way through with no error in the log.

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
