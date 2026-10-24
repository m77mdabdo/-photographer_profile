# Architecture

How the application is put together, for a developer meeting it for the first
time. It is a conventional Laravel 12 application: no repositories, no service
layer for its own sake. Where the code departs from a default, the file says
why in a comment — and the decisions that shaped whole areas are in
`docs/decisions/` (ADRs).

```
app/
  Http/Controllers   one per public page type (Home, Work, Project, Archive, Journal, Service, Page, About, Contact, Sitemap, Robots, Locale, Theme)
  Http/Middleware    SetLocale, SecurityHeaders, CompressResponse, HandleRedirects, EnsureCanonicalSlug, EnsureTwoFactorEnabled, EnsureUserIsAdmin, ResolveTheme
  Models             Eloquent models; translatable ones use Concerns/Translatable
  Actions            single-purpose operations used by the dashboard and commands (store an upload, save a story, sync media usage…)
  Jobs               ProcessMediaVariants — makes every rendition of an uploaded photograph
  Policies           who may do what, one per model; refusals carry a translated reason
  Filament           the dashboard: Resources (one per content type), Pages (Settings, Help, Account security), Support (shared field builders)
  Livewire/Studio    two hand-built dashboard components: MediaPicker and StoryBuilder
  Support            small, testable classes with one job each (Locale, Settings, SiteCopy, HeroSplit, StructuredData, Help/…)
  Services/Seo       SeoResolver (titles, descriptions, canonicals) and SitemapBuilder
  Console/Commands   operational commands (see README)
resources/
  views              Blade: layouts/, components/ (home/, blocks/), one folder per page type, filament/ (dashboard views), mail/
  css                tokens.css → base.css → fonts.css → components/*.css → pages/*.css, bundled by public.css; filament/studio/ for the dashboard theme
  js                 public.js (entry) + public/header.js, arrive/, cinematic.js; studio/ for dashboard scripts
  help               the Help Center's articles, as data (one PHP file per topic)
lang/{en,ar}         interface strings: studio.php (dashboard + shared), setting_labels.php, auth/validation/passwords; ar.json for Fortify
routes/web.php       locale groups, dashboard extras, legacy redirects; routes/public.php the public pages
```

## Requests: from URL to page

1. **Locale.** `SetLocale` (global middleware) reads the locale from the path:
   English is unprefixed (`/work`), Arabic is `/ar/work`; `/en/…` redirects
   to the unprefixed URL. `routes/web.php` registers the public routes once
   per locale (`Locale::codes()`), with an `ar.` route-name prefix for
   Arabic. `App\Routing\LocaleUrlGenerator` makes `route()` keep the current
   locale. Direction (`dir="rtl"`) and `lang` come from `App\Support\Locale`.
2. **Redirects and canonical slugs.** `HandleRedirects` serves the owner's
   redirects table; `EnsureCanonicalSlug` sends a project reached by an old
   or other-language slug to its canonical address.
3. **Controllers** load published records only (`published()` scopes) and
   hand plain data to Blade. They do not format copy: every sentence comes
   from `Settings` / `SiteCopy` or from the record.
4. **Views** compose components; images always go through
   `<x-picture>` (AVIF/WebP/JPEG `srcset`, intrinsic size, focal point).
5. **Response.** `SecurityHeaders` adds the CSP (per-request nonce), HSTS and
   the rest; `CompressResponse` gzips when the server does not.

## Content: dashboard → validation → database → public site

| Content | Dashboard | Stored in | Read by |
| --- | --- | --- | --- |
| Projects | `Filament/Resources/Projects` (+ `Livewire/Studio/StoryBuilder` for the story) | `projects`, `project_blocks`, `project_credits`, `category_project`; block photographs through `media_usages` | `ProjectController`, `HomeController`, `WorkController` |
| Photographs | `Filament/Resources/Media` | `media`, `media_variants`, `media_usages` | `<x-picture>` everywhere |
| Cinematic sections | `Filament/Resources/CinematicSections` | `cinematic_sections`, `cinematic_section_items` | `components/home/cinematic-sequence.blade.php` + `js/public/cinematic.js` |
| Services, journal, pages, clients, testimonials, menus, redirects | one Resource each | one table each | their controllers / components |
| Every other sentence on the site | `Filament/Pages/Settings` | `settings` (one translatable JSON value per key) | `Settings::get('group.key')` |

**Translatable fields** are JSON columns holding `{"en": …, "ar": …}`
(`Models/Concerns/Translatable`). A missing Arabic value falls back to English
at render time and is marked (`App\Support\Fallback`) so the Arabic page knows
which runs are English. Slugs have generated columns (`slug_en`, `slug_ar`)
for unique indexes — never write to those.

