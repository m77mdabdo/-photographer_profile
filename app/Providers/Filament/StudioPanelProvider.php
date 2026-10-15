<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Support\InitialsAvatar;
use App\Filament\Widgets\MediaNeedingAttention;
use App\Filament\Widgets\RecentEnquiries;
use App\Filament\Widgets\RecentlyEdited;
use App\Filament\Widgets\StudioFigures;
use App\Filament\Widgets\UntranslatedContent;
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
 * IT LIVES AT /admin, AS OF TASK 33.
 *
 * It was at /studio while the custom admin still owned /admin — the two had to
 * run side by side, because the old one was the only thing that made this
 * reversible.
 *
 * That reasoning held and the cost was concrete: /admin is the address the
 * client types, and three times they landed on the old admin's placeholder
 * dashboard, which told them the real screens were still to come. An address
 * is a promise about where the work is, and the work is here.
 *
 * So the panel takes /admin, the custom admin moves to /legacy, and every
 * /studio path 301s to its /admin equivalent so no bookmark breaks. The old
 * admin is NOT deleted: six media capabilities still live only there, and the
 * standing rule is that nothing is deleted until its replacement has a passing
 * test.
 *
 * `admin`, `studio` and `legacy` are all in PageController::RESERVED, because
 * the flat-page wildcard would otherwise take any of them for a page slug —
 * the identical trap that `ar` and `en` needed guarding against.
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

        /*
         * ───────────────────────────────────────────────────────────────────
         * THE MEDIA PICKER, MOUNTED ONCE PER PAGE.
         *
         * Task 34 A1. Two things open it and neither can mount it itself:
         *
         *   MediaPickerField   a form field, which may appear twice on one
         *                      form (a project has a cover AND a hero) — so
         *                      mounting it per field would put two pickers on
         *                      the page, both listening for `open-picker`,
         *                      both opening.
         *   StoryBuilder       mounted as a page, dispatching `open-picker`
         *                      from its own JavaScript since Task 32.
         *
         * One instance, at the end of the body, listening for the event. It
         * renders nothing until it is opened, so the cost on a page with no
         * picker on it is one idle Livewire component.
         * ───────────────────────────────────────────────────────────────────
         */
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): string => Blade::render('@livewire(\'studio.media-picker\')'),
        );

        /*
         * ───────────────────────────────────────────────────────────────────
         * TASK 34b — THE INTERFACE LANGUAGE, IN THE TOPBAR.
         *
         * The panel has been fully translated since Part F and had no way to
         * change its language from inside it: the cookie SetLocale reads was
         * written only by the public site's footer switcher. An owner who
         * wants to work in Arabic had to leave the panel to find out they
         * could.
         *
         * TOPBAR_END rather than the user menu, for two reasons. The user
         * menu is a dropdown, so the control would be two interactions away
         * and invisible until the second one — a language switcher somebody
         * cannot see is the problem this is fixing. And Filament builds that
         * menu from items, which are links; this has to be a form, because it
         * writes a preference (see LocaleController) and because a link that
         * changes state is a link a browser may prefetch.
         *
         * Rendered on every panel page, which is what makes requirement D
         * checkable: the harness now asserts the switcher's state agrees with
         * <html lang> on all twenty-four panel screens, at four viewports, in
         * both languages.
         * ───────────────────────────────────────────────────────────────────
         */
        FilamentView::registerRenderHook(
            PanelsRenderHook::TOPBAR_END,
            fn (): string => Blade::render('@include(\'filament.studio.locale-switch\')'),
        );
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()

            /*
             * The ID stays `studio` while the PATH becomes `admin`.
             *
             * The id is the internal handle — it names the route prefix
             * (filament.studio.*), the theme directory and every
             * Filament::getPanel() call in the test suite. Renaming it would
             * churn hundreds of route names to express something no visitor
             * sees, on the same commit that changes the addresses visitors do
             * see. The path is the promise; the id is plumbing.
             */
            ->id('studio')
            ->path('admin')

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

            /*
             * The initials, drawn locally. Filament's default posts the
             * editor's name to ui-avatars.com on every page — see
             * InitialsAvatar, and see the ->font() comment above, which had
             * already decided this question in the other direction.
             */
            ->defaultAvatarProvider(InitialsAvatar::class)

            ->darkMode(true)
            ->maxContentWidth(Width::Full)

            ->pages([
                Dashboard::class,
            ])

            /*
             * ───────────────────────────────────────────────────────────────
             * B4 — THE DASHBOARD'S ORDER IS A HIERARCHY, NOT A LIST.
             *
             *   1  StudioFigures           the facts, as one band
             *   2  MediaNeedingAttention   NEEDS ACTION — and nobody was told
             *   3  RecentEnquiries         NEEDS ACTION — somebody is waiting
             *   4  UntranslatedContent     IN PROGRESS — half-published work
             *   5  RecentlyEdited          REFERENCE   — what was I doing
             *
             * Reading order is the studio's morning: what is broken, who is
             * waiting, what is half-done, where was I. The two that can be
             * empty — the broken encodes and the translation worklist — hide
             * themselves when they are, so a healthy site shows a shorter
             * page rather than a page of panels about nothing.
             *
             * The declaration order here is irrelevant; each widget's own
             * $sort decides. Listed in sort order anyway, because a reader
             * should not have to open five files to learn the layout.
             * ───────────────────────────────────────────────────────────────
             */
            ->widgets([
                StudioFigures::class,
                MediaNeedingAttention::class,
                RecentEnquiries::class,
                UntranslatedContent::class,
                RecentlyEdited::class,
            ])

            /*
             * DISCOVERED, NOT LISTED.
             *
             * Twelve resources and two pages, found by scanning the
             * directories. The alternative is a list in this file that has to
             * be kept in step with the filesystem, and the sidebar built from
             * it — which is exactly the arrangement B5 dismantled, where a
             * navigation item and the thing it opened were two separate
             * declarations and could disagree.
             */
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')

            ->navigationGroups($this->navigationGroups())

            /*
             * ───────────────────────────────────────────────────────────────
             * A5 — ONE LINK OUT, AND IT SAYS WHAT IS THROUGH IT.
             *
             * Six media capabilities still live only in the custom admin: the
             * thumbnail picker, the focal-point drag control, the EXIF
             * display, rendition inspection, reprocess and the trash screen.
             * Until each has a replacement with a passing test, an owner needs
             * a way to reach them — and exactly one, in the System group,
             * rather than a link from every media screen.
             *
             * Labelled "Media tools", not "Old admin" or "Legacy". Those name
             * the software's history, which is the studio's problem only if we
             * make it theirs. This names what is actually through the door.
             *
             * It opens in a new tab: the two admins have different chrome, and
             * a visitor who follows it has not left the panel, they have
             * stepped out to fetch something.
             *
             * THIS ITEM IS THE DELETION CHECKLIST. When it can be removed, the
             * custom admin can be removed with it.
             * ───────────────────────────────────────────────────────────────
             */

            /*
             * ───────────────────────────────────────────────────────────────
             * NO ->navigationItems(). EVERY ITEM IS A DISCOVERED RESOURCE.
             *
             * Task 30b hung twelve hand-written NavigationItems here: ten
             * duplicating screens of the custom admin and two marked
             * "planned". Task 32 B5 replaced every one with a discovered
             * Resource or Page, so a label and its destination are the same
             * declaration and cannot drift.
             *
             * The last one standing was "Media tools", pointing at the custom
             * admin for the chunked uploader and the per-image detail screen.
             * Task 34 Part A gave the panel both, Part B deleted that admin,
             * and the item went with it. There is now nothing in this panel
             * that links out of it.
             * ───────────────────────────────────────────────────────────────
             */

            /*
             * ───────────────────────────────────────────────────────────────
             * A MISSING POLICY METHOD MUST NOT BE AN UNSPOKEN YES.
             *
             * filament/filament/src/helpers.php::get_authorization_response()
             * returns Response::allow() when the model has no policy, or when
             * the policy exists but lacks the method being asked for. It does
             * not fall through to the Gate. Measured on this application:
             *
             *     Gate::allows('viewAny', Tag::class)   false
             *     TagResource::canViewAny()             true
             *
             * for the same Editor, because Tag had no policy. ADR 0002's
             * thirty bulk methods were written on the belief that a missing
             * method DENIES; it does the opposite, which means the thirty were
             * guarding the wrong failure and three models — Tag,
             * ArticleCategory and User — were wide open to any Editor.
             *
             * Strict mode makes both cases throw a LogicException naming the
             * model and the ability. A gap is now a crash in development
             * rather than a silent permission in production, which is the only
             * version of this that can be relied on.
             * ───────────────────────────────────────────────────────────────
             */
            ->strictAuthorization()

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

}
