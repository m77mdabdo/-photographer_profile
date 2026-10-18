<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Translatable;
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
    use Translatable;
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
                /* Every locale, not the active one. See RichText::syncHtmlLocales. */
                RichText::syncHtmlLocales($page, 'body', 'body_html');
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

        /*
         * ───────────────────────────────────────────────────────────────────
         * AND ITS ADDRESS CANNOT BE MOVED EITHER.
         *
         * The comment above says a locked page cannot be DELETED, "because
         * the footer links to these by slug and a 404 there is a compliance
         * problem". Moving the slug produces exactly the same 404 by a
         * different route, and nothing stopped it.
         *
         * The custom admin's page manager refused it by hand. The panel had
         * no equivalent, so editing the privacy notice's address through
         * /admin/pages silently broke both footer links — found in Task 34
         * Part B when ServicesAndPagesTest was repointed at the panel and
         * this assertion, which had passed for months, started failing.
         *
         * Here rather than in a resource, for the reason the deletion guard
         * is here: a rule enforced in one of two admins is a rule that lasts
         * until somebody writes a third way in.
         *
         * The slug is RESTORED rather than the save being refused: an editor
         * rewriting the privacy notice's text should not lose the edit
         * because they also touched a field they are not allowed to change.
         * ───────────────────────────────────────────────────────────────────
         */
        static::updating(function (self $page): void {
            if (! $page->is_locked || ! $page->isDirty('slug')) {
                return;
            }

            /*
             * Written through setRawAttributes and not `$page->slug = …`.
             * The slug is a translatable JSON column, so the normal setter
             * takes a VALUE FOR THE ACTIVE LOCALE and merges it into the map
             * — handing it the whole original map back produces a map with a
             * map inside it. The raw attribute is the only way to say "put it
             * back exactly as it was".
             */
            $page->setRawAttributes(array_merge($page->getAttributes(), [
                'slug' => $page->getRawOriginal('slug'),
            ]));
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
        return filled($this->standfirst) ? 'standfirst' : 'body';
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
