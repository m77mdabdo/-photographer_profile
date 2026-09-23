<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PublishStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasFactory;
    use SoftDeletes;

    /**
     * Four columns are deliberately absent.
     *
     * `status` and `published_at` decide whether the world can see this, and
     * that is a decision, not a form field. `slug` is the permanent address of
     * the page. `created_by` is a fact about who made it. All four are set
     * explicitly, never by filling a model from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'subtitle',
        'excerpt',
        'description',
        'year',
        'location',
        'client',
        'is_featured',
        'sort_order',
        'cover_media_id',
        'hero_media_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PublishStatus::class,
            'published_at' => 'datetime',
            'is_featured' => 'boolean',
            'year' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * @return HasMany<ProjectCredit, $this>
     */
    public function credits(): HasMany
    {
        return $this->hasMany(ProjectCredit::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<ProjectBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(ProjectBlock::class)->orderBy('sort_order');
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function hero(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'hero_media_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Every image this project claims, through the polymorphic usage table.
     * This is what makes media deletion refuse while a project still needs it.
     *
     * @return MorphMany<MediaUsage, $this>
     */
    public function mediaUsages(): MorphMany
    {
        return $this->morphMany(MediaUsage::class, 'usable');
    }

    /**
     * Publicly visible means published AND the publication moment has passed.
     * A future published_at is a scheduled post, not a live one.
     *
     * @param  Builder<self>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', PublishStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeFeatured(Builder $query): void
    {
        $query->where('is_featured', true);
    }

    public function isPubliclyVisible(): bool
    {
        return $this->status?->isPubliclyVisible() === true
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(now());
    }
}
