<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Category;
use App\Models\Client;
use App\Models\Media;
use App\Models\Project;
use App\Policies\CategoryPolicy;
use App\Policies\ClientPolicy;
use App\Policies\MediaPolicy;
use App\Policies\ProjectPolicy;
use Illuminate\Support\Facades\Blade;
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
        //
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

        Gate::policy(Media::class, MediaPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Client::class, ClientPolicy::class);
    }
}
