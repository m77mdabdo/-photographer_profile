<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Services\SyncServiceCoverMedia;
use App\Enums\PublishStatus;
use App\Models\Concerns\Translatable;
use App\Support\RichText;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Service extends Model
{
    use Concerns\RedirectsOnSlugChange;

    /** @use HasFactory<ServiceFactory> */
    use HasFactory;
    use SoftDeletes;
    use Translatable;

    /**
     * slug, status, published_at and description_html are deliberately absent,
     * for the reasons Project and Article state: the first three are decisions
     * rather than form fields, and the fourth is written by exactly one thing.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'summary',
        'description',
        'cover_media_id',
        'price_from_pence',
        'price_note',
        'cta_label',
        'is_demo',
        'sort_order',
        'seo_title',
        'seo_description',
        'canonical_url',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PublishStatus::class,
            'published_at' => 'datetime',
            'is_demo' => 'boolean',
            'price_from_pence' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * The public address for a slug of this model.
     *
     * Built from the route so it cannot drift from where the page actually
     * lives, and reduced to a path because a redirect row stores a path.
     */
    public function publicPath(string $slug): string
    {
        return (string) parse_url(route('services.show', $slug), PHP_URL_PATH);
    }

    /**
     * THE ONLY WRITER OF description_html.
     *
     * Bound to saving rather than left to the form, so a seeder, an import or
     * a tinker session goes through the sanitiser too.
     */
    protected static function booted(): void
    {
        /*
         * See Project::booted(). ServiceForm syncs the cover AND the gallery
         * together in its own method; SyncServiceCoverMedia touches the cover
         * role only, so a save from the panel — which has no gallery field —
         * cannot release gallery images it cannot see.
         */
        static::saved(function (self $record): void {
            if ($record->wasChanged(['cover_media_id']) || $record->wasRecentlyCreated) {
                app(SyncServiceCoverMedia::class)($record);
            }
        });

        static::saving(function (self $service): void {
            if ($service->isDirty('description')) {
                /* Every locale, not the active one. See RichText::syncHtmlLocales. */
                RichText::syncHtmlLocales($service, 'description', 'description_html');
            }
        });
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    /**
     * The gallery, in the order somebody arranged it.
     *
     * @return BelongsToMany<Media, $this>
     */
    public function gallery(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'service_media')
            ->withPivot('sort_order')
            ->orderBy('service_media.sort_order');
    }

    /**
     * @return MorphMany<MediaUsage, $this>
     */
    public function mediaUsages(): MorphMany
    {
        return $this->morphMany(MediaUsage::class, 'usable');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', PublishStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isPubliclyVisible(): bool
    {
        return $this->status === PublishStatus::Published
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(now());
    }

    /**
     * The starting price as a string, or null.
     *
     * Null when no price is held. A studio that quotes on request has no
     * starting price, and "from £0" would be a lie the template told on its
     * own initiative.
     */
    public function priceFrom(): ?string
    {
        if ($this->price_from_pence === null) {
            return null;
        }

        $amount = $this->price_from_pence / 100;

        // No trailing ".00" on a round figure: "£1,200", not "£1,200.00".
        $formatted = fmod($amount, 1.0) === 0.0
            ? number_format($amount, 0)
            : number_format($amount, 2);

        return '£'.$formatted;
    }

    /**
     * WHICH translatable field summaryText() read.
     *
     * TASK 36b B3. The derived summary is a string, and a string cannot be
     * asked whether it fell back to another locale — so the template that
     * renders it has to declare the locale of the field it CAME FROM. Two
     * fields are possible and the method above chooses between them, so it
     * is the only thing that can answer.
     *
     * Found by FallbackAnnotationTest: the journal index, the journal
     * preview and the services index all printed a fallen-back summary with
     * nothing to declare it.
     */
    public function summaryTextSource(): string
    {
        return filled($this->summary) ? 'summary' : 'description';
    }

    public function summaryText(int $length = 200): ?string
    {
        if (filled($this->summary)) {
            return $this->summary;
        }

        $text = RichText::toText($this->description_html);

        return $text === '' ? null : Str::limit($text, $length);
    }
}
