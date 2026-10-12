<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\MediaPipeline;
use App\Filament\Widgets\RecentEnquiries;
use App\Filament\Widgets\RecentlyEdited;
use App\Filament\Widgets\StudioOverview;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\SetLocale;
use App\Support\Locale;
use App\Support\Settings;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The Filament panel.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * IT LIVES AT /studio, NOT /admin, AND THAT IS A PERMANENT CHOICE
 *
 * The custom admin owns /admin and keeps it until step 10. The two have to run
 * side by side, because the old one is the only thing that makes this
 * reversible — abandoning Filament in month two must not mean the studio
 * cannot edit its own website.
 *
 * The obvious temporary names — /admin2, /new-admin, /filament — are all names
 * that would have to be changed the day the old admin is deleted, and changing
 * an admin's address means every bookmark, every saved password and every
 * muscle-memory URL breaks on a day when something else is already changing.
 * /studio is a name this panel can keep for ever: it is what the thing is.
 *
 * `studio` is also added to PageController::RESERVED, because the flat-page
 * wildcard would otherwise take it for a page slug — the identical trap that
 * `ar` and `en` needed guarding against two tasks ago.
 * ───────────────────────────────────────────────────────────────────────────
 *
 * FORTIFY KEEPS LOGIN, AND THE REASON IS ONE LINE OF FILAMENT'S SOURCE
 *
 * Filament's login page throttles through danharrin/livewire-rate-limiting,
 * whose key is:
 *
 *     'livewire-rate-limiter:'.sha1($component.'|'.$method.'|'.request()->ip())
 *
 * IP only. Ours is email AND IP. Adopting Filament's auth pages would have
 * quietly replaced a composite key with a weaker one and nothing would have
 * reported it, so `->login()` is deliberately absent and Filament's
 * Authenticate middleware sends a guest to Fortify's route instead.
 *
 * The same reasoning retires Filament's MFA before it is ever configured: it
 * stores secrets in app_authentication_secret, Fortify stores them in
 * two_factor_secret, and adopting it would mean every existing user
 * re-enrolling their authenticator for no gain.
 */
class StudioPanelProvider extends PanelProvider
{
    /**
     * Livewire's runtime, injected INTO THIS PANEL ONLY.
     *
     * ───────────────────────────────────────────────────────────────────────
     * config/livewire.php sets `inject_assets => false`, deliberately: the
     * public layout must never receive a byte of Livewire, and automatic
     * injection would put it on any page that happened to render a component.
     * The custom admin therefore calls @livewireStyles and @livewireScripts by
     * hand in its own layout.
     *
     * Filament renders its OWN scripts through @filamentScripts and assumes
     * Livewire has injected itself. With injection off it had not, so Alpine
     * never started — and Filament's main container, which Alpine fades in,
     * stayed at `opacity: 0` for ever.
     *
     * THE DASHBOARD WAS BLANK IN A BROWSER WHILE EVERY TEST PASSED. The
     * markup was correct, the widgets were in the DOM, the text was in
     * innerText with real measured width, the computed colours were right and
     * there were no console errors or failed requests — and the page rendered
     * nothing. Found by screenshotting it; diagnosed by walking the ancestor
     * chain looking for an opacity.
     *
     * Registering the directives as panel render hooks gives Filament the
     * runtime it needs without re-enabling global injection, so the public
     * site's guarantee is untouched.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function boot(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::STYLES_AFTER,
            fn (): string => Blade::render('@livewireStyles'),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::SCRIPTS_BEFORE,
            fn (): string => Blade::render('@livewireScripts'),
        );
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('studio')
            ->path('studio')

            /*
             * NO ->login(). See the class comment: Fortify owns the login
             * route, the password reset and the two-factor challenge, and it
             * keeps the email+IP throttle that Filament's page does not have.
             */

            ->colors([
                // The studio's accent, not Filament's amber. See the theme.
                'primary' => Color::hex('#b45309'),
                'gray' => Color::Stone,
            ])

            /*
             * NO ->font(). Filament's font helper fetches from a provider —
             * Google, Bunny, or a local registration — and this project
             * already self-hosts Archivo, Inter Tight and JetBrains Mono with
             * measured size-adjust fallbacks. The theme imports fonts.css and
             * sets --font-sans, which is the same families by the same
             * @font-face rules, with no third-party request added to an admin.
             */

            /*
             * The compiled theme. This is where the design tokens, the real
             * typefaces and the dark/light registers come from — see
             * resources/css/filament/studio/theme.css.
             */
            ->viteTheme('resources/css/filament/studio/theme.css')

            ->brandName(fn (): string => (string) (Settings::get('site.name') ?: config('app.name')))

            ->darkMode(true)
            ->maxContentWidth(Width::Full)

            ->pages([
                Dashboard::class,
            ])

            ->widgets([
                StudioOverview::class,
                MediaPipeline::class,
                RecentlyEdited::class,
                RecentEnquiries::class,
            ])

            /*
             * Resources are discovered but there are none yet — this task
             * builds the shell. The directory is declared so the first
             * Resource needs no provider change.
             */
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')

            ->navigationGroups($this->navigationGroups())
            ->navigationItems($this->plannedModules())

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,

