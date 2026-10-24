<?php

declare(strict_types=1);

namespace App\Providers;

use App\Filament\Resources\CinematicSections\CinematicSectionResource;
use App\Http\Middleware\RejectUninstalledFeature;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Category;
use App\Models\CinematicSection;
use App\Models\Client;
use App\Models\Inquiry;
use App\Models\Media;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Project;
use App\Models\Redirect;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\Testimonial;
use App\Models\User;
use App\Policies\ActivityLogPolicy;
use App\Policies\ArticleCategoryPolicy;
use App\Policies\ArticlePolicy;
use App\Policies\CategoryPolicy;
use App\Policies\CinematicSectionPolicy;
use App\Policies\ClientPolicy;
use App\Policies\InquiryPolicy;
use App\Policies\MediaPolicy;
use App\Policies\MenuPolicy;
use App\Policies\PagePolicy;
use App\Policies\ProjectPolicy;
use App\Policies\RedirectPolicy;
use App\Policies\ServicePolicy;
use App\Policies\SettingPolicy;
use App\Policies\TagPolicy;
use App\Policies\TestimonialPolicy;
use App\Policies\UserPolicy;
use App\Routing\LocaleUrlGenerator;
use App\Support\Settings;
use App\Support\Sitemap;
use App\Support\Theme;
use App\Support\TwoFactorEnforcement;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
         * FIRST, BEFORE ANYTHING ELSE IS WIRED.
         *
         * A production application with mandatory two-factor switched off does
         * not start. See TwoFactorEnforcement::assertSafeForEnvironment() for
         * why it throws rather than warns.
         */
        TwoFactorEnforcement::assertSafeForEnvironment();

        /*
         * TASK 36 A5 — CLOSE THE TWO ROUTES filament/actions REGISTERS FOR A
         * FEATURE THIS APPLICATION DOES NOT HAVE.
         *
         * The package calls middlewareGroup('filament.actions', ['web']) in
         * its own boot(); this provider boots after it, so re-declaring the
         * group replaces it.
         *
         * BEFORE 'web', NOT AFTER IT, and the first attempt had it after.
         * Route-model binding is SubstituteBindings, which lives INSIDE the
         * web group — so appending still let an anonymous request query the
         * missing table and still returned 500. Measured, not reasoned:
         * four requests wrote 592 lines of exception to the log with the
         * middleware second, and none with it first.
         *
         * See RejectUninstalledFeature for what was and was not at risk.
         */
        Route::middlewareGroup('filament.actions', [RejectUninstalledFeature::class, 'web']);

        /*
         * TASK 36 B1 — THE CEILING THAT WAS ACTUALLY IN FORCE.
         *
         * Every panel upload goes through Livewire's temporary-file endpoint
         * before Filament or StoreUploadedMedia see a byte, and
         * config/livewire.php leaves temporary_file_upload.rules as null —
         * which means Livewire's own default, ['required', 'file', 'max:12288'].
         *
         * So the real limit was 12 MB. config/media.php says 60, the removed
         * StoreMediaRequest said 60, the FileUpload field said 50 because of
         * the typo above, and a photographer with a 20 MB frame got a generic
         * Livewire validation failure from none of them. Four numbers, one
         * upload.
         *
         * Set here rather than in config/livewire.php because the value has
         * exactly one home — config/media.php — and a config file cannot read
         * another config file without duplicating it.
         *
         * MIMES AS WELL AS SIZE. The authoritative check stays where it was,
         * in StoreUploadedMedia, which reads the bytes; this is the cheaper
         * gate that stops a non-image being written into livewire-tmp at all.
         */
        config()->set('livewire.temporary_file_upload.rules', [
            'required',
            'file',
            /*
             * mp4 and webm as well, for a cinematic section's featured video
             * (lighting brief, 22 Oct 2026). The video field checks the type
             * again by content (FileUpload acceptedFileTypes) and caps it at
             * CinematicSectionResource::VIDEO_MAX_KB; the Media library's own
             * upload still accepts images only (StoreUploadedMedia reads the
             * bytes), so widening this gate admits nothing new there.
             */
            'mimes:'.implode(',', [...(array) config('media.accepted_extensions'), 'mp4', 'webm']),
            'max:'.max((int) config('media.max_upload_kb'), CinematicSectionResource::VIDEO_MAX_KB),
        ]);

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

        /*
         * TWELVE CHARACTERS, EVERYWHERE (master directive, Part 8). make:admin-user
         * already asked for 12; Fortify's reset and the panel's profile page read
         * Password::default(), which was Laravel's 8. One rule now.
         */
        Password::defaults(fn () => Password::min(12));

        /*
         * DEBUG NEVER REACHES A VISITOR IN PRODUCTION (master directive, Part 8).
         * APP_DEBUG=true on a live server renders stack traces with environment
         * values to anybody who triggers an error. Forced off here, and said so
         * in the log, rather than trusting that the .env was edited.
         */
        if ($this->app->isProduction() && config('app.debug')) {
            config(['app.debug' => false]);
            Log::critical('APP_DEBUG was true in production and has been forced off. Set APP_DEBUG=false in .env.');
        }

        Gate::policy(Media::class, MediaPolicy::class);
        Gate::policy(CinematicSection::class, CinematicSectionPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Setting::class, SettingPolicy::class);
        Gate::policy(Inquiry::class, InquiryPolicy::class);
        Gate::policy(Article::class, ArticlePolicy::class);
        Gate::policy(Service::class, ServicePolicy::class);
        Gate::policy(Page::class, PagePolicy::class);
        Gate::policy(Redirect::class, RedirectPolicy::class);

        // Task 34 D1. A menu is edited and never created or destroyed; the
        // policy says so in words rather than by omitting the methods.
        Gate::policy(Menu::class, MenuPolicy::class);

        // Task 34 D2.
        Gate::policy(Testimonial::class, TestimonialPolicy::class);

        // Task 34 D3. Read-only: every write ability is refused in words.
        Gate::policy(ActivityLog::class, ActivityLogPolicy::class);

        /*
         * THE THREE THAT WERE MISSING, FOUND IN TASK 31.
         *
         * ADR 0002 counted ten policies and never asked whether that was all
         * the models the panel exposes. Tag, ArticleCategory and User had
         * none — and because Filament ALLOWS when no policy exists, their
         * resources answered canViewAny() with true for an Editor while
         * Gate::allows() answered false for the same user. See TagPolicy.
         */
        Gate::policy(Tag::class, TagPolicy::class);
        Gate::policy(ArticleCategory::class, ArticleCategoryPolicy::class);
        Gate::policy(User::class, UserPolicy::class);

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
