<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PublishStatus;
use App\Support\RichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Article extends Model
{
    /** @use HasFactory<\Database\Factories\ArticleFactory> */
    use HasFactory;
    use Concerns\RedirectsOnSlugChange;
    use SoftDeletes;

    /**
     * Four columns are deliberately absent, for the same reasons they are
     * absent on Project.
     *
     * `slug` is the permanent address. `status` and `published_at` decide
     * whether the world can see this. `body_html` is written by exactly one
     * thing — the model event below, from the sanitiser — and allowing it to
     * be mass assigned would be a way for unsanitised markup to reach the
     * column that gets rendered unescaped.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'subtitle',
        'excerpt',
        'body',
        'featured_media_id',
        'article_category_id',
        'author_id',
        'is_demo',
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
        return (string) parse_url(route('journal.show', $slug), PHP_URL_PATH);
    }

    /**
     * THE ONLY WRITER OF body_html.
     *
     * Bound to saving rather than left to the form, so every path that stores
     * a body — the admin editor, a seeder, a future import, a tinker session —
     * goes through the sanitiser. A rule enforced in one Livewire component is
     * a rule the next writer forgets.
     */
    protected static function booted(): void
    {
        static::saving(function (self $article): void {
            if ($article->isDirty('body')) {
                $article->body_html = RichText::toHtml($article->body);
            }
        });
    }

    /**
     * @return BelongsTo<ArticleCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ArticleCategory::class, 'article_category_id');
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function featured(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'featured_media_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * The featured image claims its media through the usage table, so the
     * media library refuses to delete a picture an article still needs.
     *
     * @return MorphMany<MediaUsage, $this>
     */
    public function mediaUsages(): MorphMany
    {
        return $this->morphMany(MediaUsage::class, 'usable');
    }

    /**
     * Published AND the publication moment has passed. A future published_at
     * is a scheduled piece, not a live one.
     *
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
     * The description a search result or a card should show.
     *
     * Falls through: what the author wrote, then the opening of the piece,
     * then nothing. Never the title again — a description that repeats the
     * headline tells a reader nothing they did not already have.
     */
    public function summary(int $length = 200): ?string
    {
        if (filled($this->excerpt)) {
            return $this->excerpt;
        }

        $text = RichText::toText($this->body_html);

        return $text === '' ? null : Str::limit($text, $length);
    }

    public function readingMinutes(): int
    {
        return RichText::readingMinutes($this->body_html);
    }
}