                /*
                 * The panel is bilingual because the site is. SetLocale is
                 * global, so this is belt and braces — but a panel that
                 * declared its own middleware stack and omitted it would be
                 * one refactor away from an English-only admin.
                 */
                SetLocale::class,
            ])

            /*
             * THE EXISTING GUARDS, UNCHANGED.
             *
             * Filament's Authenticate establishes there is a user. Everything
             * after it is this application's own policy, in the order the
             * custom admin already applies it:
             *
             *   admin      — the UserRole enum. Owner and Editor, nothing else.
             *   twofactor  — a CONFIRMED second factor, not merely an enrolled
             *                one. Filament has no equivalent of
             *                two_factor_confirmed_at.
             *   throttle   — the admin limiter, so the panel cannot be used to
             *                hammer the application.
             */
            ->authMiddleware([
                \Filament\Http\Middleware\Authenticate::class,
                'verified',
                EnsureUserIsAdmin::class,
                EnsureTwoFactorEnabled::class,
                'throttle:admin',
            ]);
    }

    /**
     * The groups the planned modules hang from.
     *
     * Named for what the studio calls things, not for the models underneath:
     * "The work" rather than "Projects", because an owner opening this panel
     * is thinking about their work, not about a database table.
     *
     * @return list<NavigationGroup>
     */
    /**
     * FIVE GROUPS, NAMED FOR WHAT THE OWNER IS DOING.
     *
     * Eleven items in a flat list is a list, not a structure — you read all of
     * it every time because nothing tells you which part you are in. Grouped,
     * the eye goes to one of five short lists.
     *
     * Named in the studio's voice rather than after the models underneath:
     * "The work" rather than "Projects", because somebody opening this is
     * thinking about their work, not about a database table. "People &
     * messages" rather than "CRM".
     *
     * Closures, not strings: panel() runs at provider registration, before
     * SetLocale, so a plain __() would freeze the boot locale for the life of
     * the process and give an Arabic editor an English sidebar.
     *
     * @return list<NavigationGroup>
     */
    private function navigationGroups(): array
    {
        return [
            NavigationGroup::make(fn (): string => __('studio.nav.group_work')),
            NavigationGroup::make(fn (): string => __('studio.nav.group_writing')),
            NavigationGroup::make(fn (): string => __('studio.nav.group_studio')),
            NavigationGroup::make(fn (): string => __('studio.nav.group_people')),
            NavigationGroup::make(fn (): string => __('studio.nav.group_system')),
        ];
    }

    /**
     * THE TWELVE MODULES, IN TWO TIERS.
     *
     * B4: nothing is hidden because it has not been moved yet. A navigation
     * that silently omits what is unbuilt tells an owner the product is
     * smaller than it is and tells a developer nothing — so the whole
     * structure is visible from the first day and fills in.
     *
     * The two tiers are different states and look different:
     *
     *   OLD PANEL — the module works today, in the admin this one replaces.
     *               The item is a live link and says where it goes. An owner
     *               can do the job; it is the address that is temporary.
     *
     *   PLANNED   — nothing exists anywhere. The item is visible, disabled,
     *               and says so. It is a promise, not a broken link.
     *
     * Each tier loses its badge as the matching Resource lands in task 31.
     *
     * @return list<NavigationItem>
     */
    private function plannedModules(): array
    {
        /** [key, group, icon, route or null] */
        $modules = [
            ['projects', 'group_work', 'heroicon-o-photo', 'admin.projects.index'],
            ['media', 'group_work', 'heroicon-o-rectangle-stack', 'admin.media.index'],
            ['categories', 'group_work', 'heroicon-o-tag', 'admin.categories.index'],
            ['galleries', 'group_work', 'heroicon-o-squares-2x2', null],

            ['journal', 'group_writing', 'heroicon-o-pencil-square', 'admin.articles.index'],
            ['pages', 'group_writing', 'heroicon-o-document-text', 'admin.pages.index'],

            ['services', 'group_studio', 'heroicon-o-briefcase', 'admin.services.index'],
            ['clients', 'group_studio', 'heroicon-o-identification', 'admin.clients.index'],

            ['enquiries', 'group_people', 'heroicon-o-envelope', 'admin.inquiries.index'],
            ['users', 'group_people', 'heroicon-o-users', null],

            ['settings', 'group_system', 'heroicon-o-cog-6-tooth', 'admin.settings.index'],
            ['redirects', 'group_system', 'heroicon-o-arrow-path-rounded-square', 'admin.redirects.index'],
        ];

        $items = [];

        /*
         * EVERY LOOKUP IS DEFERRED, and Route::has is the reason.
         *
         * panel() runs when the provider registers — before the router has
         * loaded a single route file. Asking Route::has() there returns false
         * for every route that exists, so all twelve modules rendered as
         * "planned" and none of them linked anywhere. The same trap as the
         * locale labels, which froze at boot for the same reason.
         *
         * Closures are evaluated at render, by which time the routes are
         * registered and the locale is known.
         */
        foreach ($modules as [$key, $group, $icon, $route]) {
            $exists = static fn (): bool => $route !== null && Route::has($route);

            $items[] = NavigationItem::make(fn (): string => __('studio.nav.'.$key))
                ->group(fn (): string => __('studio.nav.'.$group))
                ->icon($icon)
                ->url(fn (): string => $exists() ? route($route) : '#')
                ->badge(
                    fn (): string => __($exists() ? 'studio.nav.in_old_panel' : 'studio.nav.planned'),
                    color: 'gray',
                )
                /*
                 * A planned item must not be reachable by mouse OR keyboard.
                 * aria-disabled alone still takes focus and still follows the
                 * href, so tabindex carries the rest and the theme's cursor
                 * rule says so visually.
                 */
                ->extraAttributes(fn (): array => $exists() ? [] : [
                    'aria-disabled' => 'true',
                    'tabindex' => '-1',
                ]);
        }

        return $items;
    }
}
