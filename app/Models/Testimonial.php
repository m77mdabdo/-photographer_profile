<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PublishStatus;
use App\Models\Concerns\Translatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What a client said, in their words.
 *
 * Published like everything else on this site — a status and a date — so a
 * quote can be written, approved and timed rather than appearing the moment
 * it is typed. The portrait is optional: most clients do not send one, and a
 * row of empty circles is worse than no circles.
 */
class Testimonial extends Model
{
    use HasFactory;
    use SoftDeletes;
    use Translatable;

    /** @var list<string> */
    protected $fillable = [
        'quote', 'attribution', 'role', 'media_id', 'status', 'published_at', 'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PublishStatus::class,
            'published_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function portrait(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
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
}
