<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PublishStatus;
use App\Support\RichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Page extends Model
{
    /** @use HasFactory<\Database\Factories\PageFactory> */
    use HasFactory;
    use SoftDeletes;

    /**
     * The slugs other parts of the site link to by name. A locked page cannot
     * be deleted or have its address moved, because the footer points at it.
     */
    public const PRIVACY = 'privacy';

    public const TERMS = 'terms';

    /** @var list<string> */
    protected $fillable = [
        'title',
        'standfirst',
        'body',
        'is_indexable',
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
            'is_indexable' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** THE ONLY WRITER OF body_html. See App\Models\Article. */
    protected static function booted(): void
    {
        static::saving(function (self $page): void {
            if ($page->isDirty('body')) {
                $page->body_html = RichText::toHtml($page->body);
            }
        });

        /*
         * A locked page cannot be deleted through any path — not the admin,
         * not a seeder, not a stray ->delete() in a future feature. The footer
         * links to these by slug and a 404 there is a compliance problem, not
         * a broken link.
         */
        static::deleting(function (self $page): bool {
            return ! $page->is_locked;
        });

        // The footer asks which legal pages are published; that answer is
        // cached, so any write to a page drops it.
        static::saved(fn () => \App\Support\PageLinks::forget());
        static::deleted(fn () => \App\Support\PageLinks::forget());
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
     * The robots directive this page asks for.
     *
     * Null means "no opinion", and the resolver applies the site default. A
     * page that says it is not indexable says so explicitly, and the sitemap
     * reads the same flag rather than guessing.
     */
    public function robots(): ?string
    {
        return $this->is_indexable ? null : 'noindex, follow';
    }

    public function summaryText(int $length = 200): ?string
    {
        if (filled($this->standfirst)) {
            return $this->standfirst;
        }

        $text = RichText::toText($this->body_html);

        return $text === '' ? null : Str::limit($text, $length);
    }
}
