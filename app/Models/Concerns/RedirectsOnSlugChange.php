<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\PublishStatus;
use App\Models\Redirect;
use App\Support\Locale;
use App\Support\SlugRedirects;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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
    /**
     * Run something with slug-change redirects switched off.
     *
     * THE STATE LIVES IN App\Support\SlugRedirects, NOT HERE, and the reason
     * is a bug this nearly shipped with: a static property declared in a trait
     * is a separate property per consuming class, so suppressing it on Project
     * would have left Article, Service and Page firing. The data migration
     * touches all four.
     *
     * See that class for why it is a counter, and why there is no
     * disable()/enable() pair.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutSlugRedirects(callable $callback): mixed
    {
        return SlugRedirects::without($callback);
    }

    public static function bootRedirectsOnSlugChange(): void
    {
        static::updating(function (self|Model $model): void {
            if (SlugRedirects::suppressed()) {
                return;
            }

            if (! $model->isDirty('slug')) {
                return;
            }

            /*
             * The state BEFORE this save decides it. Something being published
             * in the same save that renames it never had a public address
             * under the old slug.
             */
            if (! self::wasPubliclyVisible($model)) {
                return;
            }

            /*
             * PER LOCALE, BECAUSE SLUGS ARE NOW PER LOCALE.
             *
             * This read getOriginal('slug') as a string and handed it to
             * publicPath(). Once the column became JSON that string was the
             * whole document, and route() was asked to build a URL from
             * `{"en":"…","ar":"…"}`:
             *
             *     Missing required parameter for [Route: journal.show]
             *
             * Comparing the two maps locale by locale is also simply more
             * correct than it was before: an editor who changes only the
             * Arabic slug moves only the Arabic address, and only that one
             * needs a redirect. The previous version would have written both.
             */
            $before = $model->translationsOf('slug', $model->getOriginal('slug'));
            $after = $model->translations('slug');

            foreach (Locale::codes() as $locale) {
                $old = $before[$locale] ?? null;
                $new = $after[$locale] ?? null;

                if (! is_string($old) || $old === '' || ! is_string($new) || $new === '' || $old === $new) {
                    continue;
                }

                $model->recordSlugRedirect($old, $new, $locale);
            }
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

        if ($statusValue !== PublishStatus::Published->value) {
            return false;
        }

        if ($publishedAt === null) {
            return false;
        }

        return Carbon::parse($publishedAt)->lessThanOrEqualTo(now());
    }

    /**
     * Create (or re-point) the 301.
     *
     * Failures are swallowed and logged: a redirect is a courtesy to the past
     * and must never stop somebody saving their work. A studio that cannot
     * rename a project because the redirects table is unavailable is a worse
     * outcome than a missing redirect.
     */
    public function recordSlugRedirect(string $oldSlug, string $newSlug, ?string $locale = null): void
    {
        if ($oldSlug === $newSlug) {
            return;
        }

        $locale ??= Locale::DEFAULT;

        $bareFrom = Redirect::normalise($this->publicPath($oldSlug));
        $bareTo = Redirect::normalise($this->publicPath($newSlug));

        if ($bareFrom === $bareTo) {
            return;
        }

        /*
         * ONE ROW PER LOCALE, because a slug change moves every locale's copy
         * of the address at once.
         *
         * HandleRedirects matches the full path including the locale prefix
         * and deliberately never leaks a redirect from one locale into
         * another — so a single row for `/work/old` would leave
         * `/ar/work/old` returning a 404 after a rename. The rule that keeps
         * an editor's hand-written redirect from applying to a language they
         * were not thinking about is the same rule that obliges this, which
         * knows it is moving both, to say so twice.
         *
         * The slugs themselves are not per-locale yet — that is step 3 of the
         * migration plan and not this task — so both paths are built from the
         * same slug today. When slugs become translatable this loop is the
         * place that changes, and it changes by reading the slug per locale
         * rather than by growing another branch.
         */
        $this->recordMove(
            Locale::pathFor($locale, $bareFrom),
            Locale::pathFor($locale, $bareTo),
        );

        Redirect::forgetMap();
    }

    /**
     * Record one address move, for one locale.
     */
    private function recordMove(string $from, string $to): void
    {
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
