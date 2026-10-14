<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Translatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * ONE LINK IN THE PUBLIC NAVIGATION.
 *
 * THREE KINDS, AND THE DIFFERENCE IS THE POINT.
 *
 *   route     a named route this application defines. Stored as the NAME, so
 *             it is checked against the router rather than typed as a path —
 *             there is no way to express /wrok.
 *   page      a flat page, by ID. Renaming a page's slug moves its address
 *             and the menu follows, because the menu never held the address.
 *   external  somewhere else. The only free text, validated as a URL, and
 *             marked so the template can open it safely.
 *
 * An item that cannot be resolved — a route that was removed, a page that was
 * deleted or unpublished — is DROPPED from the rendered menu rather than
 * rendered as a dead link. A navigation that lies is worse than one that is
 * one item short.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class MenuItem extends Model
{
    use HasFactory;

    use Translatable;

    public const TYPE_ROUTE = 'route';

    public const TYPE_PAGE = 'page';

    public const TYPE_EXTERNAL = 'external';

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
    protected $fillable = ['menu_id', 'label', 'type', 'target', 'page_id', 'sort_order', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * The routes an owner may link to, as name => the label they'd recognise.
     *
     * Read from the ROUTER rather than written down here, so a route that is
     * renamed or removed cannot leave a menu pointing at nothing — the option
     * simply stops being offered, and isResolvable() drops any item already
     * using it.
     *
     * @return array<string, string>
     */
    public static function linkableRoutes(): array
    {
        $offered = [
            'home' => 'nav.home',
            'work.index' => 'nav.work',
            'archive.index' => 'nav.archive',
            'services.index' => 'nav.services',
            'journal.index' => 'nav.journal',
            'about' => 'nav.about',
            'contact.show' => 'nav.contact',
        ];

        $routes = [];

        foreach ($offered as $name => $settingKey) {
            if (! app('router')->has($name)) {
                continue;
            }

            $routes[$name] = \App\Support\Settings::get($settingKey) ?: $name;
        }

        return $routes;
    }

    /** Can this item be turned into an address a visitor can follow? */
    public function isResolvable(): bool
    {
        return $this->url() !== null;
    }

    /**
     * The address, or null when there is not one any more.
     *
     * Null is a real answer and the caller must handle it: a route can be
     * removed and a page can be deleted or unpublished long after somebody
     * put it in the menu.
     */
    public function url(): ?string
    {
        return match ($this->type) {
            self::TYPE_ROUTE => app('router')->has((string) $this->target)
                ? route((string) $this->target)
                : null,

            self::TYPE_PAGE => ($page = $this->page) && $page->isPubliclyVisible()
                ? route('pages.show', $page)
                : null,

            self::TYPE_EXTERNAL => filled($this->target) ? (string) $this->target : null,

            default => null,
        };
    }

    /** External links leave the site, and say so to assistive technology. */
    public function isExternal(): bool
    {
        return $this->type === self::TYPE_EXTERNAL;
    }
}
