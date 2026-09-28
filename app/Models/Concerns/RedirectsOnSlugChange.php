<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Redirect;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * When a PUBLISHED thing's address changes, the old address keeps working.
 *
 * WHY THIS IS AUTOMATIC
 *
 * Renaming a published page is the single most common way a site loses its
 * search position, and it is always an accident: an editor improves a title,
 * the slug follows, and every link anybody ever made to that page — every
 * search result, every email, every other site's link — becomes a 404 without
 * anybody being told. Asking an editor to remember to create a redirect is
 * asking them to remember a consequence the interface never showed them.
 *
 * So the model does it. 301 rather than 302: the old address will never be
 * correct again, and a 302 tells search engines to keep indexing it.
 *
 * ONLY FOR PUBLISHED THINGS. A draft has never had an address the public could
 * reach, so a redirect from its old slug would be a row that can never be hit
 * — and a table full of those is how a redirect table becomes unreadable.
 *
 * The consuming model must provide publicPath(string $slug): string and an
 * isPubliclyVisible() check.
 */
trait RedirectsOnSlugChange
{
    public static function bootRedirectsOnSlugChange(): void
    {
        static::updating(function (self|\Illuminate\Database\Eloquent\Model $model): void {
            if (! $model->isDirty('slug')) {
                return;
            }

            $previous = $model->getOriginal('slug');

            if (! is_string($previous) || $previous === '') {
                return;
            }

            /*
             * The state BEFORE this save decides it. Something being published
             * in the same save that renames it never had a public address
             * under the old slug.
             */
            $wasVisible = self::wasPubliclyVisible($model);

            if (! $wasVisible) {
                return;
            }

            $model->recordSlugRedirect($previous, (string) $model->slug);
        });
    }

    /**
     * Whether the record was publicly reachable before this save.
     */
    private static function wasPubliclyVisible(mixed $model): bool
    {
        $status = $model->getOriginal('status');
        $publishedAt = $model->getOriginal('published_at');

        $statusValue = $status instanceof \BackedEnum ? $status->value : $status;

        if ($statusValue !== \App\Enums\PublishStatus::Published->value) {
            return false;
        }

        if ($publishedAt === null) {
            return false;
        }

        return \Illuminate\Support\Carbon::parse($publishedAt)->lessThanOrEqualTo(now());
    }

    /**
     * Create (or re-point) the 301.
     *
     * Failures are swallowed and logged: a redirect is a courtesy to the past
     * and must never stop somebody saving their work. A studio that cannot
     * rename a project because the redirects table is unavailable is a worse
     * outcome than a missing redirect.
     */
    public function recordSlugRedirect(string $oldSlug, string $newSlug): void
    {
        if ($oldSlug === $newSlug) {
            return;
        }

        $from = Redirect::normalise($this->publicPath($oldSlug));
        $to = Redirect::normalise($this->publicPath($newSlug));

        if ($from === $to) {
            return;
        }

        try {
            /*
             * Re-pointing rather than stacking. If /a moved to /b and then to
             * /c, the row for /a is updated to point at /c directly — so a
             * visitor on the oldest address still pays exactly one redirect.
             * The middleware flattens chains too, but not creating one is
             * better than resolving one.
             */
            Redirect::query()->updateOrCreate(
                ['from_path' => $from],
                ['to_path' => $to, 'status_code' => 301, 'is_active' => true, 'source' => 'slug_change'],
            );

            /*
             * Anything that previously pointed AT the old address now points
             * at the new one, which is what keeps chains from forming at all.
             */
            Redirect::query()
                ->where('to_path', $from)
                ->where('from_path', '!=', $to)
                ->update(['to_path' => $to]);

            /*
             * And a row that would now point at itself is removed rather than
             * left as a loop for the middleware to discard silently.
             */
            Redirect::query()->where('from_path', $to)->where('to_path', $to)->delete();

            Redirect::forgetMap();
        } catch (Throwable $e) {
            Log::error('Could not record a slug-change redirect.', [
                'model' => static::class,
                'from' => $from,
                'to' => $to,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The public address for a given slug of this model.
     */
    abstract public function publicPath(string $slug): string;
}
