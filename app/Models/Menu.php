<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place in the design that holds links.
 *
 * There are two and there will only ever be as many as the templates render:
 * a menu nothing draws is a row nobody sees. They are seeded, not created,
 * for the same reason a settings key is — see the migration.
 */
class Menu extends Model
{
    use HasFactory;

    /** The strip across the top of every public page. */
    public const HEADER = 'header';

    /** The column at the bottom, beside the legal links. */
    public const FOOTER = 'footer';

    /**
     * Any write to the navigation drops the cache that serves it.
     *
     * On the MODEL rather than in the resource, because a seeder, a console
     * command and a future import all write here too, and a cache invalidated
     * in one of four places is a cache that serves yesterday's menu.
     */
    protected static function booted(): void
    {
        static::saved(fn () => \App\Support\Navigation::forget());
        static::deleted(fn () => \App\Support\Navigation::forget());
    }

    /** @var list<string> */
    protected $fillable = ['location'];

    /**
     * @return HasMany<MenuItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('sort_order');
    }

    /**
     * How many of this menu's links actually work, without a query.
     *
     * Reads the LOADED items relation rather than issuing one of its own, so
     * a table of menus costs one query for all of them. visibleItems() below
     * is the query form and is right for the public path, where the answer is
     * cached; calling it once per row in a Filament column was an N+1 — three
     * queries for one menu, twelve for ten — caught by
     * ResourceInventoryTest the moment the resource was added.
     */
    public function resolvableCount(): int
    {
        return $this->items
            ->filter(fn (MenuItem $item): bool => $item->is_active && $item->isResolvable())
            ->count();
    }

    /**
     * The items a visitor should actually see, in order.
     *
     * Eager-loads the page so resolving an internal link costs one query for
     * the whole menu rather than one per item — this runs on every public
     * page, in the layout, above the fold.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, MenuItem>
     */
    public function visibleItems(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->items()
            ->where('is_active', true)
            ->with('page')
            ->get()
            ->filter(fn (MenuItem $item): bool => $item->isResolvable())
            ->values();
    }
}
