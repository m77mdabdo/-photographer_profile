<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Category;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\Service;
use App\Models\Client;
use App\Models\Inquiry;
use App\Models\Media;
use App\Models\Project;
use App\Models\Setting;
use App\Policies\ArticlePolicy;
use App\Policies\CategoryPolicy;
use App\Policies\PagePolicy;
use App\Policies\RedirectPolicy;
use App\Policies\ServicePolicy;
use App\Policies\ClientPolicy;
use App\Policies\InquiryPolicy;
use App\Policies\MediaPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\SettingPolicy;
use App\Routing\LocaleUrlGenerator;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Blade;
use App\Support\Settings;
use App\Support\Sitemap;
use App\Support\Theme;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerLocaleUrlGenerator();
    }

    /**
     * Swap Laravel's URL generator for the locale-aware one.
     *
     * WHY `extend` AND NOT A FRESH BINDING
     *
     * The container's `url` instance is wired to several things after it is
     * created — the session's resolver, the key resolver for signed URLs, the
     * request. Rebinding it from scratch means reproducing all of that and
     * getting it wrong the first time signed preview links are used.
     *
     * Extending instead means Laravel builds its generator exactly as it
     * normally would, and this copies its state into the subclass. The only
     * behavioural difference is the one in LocaleUrlGenerator::route().
     */
    private function registerLocaleUrlGenerator(): void
    {
        $this->app->extend('url', function (UrlGenerator $generator, $app): UrlGenerator {
            $localised = new LocaleUrlGenerator(
                $app['router']->getRoutes(),
                $generator->getRequest(),
                $app['config']->get('app.asset_url'),
            );

            // Signed URLs — the project preview route depends on this.
            $localised->setKeyResolver(fn () => $app->make('config')->get('app.key'));

            /*
             * The session resolver is what makes url()->previous() work. It is
             * bound lazily because the session is not available this early in
             * the container's life.
             */
            $localised->setSessionResolver(fn () => $app['session'] ?? null);

            // Keep the generator pointed at the live request as it changes.
            $app->rebinding('request', function ($app, $request) use ($localised): void {
                $localised->setRequest($request);
            });

            return $localised;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Layouts live in resources/views/layouts rather than under
         * resources/views/components, so the path is registered explicitly.
         * They are then used as <x-layouts::public> and receive {{ $slot }}.
         */
        Blade::anonymousComponentPath(resource_path('views/layouts'), 'layouts');

        // Registered explicitly rather than relying on discovery, so the
        // mapping is greppable.
        /*
         * Every view can rely on $theme existing. ResolveTheme overrides this
         * per request; the default is here so that a view rendered outside the
         * web middleware group — an error page, a component under test, a
         * queued mail template — still has a value rather than throwing.
         */
        View::share('theme', Theme::System);

        /*
         * The settings table is laid over config before anything reads it, so
         * every existing config('seo.…') call gets the edited value without
         * each call site needing to know the table exists. It is one cached
         * map, so this costs a query only when that cache is cold.
         */
        Settings::forgetMemo();
        Settings::overlayConfig();

        Gate::policy(Media::class, MediaPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Setting::class, SettingPolicy::class);
        Gate::policy(Inquiry::class, InquiryPolicy::class);
        Gate::policy(Article::class, ArticlePolicy::class);
        Gate::policy(Service::class, ServicePolicy::class);
        Gate::policy(Page::class, PagePolicy::class);
        Gate::policy(Redirect::class, RedirectPolicy::class);

        /*
         * SITEMAP INVALIDATION.
         *
         * Every model whose rows can appear in a sitemap drops the whole
         * sitemap cache when it is written or deleted. This is why there is no
         * scheduled regeneration: publishing is the event, so publishing is
         * what invalidates.
         *
         * Registered here rather than in each model's booted() so the set of
         * things that affect the sitemap is one readable list. A model added
         * to a sitemap segment and forgotten here is the failure this shape
         * makes obvious.
         *
         * saved and deleted cover publish, unpublish, edit and bin. A project
         * moving from published to draft has to drop the cache exactly as
         * hard as one being published.
         */
        foreach ([Project::class, Article::class, Service::class, Page::class,
                  Category::class, ArticleCategory::class, Media::class] as $model) {
            $model::saved(fn () => Sitemap::forget());
            $model::deleted(fn () => Sitemap::forget());
        }
    }
}
