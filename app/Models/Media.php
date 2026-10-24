<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MediaStatus;
use App\Models\Concerns\Translatable;
use App\Support\AccentPalette;
use App\Support\ExposureLine;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    use SoftDeletes;
    use Translatable;

    protected $table = 'media';

    /**
     * id, uuid and checksum are assigned by the system, never mass assigned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'disk',
        'path',
        'original_filename',
        'extension',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'status',
        'processed_at',
        'failure_reason',
        'alt',
        'caption',
        'credit',
        'copyright',
        'focal_x',
        'focal_y',
        'lqip',
        'demo_set',
        'dominant_color',
        'palette',
        'palette_read_at',
        'uploaded_by',

        /*
         * Camera data read from the original. There is no GPS field here and
         * there never will be — see App\Support\ExifReader.
         */
        'exif_aperture',
        'exif_shutter',
        'exif_iso',
        'exif_focal_length',
        'exif_camera',
        'exif_lens',
        'captured_at',
        'exif_read_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MediaStatus::class,
            'is_demo' => 'boolean',
            'processed_at' => 'datetime',
            'focal_x' => 'float',
            'focal_y' => 'float',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'exif_aperture' => 'float',
            'exif_iso' => 'integer',
            'exif_focal_length' => 'integer',
            'captured_at' => 'datetime',
            'exif_read_at' => 'datetime',
            'palette' => 'array',
            'palette_read_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $media): void {
            if (empty($media->uuid)) {
                $media->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * The technical line for this frame, or null when it carries no camera
     * data at all. Never a partial line padded with placeholders.
     */
    public function exposureLine(): ?ExposureLine
    {
        return ExposureLine::for($this);
    }

    /**
     * The colour this photograph should lend the page.
     *
     * Chosen from the palette by salience — the colour that draws the eye,
     * not the one that covers the most area. Falls back to dominant_color for
     * rows whose palette has not been extracted yet, and to null when the
     * frame has no colour worth taking, in which case the caller keeps the
     * token accent.
     */
    public function accentSource(): ?string
    {
        /*
         * Once the palette has been read, its answer is final — INCLUDING
         * when that answer is "there is no colour here". Falling through to
         * dominant_color in that case would walk straight back into the bug
         * this replaced: a black-and-white photograph would hand its neutral
         * average to AccentColor, whose saturation floor would invent a hue
         * out of a two-unit channel difference and tint the page with it.
         *
         * dominant_color is still the answer for rows extracted before this
         * existed, which is the only case where there is nothing better.
         */
        if ($this->palette_read_at !== null) {
            return AccentPalette::select($this->palette);
        }

        return $this->dominant_color;
    }

    public function hasExifData(): bool
    {
        return $this->exif_aperture !== null
            || $this->exif_shutter !== null
            || $this->exif_iso !== null
            || $this->exif_focal_length !== null
            || $this->exif_camera !== null
            || $this->exif_lens !== null
            || $this->captured_at !== null;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return HasMany<MediaVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(MediaVariant::class);
    }

    /**
     * @return HasMany<MediaUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(MediaUsage::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeReady(Builder $query): void
    {
        $query->where('status', MediaStatus::Ready);
    }

    /**
     * Stock content, fetched to make the site judgeable before real work
     * exists. Everything it touches must be removable in one command.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDemo(Builder $query): void
    {
        $query->where('is_demo', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeReal(Builder $query): void
    {
        $query->where('is_demo', false);
    }

    /**
     * Drop every usage row pointing at this image.
     *
     * media_usages is polymorphic, so nothing cascades from the media side —
     * deleting an image would otherwise leave rows claiming it is still in
     * use, and the next purge would refuse on a phantom.
     */
    public function mediaUsagesCleanup(): void
    {
        $this->usages()->delete();
    }

    public function isReady(): bool
    {
        return $this->status === MediaStatus::Ready;
    }

    public function aspectRatio(): ?float
    {
        if ($this->width === null || $this->height === null || $this->height === 0) {
            return null;
        }

        return $this->width / $this->height;
    }

    /**
     * Variants of one format, narrowest first.
     *
     * @return Collection<int, MediaVariant>
     */
    public function variantsFor(string $format): Collection
    {
        return $this->variants
            ->where('format', $format)
            ->sortBy('width')
            ->values();
    }

    /**
     * A srcset attribute value: "<url> <width>w, ..." in ascending width order.
     */
    public function srcset(string $format): string
    {
        return $this->variantsFor($format)
            ->map(fn (MediaVariant $variant): string => sprintf(
                '%s %dw',
                Storage::disk($variant->disk)->url($variant->path),
                $variant->width
            ))
            ->implode(', ');
    }

    /**
     * The URL of the variant closest to the requested width, or the widest
     * available when no width is given. Null when the format was never built.
     */
    public function url(string $format = 'jpeg', ?int $width = null): ?string
    {
        $variants = $this->variantsFor($format);

        if ($variants->isEmpty()) {
            return null;
        }

        $variant = $width === null
            ? $variants->last()
            : $variants->sortBy(fn (MediaVariant $candidate): int => abs($candidate->width - $width))->first();

        return Storage::disk($variant->disk)->url($variant->path);
    }

    /**
     * A small rendition for admin thumbnails, in whichever modern format was
     * built. Falls back through the formats rather than assuming any one of
     * them exists, because which were generated depends on config.
     */
    public function thumbnailUrl(int $width = 480): ?string
    {
        foreach (['webp', 'jpeg', 'avif'] as $format) {
            $url = $this->url($format, $width);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    public function hasFormat(string $format): bool
    {
        return $this->variantsFor($format)->isNotEmpty();
    }
}
