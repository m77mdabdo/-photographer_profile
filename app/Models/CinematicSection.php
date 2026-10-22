<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Translatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A home-page cinematic section: a lighting gallery, a featured image or a
 * featured video (cinematic lighting brief, 22 Oct 2026).
 *
 * The owner chooses lighting by NAME — direction, intensity, darkness, pace,
 * overlay, mobile layout — and lighting() turns the names into the numbers
 * the page reads. Nothing here is a free number an owner can set to 0.97.
 *
 * The server never renders a photograph darkened: the dark state is drawn by
 * resources/js/public/cinematic.js, which reduced motion and a failed script
 * both leave unloaded. Every image is fully visible without it.
 */
class CinematicSection extends Model
{
    use HasFactory;
    use SoftDeletes;
    use Translatable;

    public const TYPE_GALLERY = 'gallery';
    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';

    public const TYPES = [self::TYPE_GALLERY, self::TYPE_IMAGE, self::TYPE_VIDEO];

    /** A gallery holds four or five photographs. */
    public const GALLERY_MIN = 4;
    public const GALLERY_MAX = 5;

    /** preset => [highlight strength 0-1] */
    public const INTENSITY = ['soft' => 0.35, 'balanced' => 0.55, 'strong' => 0.8];

    /**
     * preset => [veil opacity over a photograph the light is not on].
     * In the spotlight, "Deep" and "Night" are the brief's 95-98%; without
     * it (a sweep that lights every photograph) the start darkness is lower,
     * because there every photograph ends lit.
     */
    public const DARKNESS = ['subtle' => 0.85, 'deep' => 0.95, 'night' => 0.98];
    public const DARKNESS_SWEEP = ['subtle' => 0.45, 'deep' => 0.62, 'night' => 0.78];

    /** preset => scroll distance per photograph, in viewport heights */
    public const PACE = ['slow' => 0.9, 'natural' => 0.7, 'quick' => 0.5];

    /** preset => how far either side of the active photograph the light spreads, in photographs */
    public const SOFTNESS = ['crisp' => 0.45, 'soft' => 0.7, 'gentle' => 0.95];

    /** % of the phone screen a photograph in the moving strip takes */
    public const MOBILE_WIDTHS = ['70', '80', '90'];

    /** preset => the veil's colour, from the site's own palette */
    public const OVERLAYS = ['none', 'ink', 'warm', 'cool'];

    /** auto follows the page's language: left to right in English, right to left in Arabic. */
    public const DIRECTIONS = ['auto', 'ltr', 'rtl'];

    public const MOBILE_LAYOUTS = ['track', 'grid', 'stack'];

    protected $fillable = [
        'type', 'title', 'description', 'is_active', 'sort_order',
        'light_direction', 'intensity', 'darkness', 'pace', 'overlay', 'mobile_layout', 'animate',
        'spotlight', 'softness', 'mobile_width',
        'featured_media_id', 'video_path', 'poster_media_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_demo' => 'boolean',
            'animate' => 'boolean',
            'spotlight' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $section): void {
            app(\App\Actions\Cinematic\SyncCinematicMedia::class)($section);
        });

        /*
         * Deleting releases the photographs (the library may delete them
         * again); a soft delete keeps the section restorable, and restoring
         * claims them back.
         */
        static::deleted(fn (self $section) => $section->mediaUsages()->delete());
        static::restored(fn (self $section) => app(\App\Actions\Cinematic\SyncCinematicMedia::class)($section));
    }

    /** @return HasMany<CinematicSectionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CinematicSectionItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return BelongsTo<Media, $this> */
    public function featured(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'featured_media_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'poster_media_id');
    }

    /** @return MorphMany<MediaUsage, $this> */
    public function mediaUsages(): MorphMany
    {
        return $this->morphMany(MediaUsage::class, 'usable');
    }

    public function scopeVisible(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** The video's public address, or null — never a path from outside the disk. */
    public function videoUrl(): ?string
    {
        if ($this->video_path === null || $this->video_path === '' || str_contains($this->video_path, '..')) {
            return null;
        }

        $disk = Storage::disk('media_public');

        // An empty file is not a video: an interrupted upload must not render a dead player.
        return $disk->exists($this->video_path) && $disk->size($this->video_path) > 0 ? $disk->url($this->video_path) : null;
    }

    /**
     * Whether there is anything to show. A gallery with fewer than four ready
     * photographs, an image section without its image, a video section
     * without a playable file: none renders, rather than rendering a shell.
     */
    public function isRenderable(): bool
    {
        return match ($this->type) {
            self::TYPE_GALLERY => $this->readyItems()->count() >= self::GALLERY_MIN,
            self::TYPE_IMAGE => $this->featured?->isReady() ?? false,
            self::TYPE_VIDEO => $this->videoUrl() !== null,
            default => false,
        };
    }

    /** @return \Illuminate\Support\Collection<int, Media> */
    public function readyItems(): \Illuminate\Support\Collection
    {
        return $this->items
            ->map(fn (CinematicSectionItem $item): ?Media => $item->media)
            ->filter(fn (?Media $media): bool => $media !== null && $media->isReady())
            ->take(self::GALLERY_MAX)
            ->values();
    }

    /**
     * The direction the light travels ON SCREEN for this page: the owner's
     * override, or the page's own reading direction.
     */
    public function direction(?string $pageDirection = null): string
    {
        if (in_array($this->light_direction, ['ltr', 'rtl'], true)) {
            return $this->light_direction;
        }

        return $pageDirection ?? \App\Support\Locale::direction();
    }

    /**
     * The presets, as the numbers the page reads (data attributes on the
     * section). Unknown names fall back to the defaults, so a value written
     * by hand into the database cannot break the page.
     *
     * @return array<string, string>
     */
    public function lighting(?string $pageDirection = null): array
    {
        $darkness = $this->spotlight ? self::DARKNESS : self::DARKNESS_SWEEP;

        return [
            'direction' => $this->direction($pageDirection),
            'mode' => $this->spotlight ? 'spotlight' : 'sweep',
            'intensity' => (string) (self::INTENSITY[$this->intensity] ?? self::INTENSITY['balanced']),
            'darkness' => (string) ($darkness[$this->darkness] ?? $darkness['deep']),
            'pace' => (string) (self::PACE[$this->pace] ?? self::PACE['natural']),
            'softness' => (string) (self::SOFTNESS[$this->softness] ?? self::SOFTNESS['soft']),
            'overlay' => in_array($this->overlay, self::OVERLAYS, true) ? $this->overlay : 'none',
            'mobile' => in_array($this->mobile_layout, self::MOBILE_LAYOUTS, true) ? $this->mobile_layout : 'track',
            'mobileWidth' => in_array((string) $this->mobile_width, self::MOBILE_WIDTHS, true) ? (string) $this->mobile_width : '80',
            'animate' => $this->animate ? '1' : '0',
        ];
    }
}
