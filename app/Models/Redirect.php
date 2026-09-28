<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Redirect extends Model
{
    /** @use HasFactory<\Database\Factories\RedirectFactory> */
    use HasFactory;

    /**
     * THE WHOLE MAP, UNDER ONE KEY.
     *
     * See App\Http\Middleware\HandleRedirects for why this is one key rather
     * than one key per path.
     */
    public const CACHE_KEY = 'redirects.map';

    /**
     * How many hops a chain may take before it is refused.
     *
     * Three is generous: a slug that has moved three times has usually been
     * moved by three different people who did not know about each other, and
     * each hop costs a real visitor a round trip. Beyond that the answer is
     * "flatten it", which resolve() does automatically.
     */
    public const MAX_HOPS = 5;

    /** @var list<string> */
    protected $fillable = ['from_path', 'to_path', 'status_code', 'is_active', 'source'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'status_code' => 'integer',
            'hits' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }

    /**
     * Any write invalidates the map. Cheap, because the map is one key.
     */
    protected static function booted(): void
    {
        static::saved(fn () => self::forgetMap());
        static::deleted(fn () => self::forgetMap());
    }

    public static function forgetMap(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Normalise a path to the one shape the table stores.
     *
     * A single leading slash, no trailing slash, no query string, no fragment,
     * and lowercased. Without this "/Old-Name/", "/old-name" and
     * "/old-name?utm_source=x" are three different rows for one redirect, and
     * the one a visitor actually arrives on is whichever nobody created.
     *
     * The root stays "/" rather than collapsing to "".
     */
    public static function normalise(string $path): string
    {
        // Drop anything after the path: a query string belongs to the request.
        $path = (string) preg_replace('/[?#].*$/', '', $path);

        // Collapse repeated separators: "//a//b" and "/a/b" are one address.
        $path = (string) preg_replace('#/+#', '/', $path);

        $path = '/'.trim($path, '/');

        $path = mb_strtolower($path);

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * Record a hit without a write on the hot path being able to fail the
     * redirect.
     *
     * An increment is a single UPDATE and does not touch the model events, so
     * it cannot invalidate the cache it was just read from — which would turn
     * every redirect into a cache miss and defeat the whole design.
     */
    public function recordHit(): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->update([
                'hits' => $this->hits + 1,
                'last_hit_at' => now(),
            ]);
    }
}
