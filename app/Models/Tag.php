<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    /** @use HasFactory<\Database\Factories\TagFactory> */
    use HasFactory;

    public $timestamps = true;

    /** @var list<string> */
    protected $fillable = ['name'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsToMany<Article, $this>
     */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class);
    }

    /**
     * Find or make the tag for a typed name.
     *
     * Keyed on the slug rather than the name, so "Large Format",
     * "large format" and "Large  Format" are one tag rather than three —
     * which is the difference between a tag list and a mess.
     */
    public static function named(string $name): self
    {
        $name = trim($name);
        $slug = Str::slug($name);

        $tag = self::query()->where('slug', $slug)->first();

        if ($tag !== null) {
            return $tag;
        }

        $tag = new self(['name' => $name]);
        $tag->slug = $slug;
        $tag->save();

        return $tag;
    }
}