**Validation** lives in the Filament form definitions (required, lengths,
slug uniqueness per language in `ResourceDefaults::slugField`), in policies
(what may be deleted), and in the Actions (uploads are checked by content,
not extension). **Publishing** is a status (`Draft`, `Published`,
`Archived`) plus `published_at`; scopes treat a future date as unpublished.

**Settings** are created by `SettingsSeeder` from the registry in
`App\Support\SiteCopy` (default English, Arabic, type, group) and never
overwritten afterwards; `site:upgrade-copy` updates only values still equal
to an old default. The Settings page builds its sections from the same
registry, so adding a key there adds its field.

## Media pipeline

`Actions/Media/StoreUploadedMedia` validates the upload by content (JPEG,
PNG, WebP; 60 MB; 50 MP), stores the original privately, de-duplicates by
hash, reads EXIF, and queues `Jobs/ProcessMediaVariants` on the `media`
queue, which writes AVIF and WebP at 480–2560 px and two JPEG fallbacks
(`config/media.php`) and extracts a palette. `media_usages` records every
place a photograph is used (polymorphic `usable`), which is why a photograph
in use cannot be deleted (`MediaPolicy`). Videos for cinematic sections are a
separate file field (MP4/WebM) on the public media disk.

## The dashboard

Filament 5 panel `studio` at `/admin` (`Providers/Filament/StudioPanelProvider`).
Each Resource defines its form, table and actions; shared building blocks are
in `Filament/Support` — `ResourceDefaults` (status, publish date, slug,
row actions), `Translatable` (the English/العربية tabs), `MediaField` (the
picker), `HelpHint` (the contextual **?** buttons). Access: `auth` +
`EnsureTwoFactorEnabled` + `EnsureUserIsAdmin` + a policy per model
(Owner / Editor roles). `/legacy/*` holds authenticated 301 redirects from
the old admin's addresses to the panel's.

The **Help Center** (`Filament/Pages/Help`) reads `resources/help`
(`Support/Help/KnowledgeBase`), searches with `Support/Help/HelpSearch`
(English, Arabic, Egyptian Arabic; synonyms in `resources/help/synonyms.php`)
and renders with `Support/Help/HelpText`. Articles quote on-screen words as
`[[Label]]`; `HelpKnowledgeBaseTest` checks each exists in the interface in
that language (`Support/Help/InterfaceLabels`).

## Front end

No framework. `resources/js/public.js` is the only entry on public pages:
it starts the header (`public/header.js`) and, unless the visitor prefers
reduced motion, dynamically imports the arrival layer (`public/arrive/`) and
— only on a page that has one — the cinematic engine (`public/cinematic.js`).
Everything renders and works without JavaScript; scripts add motion.
CSS is layered by role (tokens → base → components → pages); see
`FRONTEND_DESIGN_SYSTEM.md`.

## Localization

- Interface strings: `lang/{en,ar}/studio.php` (keys by area: `nav`,
  `field`, `help`, `refuse`…), `setting_labels.php` for Settings field names,
  Laravel's `auth`/`validation`/`passwords`, and `lang/ar.json` for Fortify's
  sentence-keyed messages.
- Content: translatable columns and settings (above) — owner-editable, never
  in `lang/`.
- `PanelArabicRenderTest` renders dashboard screens in Arabic and fails on
  stray English; `RefusalsAreTranslatedTest` covers refusal messages.
- Layout uses logical properties (`margin-inline-start`, `inset-inline-*`) so
  one stylesheet serves both directions; Arabic-specific rules are scoped to
  `:lang(ar)` / `[lang='ar']` and are few (fonts, tracking, the hero).

## Security boundaries

Public pages are read-only except `POST /contact` (validated, honeypot,
timing window, per-IP and per-email limits, IP stored hashed). Everything
else that writes is behind sign-in + mandatory TOTP + role policies, with
the activity log recording publishing, deletion, settings and sign-ins.
Dev-only routes (`/_dev/*`) and commands that create accounts refuse
outside `APP_ENV=local`. Details: `SECURITY_AUDIT.md`.

## Tests

`tests/Feature` (HTTP, Livewire and Filament behaviour, one file per area),
`tests/Unit` (pure classes such as `HelpSearch`), and browser checks in
`tests/Support/*.mjs` and `bin/browser-check.mjs` for what PHP cannot see:
layout, motion, overflow, fonts, real round trips through the dashboard.
`docs/operations/browser-checks.md` lists them.
