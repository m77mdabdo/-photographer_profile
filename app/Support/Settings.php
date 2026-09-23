<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Editable text, read as one cached map.
 *
 * Everything a client may reword — the studio name, the hero statement, the
 * navigation labels — lives in one table and is read through here. The whole
 * table is small enough to be a single row-set, so it is fetched once, cached
 * forever, and invalidated by the model on write. A page that reads twenty
 * settings costs one query the first time and none afterwards.
 */
final class Settings
{
    public const CACHE_KEY = 'settings.map';

    /**
     * Config keys that a setting overrides when one is present.
     *
     * PRECEDENCE: the database wins. The config file is the fallback of last
     * resort, not the source of truth — the point of the table is that these
     * strings change without a deploy, so a populated row must beat a file. An
     * absent or empty row falls through to config, which is what makes a fresh
     * install render before anyone has opened the admin.
     *
     * One setting may stand behind several config keys — the studio name is
     * both the og:site_name and the default document title — so the map is
     * key => list, not key => key.
     *
     * @var array<string, list<string>>
     */
    private const CONFIG_OVERRIDES = [
        'site.name' => ['seo.site_name', 'seo.default_title'],
        'seo.description' => ['seo.default_description'],
    ];

    /**
     * The per-request copy of the map.
     *
     * This is static, which means it outlives the application instance that
     * filled it: a second request in the same PHP process — a worker, Octane,
     * or the next test in a suite — would otherwise read the previous one's
     * values out of it, with no way for a write ANYWHERE ELSE to invalidate
     * it. Cache::forget() clears the shared copy; it cannot reach into another
     * process's memory.
     *
     * So the provider calls forgetMemo() as each application boots. Within one
     * request the memo saves twenty cache reads; across requests it is never
     * trusted.
     *
     * @var array<string, string|null>|null
     */
    private static ?array $memo = null;

    /**
     * @return array<string, string|null>
     */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        try {
            return self::$memo = Cache::rememberForever(self::CACHE_KEY, static function (): array {
                /** @var array<string, string|null> $map */
                $map = Setting::query()->pluck('value', 'key')->all();

                return $map;
            });
        } catch (Throwable) {
            /*
             * The guard belongs HERE, not only around the config overlay.
             *
             * Templates call get() directly, so protecting the overlay alone
             * left the public site returning 500 whenever the table was
             * missing — between a deploy and its migration, or on a fresh
             * clone. Every caller has a defined fallback, so an unreadable
             * table must degrade to "no opinion", never to an error page.
             *
             * The empty map is memoised so a broken table costs one failed
             * query per request rather than one per key.
             */
            return self::$memo = [];
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = self::all()[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    public static function set(string $key, ?string $value, string $type = 'string', string $group = 'general'): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'type' => $type, 'group' => $group],
        );
    }

    /**
     * Drop the in-process copy only, leaving the shared cache intact.
     *
     * Called once per application boot. This is NOT flush(): clearing the
     * shared cache on every request would mean every request paid for the
     * query, which is the whole thing the cache exists to avoid.
     */
    public static function forgetMemo(): void
    {
        self::$memo = null;
    }

    /**
     * Invalidate everywhere: this process and the shared cache. Called on any
     * write, from anywhere, by the model's own events.
     */
    public static function flush(): bool
    {
        self::$memo = null;
        Cache::forget(self::CACHE_KEY);

        return true;
    }

    /**
     * Lay the table over config for the keys the two share.
     *
     * Called once at boot so every existing `config('seo.site_name')` in the
     * codebase reads the edited value without each call site having to know a
     * settings table exists.
     */
    public static function overlayConfig(): void
    {
        try {
            /*
             * No Schema::hasTable() guard: that is a query on every single
             * request, including the overwhelming majority where the map is
             * already cached and no database work was needed at all. The
             * catch below covers the one boot where the table is missing.
             */
            foreach (self::CONFIG_OVERRIDES as $settingKey => $configKeys) {
                $value = self::get($settingKey);

                if ($value === null) {
                    continue;
                }

                foreach ($configKeys as $configKey) {
                    config([$configKey => $value]);
                }
            }
        } catch (Throwable) {
            // A missing database must not stop the application booting — the
            // config defaults are a complete, working fallback by design.
        }
    }
}
