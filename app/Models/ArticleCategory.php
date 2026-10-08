<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Translatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ArticleCategory extends Model
{
    /** @use HasFactory<\Database\Factories\ArticleCategoryFactory> */
    use HasFactory;
    use Translatable;
    use SoftDeletes;

    /**
     * The slug is the permanent address of a landing page, so it is set
     * explicitly rather than mass assigned — the same rule Project follows.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'description', 'seo_title', 'seo_description', 'sort_order'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return HasMany<Article, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
