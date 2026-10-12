# 0002 — Filament replaces the custom admin, and the site becomes bilingual

**Status:** accepted (client decision, 2026-10-08)
**Date:** 2026-10-08
**Supersedes:** parts of [0001](0001-livewire-for-admin.md) — the admin half, not the public half
**Spike commit:** `31d750b`

## Decision

Two decisions, taken together by the client:

1. **Filament replaces the entire custom admin.** All seventeen Livewire
   components are retired in favour of Filament panels, resources and fields.
2. **The site becomes fully bilingual** — Arabic and English, RTL and LTR,
   across both the public site and the admin.

This document records the evidence behind the compatibility question, what the
replacement costs in capability, and the order the work has to happen in. It
does not relitigate the decision.

---

## The blocking question, and its answer

The decision was taken on the understanding that **Filament is built on
Livewire 3**, and that this project — on Livewire 4.4.6 — would therefore face
a downgrade that could break the existing admin.

**That understanding is one major version out of date.**

| Filament major | latest stable | requires `livewire/livewire` |
| --- | --- | --- |
| **5** | v5.10.1 | **`^4.4.7`** |
| 4 | v4.15.1 | `^3.8.7` |
| 3 | v3.3.56 | `^3.5` |

Filament **4** is the Livewire 3 release. Filament **5** moved to Livewire 4.
There is no conflict to resolve, and no downgrade to plan: Filament 5 *requires*
the major version this project already runs.

Against the project's real `composer.lock`, the resolver adds Filament without
touching a single existing package:

```
Running composer update filament/filament
Lock file operations: 32 installs, 0 updates, 0 removals
  - Locking filament/filament (v5.9.0)
Using version ^5.9 for filament/filament
```

`0 updates, 0 removals` — Livewire 4.4.6 is left exactly where it is. It picks
v5.9.0 rather than v5.10.1 because a partial update may not move locked
packages, and v5.9.0 requires `livewire/livewire ^4.4.2` while v5.10.1 requires
`^4.4.7`. Allowing dependencies to move gets the newest release on a **patch**
bump:

```
Lock file operations: 32 installs, 24 updates, 0 removals
  - Locking filament/filament (v5.10.1)
  - Upgrading livewire/livewire (v4.4.6 => v4.4.7)
```

Both paths are acceptable. v5.10.1 with the patch bump is the one to take.

### What the existing codebase would lose to a Livewire 3 downgrade: nothing

This is now a hypothetical, but it is worth recording because it tells us how
locked in we are. The entire admin uses **five** Livewire symbols:

| symbol | occurrences | introduced |
| --- | ---: | --- |
| `Livewire\Component` | 17 | v1 |
| `Livewire\Attributes\Layout` | 16 | v3.0 |
| `Livewire\Attributes\Url` | 16 | v3.0 |
| `Livewire\WithPagination` | 7 | v1 |
| `Livewire\Attributes\On` | 5 | v3.0 |

Template directives in use: `wire:model` (77), `wire:click` (67), `wire:confirm`
(4), `wire:poll` (3) — all v3. Server-side: `$this->dispatch()` (5) — v3.

**Nothing Livewire-4-specific is used anywhere.** No single-file components, no
islands, no partials, no `wire:ref`, no slots, no Volt — all zero across
`app/`, `resources/` and `tests/`. All seventeen components are classic
class-plus-view pairs.

That is the good outcome and it needs no dressing up: the admin was written
against the Livewire 3 API surface and happens to run on 4.

### PHP 8.5

Filament 5 declares `php ^8.2`. All 32 added packages permit 8.5 — verified
individually against their published constraints, not inferred from the
changelog. The resolver also ran under PHP 8.5.2 with platform requirements
enforced, which is itself proof.

Two packages carry an **upper** bound worth knowing about:

- `nette/php-generator` — `8.1 - 8.5`
- `openspout/openspout` — `~8.3.0 || ~8.4.0 || ~8.5.0`

Both allow 8.5 and both will block PHP 8.6 until they release for it. Neither is
a Filament package; both arrive through Filament's export/import tooling.

One package raises the **effective floor** above Filament's declared `^8.2`:
`symfony/html-sanitizer v8.1.8` requires `php >=8.4.1`. That is a consequence of
this project already being locked to Symfony 8 components, not a Filament
requirement — on a PHP 8.2 project composer would select an older
html-sanitizer. Stated plainly: *on this project*, Filament pulls a transitive
package that needs PHP ≥ 8.4.1. We are on 8.5.2, so it is fine, but the "^8.2"
in Filament's own manifest is not the real floor here.

### What the install adds

**32 packages.** By dist archive size, the three largest:

| package | dist |
| --- | ---: |
| `filament/filament` | 1,822 kB |
| `filament/forms` | 1,515 kB |
| `filament/actions` | 675 kB |

Total measured: **7.56 MB** of dist archives across all 32.

For contrast, ADR 0001 recorded Livewire as "+1 dependency, **no transitive
deps**." That sentence is the one this decision spends. Nine of the 32 are
Filament's own packages; the remaining 23 are its supply chain — icons, a syntax
highlighter, a QR code generator, spreadsheet export, a tiptap PHP port, CSV and
URI libraries.

---

## What Filament replaces, and what is lost

Filament replaces most of this well. The losses are concentrated in three
places: the story builder, the media library's conditional polling, and the
reorder-then-save pattern.

| Surface | Filament equivalent | What is lost |
| --- | --- | --- |
| **Media library** (grid, upload, polling, focal point, trash) | `MediaResource` with a grid layout, `FileUpload`, table `->poll()`, `TrashedFilter` | **Conditional polling.** Ours polls at 3s *only while something is unfinished* (`@if ($hasUnfinished) wire:poll.3s`); Filament's `->poll()` is unconditional, so an idle library polls forever. **The focal-point picker** (127 lines, drag-to-set on the image itself) has no equivalent and must be a custom field. |
| **Media picker** (multi-select, shift-range, recently used) | A custom field, or `Select`/`FileUpload` with a modal | **Shift-range selection** (`extendTo()` with an anchor) and **"recently used in this project"** (`recentOnly` scoped to `projectId`) are bespoke. `selectAllMatching()` and the capacity messages go too. Rebuildable as a custom field; nothing out of the box. |
| **Project index** (local-state drag reorder, one save) | `->reorderable('sort_order')` | **The one-save pattern.** Ours rewrites an array in the browser and writes once on "Save order", in a transaction. Filament's table reorder **persists on every drop**. Functionally fine, architecturally the opposite of what was built on purpose. |
| **Project form** | `ProjectResource` form schema | Little. This is Filament's core competence and the form is plain fields. |
| **Story builder** | `Builder` field | See below. This is where the real loss is. |
| **Categories** | `CategoryResource` | Nothing material. |
| **Journal** (articles) | `ArticleResource` | Nothing material. The `publish` policy ability needs wiring to a custom action. |
| **Services** | `ServiceResource` | Nothing material. |
| **Inquiries CRM** | `InquiryResource` + relation manager for notes | Little. Status transitions, notes, archive/erase and `heard_from` all map to actions and a `NotesRelationManager`. The acknowledgement email stays where it is. |
| **Settings** (60 keys, grouped) | A custom Filament **Page** with a form, not a Resource | Settings are key-value rows, not a model with columns. A Resource is the wrong shape; a settings Page is the right one. The grouped layout is reproducible with `Section`s. Low risk, some rebuild. |
| **Redirects** | `RedirectResource` | Nothing material. `Redirect::normalise()` moves into a mutator. |
| **Clients** | `ClientResource` with `->reorderable()` | Same reorder note as projects. |
| **Pages** | `PageResource` | Nothing material. |

### B1 — the story builder, honestly

What exists today: **1,136 lines** — `StoryBuilder.php` (190), its view (153),
and `story-builder.js` (993). Measured against Filament's `Builder` field, whose
source I read rather than guessed at (`packages/forms/src/Components/Builder.php`,
1,879 lines).

| capability | survives? | evidence |
| --- | --- | --- |
| Block add / reorder / delete / duplicate | **yes** | `addAction`, `reorderAction`, `deleteAction`, `cloneAction`, `moveUpAction`, `moveDownAction` |
| Keyboard reordering | **yes**, differently | `reorderableWithButtons()` gives up/down buttons. Ours is `ArrowUp`/`ArrowDown` on a focused block and `Alt+Arrow` on an image. Parity of *outcome*, not of gesture. |
| Per-block validation with per-block errors | **yes** | Builder validates each block's schema and surfaces errors against the item |
| Collapse / expand | **yes**, and client-side only | `collapseAction` etc. carry `livewireClickHandlerEnabled(false)` |
| **Undo / redo** | **no — does not come back** | **Zero** occurrences of `undo`/`redo` in Builder.php's 1,879 lines. Ours is a 30-deep bounded history over JSON snapshots with an `aria-live` announcement. Rebuilding it inside Filament means intercepting Filament's own state array, which is server-held — a fundamentally harder problem than the client-side one we solved. Realistically: gone. |
| **localStorage draft recovery** | **no — does not come back** | **Zero** occurrences of `localStorage` in Builder.php. Ours debounces a draft to `localStorage` every 2s, tags it with a `crypto.randomUUID()` tab id, detects a competing tab via the `storage` event, and offers recovery on return. Filament has no concept of a client-side draft. Gone. |
| **One server call per editing session** | **no — does not come back** | Decisive: line 1682 of the Builder view wires drag-end to `$wire.mountAction('reorder', { items: … })`, and `getMoveDownAction()` mutates `$component->rawState()` in a server closure. **Every add, delete, clone, move and drop is a Livewire round trip.** Ours sends the entire story once, as an array, to `saveStory()`. This is not a tuning problem; it is how Filament holds form state. Gone. |
| **Cross-block image drag** | **no — does not come back** | Builder reordering operates on one sortable list of blocks. Ours drags an image *out of one block and into another* (`bindImageDrag`, with edge auto-scroll at 80px / 18px per tick). Rebuildable only by replacing the field wholesale. Gone as a Builder feature. |
| **Atomic save with per-block errors** | **partly** | Filament validates per block and saves the form in one request, so the *form* is atomic. But because block structure mutations already hit the server one at a time, "the whole session is atomic" is no longer true — the structure is already persisted into server state before you press save. |
| **`media_usages` integrity** | **yes, if rebuilt deliberately** | Not a Builder feature either way. Ours flows through `SyncBlockMedia` inside `SaveStory`'s transaction, and `MediaPolicy::delete()` refuses to delete an image with usages. Filament will honour that policy unchanged (see C3). The *wiring* — that saving a story syncs usages — has to be rebuilt in the Resource's `mutateFormDataBeforeSave`/`afterSave`. It survives, but only because someone writes it again. |
| **Optimistic concurrency** (refuse rather than overwrite) | **no, not by default** | Ours compares `updated_at` against the version loaded and refuses the save with the editor's work intact. Filament has no equivalent; last write wins. Rebuildable in an `afterSave` guard, but it is bespoke and was written because it mattered. |

Summary in one line: **of the eight capabilities named in the brief, three
survive, two survive only if rebuilt by hand, and three do not come back.**

### B2 — keeping the story builder as a custom Livewire component inside Filament

The client has chosen full replacement. This records the cost of that choice,
not an argument against it.

**It is technically viable, and more cheaply than the decision assumed** —
because of how Part A came out. Filament 5 ships
`Filament\Schemas\Components\Livewire`, which mounts an arbitrary Livewire
component inside a form schema:

```php
Livewire::make('admin.story-builder', ['project' => $this->record])
```

Since Filament 5 *is* Livewire 4, `App\Livewire\Admin\StoryBuilder` and its 993
lines of JavaScript would run **unchanged**. No downgrade, no rewrite, no
compatibility shim. The version answer that was supposed to be the risk is the
thing that makes this easy.

The one real constraint: a mounted Livewire component is an **island**. Its
state is its own and does not participate in the parent form's state or
validation. You would keep the story builder's own save button and its own
atomic `saveStory()` call, separate from the Resource's save. That is exactly
how it works today, so nothing is lost — but the page would have two save
affordances, which is a genuine UX wart and the honest reason to think twice.

**Cost of choosing full replacement instead:** the five capabilities in the
table above that do not survive or must be rebuilt, plus roughly 1,100 lines of
working, tested code deleted. **Cost of the island:** one awkward page with two
save buttons, and a component that is outside Filament's conventions and will
need hand-maintenance when Filament's styling changes around it.

---

## Auth and security

### C1 — mapping

| today | under Filament | verdict |
| --- | --- | --- |
| **Fortify** login, password reset, profile/password update | Filament ships its own login, password reset and profile pages per panel | **Choose one.** Running both means two login routes and two sessions-of-record. Recommendation: keep Fortify as the authentication backend and point the panel at it (`->login(false)` and guard the panel), rather than adopting Filament's auth pages. Less rework, and keeps the throttle below. |
| **Mandatory 2FA** via `EnsureTwoFactorEnabled` middleware | Filament panels accept arbitrary middleware: `->middleware([EnsureTwoFactorEnabled::class])` or `->authMiddleware([…])` | **Survives unchanged.** This is the cheapest part of the whole migration. |
| **`UserRole` enum** (Owner, Editor) | No change — it is a model concern, not an auth one | **Survives unchanged.** |
| **10 policies** | Filament observes registered Laravel policies with no interface or trait | **Survives, with one gap — see C3.** |
| **Login throttle keyed on email+IP** (`Limit::perMinute(5)->by(email|ip)`) | Filament's own login page uses `danharrin/livewire-rate-limiting` (which the install adds) keyed by IP | **Rewritten or retained.** If Fortify keeps the login route, the existing limiter is untouched. If Filament's login is adopted, the email+IP composite key must be re-implemented against Filament's `Login` page — the package's default is weaker than what we have. |
| **2FA challenge throttle** keyed on `login.id` | Filament has its own MFA challenge flow | Same choice as above. |

### C2 — does Filament 5 provide MFA itself, and would adopting it force re-enrolment?

**Yes it provides MFA, and yes, adopting it would force every existing user to
re-enrol.** Verified, not assumed.

Filament 5 ships two first-party providers: **App Authentication** (TOTP, with
optional recovery codes) and **Email Authentication** (one-time codes by
email). Consistent with that, the install adds `pragmarx/google2fa-qrcode` and
`chillerlan/php-qrcode`.

The re-enrolment problem is a **column mismatch**:

| | Fortify (what we have) | Filament 5 |
| --- | --- | --- |
| secret | `two_factor_secret` | `app_authentication_secret` |
| recovery codes | `two_factor_recovery_codes` | `app_authentication_recovery_codes` |
| confirmation | `two_factor_confirmed_at` | *(no equivalent; `has_email_authentication` for the email provider)* |
| contract | Fortify's own | `HasAppAuthentication` + `InteractsWithAppAuthentication` |

Filament will not read Fortify's columns. Adopting Filament MFA means a data
migration of encrypted secrets into differently-named columns, or — far more
likely in practice — **telling every user to re-enrol their authenticator**.

There is also a semantic loss: Filament's TOTP provider has no
`two_factor_confirmed_at` analogue, and
`EnsureTwoFactorEnabled` deliberately requires a *confirmed* second factor, not
merely an enrolled one. That distinction would have to be rebuilt.

**Recommendation: keep Fortify's 2FA.** It works, it is mandatory, it is
already enrolled, and the middleware that enforces it drops into a Filament
panel unchanged. Adopting Filament's MFA buys nothing here and costs a
re-enrolment event for every user.

### C3 — are our policies honoured unchanged?

**Yes — and there is a specific, verified gap.**

Filament calls plain Laravel policies with no interface or trait required, using
`viewAny`, `view`, `create`, `update`, `delete`, `deleteAny`, `forceDelete`,
`forceDeleteAny`, `restore`, `restoreAny` and `reorder`.

Our ten policies define `viewAny`, `view`, `create`, `update`, `delete`,
`restore`, `forceDelete`, plus `publish` and `reorder` where relevant. **None of
them defines `deleteAny`, `forceDeleteAny` or `restoreAny`** — the three bulk
abilities.

Verified empirically against `ProjectPolicy` as the owner user:

```
viewAny          defined  -> ALLOW
reorder          defined  -> ALLOW
deleteAny        MISSING  -> deny
forceDeleteAny   MISSING  -> deny
restoreAny       MISSING  -> deny
```

A missing method on a registered policy **denies**. That fails closed, which is
the safe direction — but it means **every Filament bulk action will be refused
or hidden** until those three methods are added to each of the ten policies.
Thirty small methods. Cheap, but it will present as "bulk delete doesn't work"
and waste an afternoon if nobody wrote it down. It is now written down.

> ### Correction, Task 31 — the paragraph above is wrong in the dangerous direction
>
> The measurement was right and the conclusion was wrong, because the thing
> measured was `Gate::allows()` and the thing Filament calls is not.
>
> `filament/filament/src/helpers.php::get_authorization_response()` returns
> `Response::allow()` when the model has no policy, **or when the policy exists
> but lacks the method being asked for**. It does not fall through to the Gate.
> Measured on this application before the panel was made strict:
>
> ```
> Gate::allows('viewAny', Tag::class)      false
> TagResource::canViewAny()                true
> ClientPolicy has no view()
> Gate::allows('view', $client)            false
> ClientResource::can('view')              true
> ```
>
> So a missing method is an **unspoken yes**, not a refusal. Two consequences
> this ADR missed:
>
> 1. **The count of ten was wrong.** `Tag`, `ArticleCategory` and `User` have
>    resources in the panel and had **no policy at all**, so they were editable
>    and deletable by any Editor. Three policies were written in Task 31, taking
>    the total to thirteen.
> 2. **The thirty methods were guarding the wrong failure.** They are still
>    worth having — a policy that answers is better than one that is guessed at
>    — but what actually closes the hole is `->strictAuthorization()` on the
>    panel, which turns a missing policy or method into a `LogicException`
>    naming the model and the ability. A gap is now a crash in development
>    rather than a permission in production.
>
> The old sentence stands above, unedited, because a decision record that
> quietly rewrites its own measurements is worth less than one that shows where
> it was wrong.

---

## Bilingual architecture

### D1 — recommendation: **translatable JSON columns**, with one exception

The alternative — a `*_translations` table per model — is the textbook answer
and it is the wrong one for this codebase. Four criteria:

**Query cost on list pages.** JSON columns cost nothing: the translated text is
in the row already loaded. Translation tables need a join per locale on every
index page, and the admin has eleven of them. The public work index already
runs at a flat query count that the test suite asserts; a join-per-locale
pattern puts that under permanent pressure.

**Sorting and filtering by a translated field.** This is the one place
translation tables genuinely win — `ORDER BY t.title` with an index is simply
better than ordering by a JSON extraction. MySQL 8.4 can index a JSON path
through a generated column, which closes most of the gap but means a generated
column plus an index **per locale per sortable field**. With two locales and
~4 sortable fields that is 8 generated columns. Acceptable. Not free.

**Slug uniqueness per locale.** Every slug today has a single-column unique
index (`projects_slug_unique`, and four more). Under JSON this becomes a
generated column per locale plus a unique index on each — `slug_en`, `slug_ar`,
unique on both. Under translation tables it is one composite unique
`(locale, slug)`, which is cleaner. A real point for tables.

**Behaviour in a Filament form.** Decisive. Filament edits a model's attributes.
A JSON translatable attribute is a field like any other, and a locale switcher
on the form is a tab per locale over the same fields. A translation table is a
`HasMany` and becomes a **relation manager** — a separate panel below the form,
not a tab inside it. Editing the Arabic title would mean leaving the project
form. For a bilingual site where both locales are edited together, that is the
wrong shape.

**And the argument that settles it:** `project_blocks.data` is *already a JSON
column*. All story block text — body, quote, caption — lives inside it. A
translation table cannot cleanly hold text that lives in a JSON blob, so a
tables approach would mean **two different translation mechanisms** in one
codebase: tables for columns, JSON for blocks. One mechanism is worth a lot.

**Recommendation:** JSON translatable columns on the 25 identified columns
across 9 tables, generated columns plus unique indexes for the five slugs, and
the same JSON convention inside `project_blocks.data`.

| table | translatable columns |
| --- | --- |
| `projects` | slug, title, subtitle, excerpt, description |
| `articles` | slug, title, subtitle, excerpt, body |
| `services` | slug, name, summary, description |
| `pages` | slug, title, standfirst, body |
| `categories` | name, slug, description |
| `media` | alt, caption |
| `clients` | name |
| `settings` | value |
| `project_blocks` | inside `data` (JSON) |

**25 columns**, plus the block JSON.

### D2 — URL strategy: prefixed locale, with English unprefixed

`/work/salt-line` and `/ar/work/salt-line`. English keeps its current URLs, so
**every existing URL, sitemap entry and redirect row stays valid** — a decisive
practical advantage over prefixing both.

Three concrete consequences in this codebase:

**1. The catch-all page route will swallow `/ar`.** The last route is:

```php
Route::get('/{page:slug}', …)
    ->where('page', '^(?!work$|journal$|services$|archive$|about$|contact$
                      |admin$|login$|…)[a-z0-9-]+$')
```

`/ar` matches `[a-z0-9-]+` and is not in the blocklist, so it would resolve as a
page with slug `ar` before the locale group is consulted. The locale prefix
group must be registered *before* this route **and** `ar` added to the negative
lookahead. Both, not either — the blocklist is the thing a test can assert.

**2. Canonical and hreflang.** Each page gains a self-referential canonical plus
`<link rel="alternate" hreflang>` for `en`, `ar` and `x-default` (pointing at
English). There is **no hreflang anywhere in the codebase today** — grep returns
zero — so this is new work in `SeoResolver`, not a modification. Sitemaps gain
`xhtml:link` alternates per URL; the seven sitemap files become seven files with
two entries per URL, not fourteen files.

**3. The redirect map becomes locale-aware.** `redirects` stores raw
`from_path`/`to_path` with a unique on `from_path`. A slug change today writes
one row via `RedirectsOnSlugChange`. Under a prefix it must either write **two**
rows per change (`/work/x → /work/y` and `/ar/work/x → /ar/work/y`) or
`HandleRedirects` must strip the locale prefix, match, and re-apply it.
**Prefer the middleware change** — one place, and it keeps the map half the
size and locale-agnostic. The whole-map cache under one key still works.

### D3 — the font problem

Archivo, Inter Tight and JetBrains Mono carry **no Arabic coverage**. The
`unicode-range` declarations in `fonts.css` cover Latin and Latin-Extended
only; Arabic text would fall through to whatever the OS supplies, which on a
luxury portfolio is not acceptable.

Proposed Arabic faces, SIL OFL only, self-hosted:

| role | Latin today | Arabic proposal | why |
| --- | --- | --- | --- |
| display | Archivo (heavy condensed) | **Rubik** (OFL) or **Cairo** (OFL) | Rubik is a geometric sans with real weight range and a confident high-contrast Arabic; Cairo is the safer, more neutral pairing. Neither is condensed — see D4. |
| text | Inter Tight | **IBM Plex Sans Arabic** (OFL) | The closest thing to a neutral grotesque Arabic with a matching weight range; pairs honestly with Inter's proportions. |
| mono | JetBrains Mono | **No true OFL Arabic monospace exists.** | Arabic is cursive and does not monospace meaningfully. Recommendation: keep JetBrains Mono for Latin digits and codes, and set Arabic metadata in **IBM Plex Sans Arabic** at the mono's size. The "mono voice" becomes a *small-caps-ish text voice* in Arabic. This is a real design change, not a substitution. |

**The size-adjust story gets substantially worse.** The current fallback metrics
are tuned to three decimal places against a measured x-height ratio:

```
size-adjust: 101.4%;  ascent-override: 86.6%;   /* Archivo Fallback */
size-adjust: 105.3%;  ascent-override: 92.0%;   /* Inter Fallback */
```

Two problems with two scripts:

1. **x-height is not a meaningful metric for Arabic.** The relevant measure is
   baseline-to-baseline with room for deep descenders (ج ح ع) and high marks.
   The formula `size-adjust = webfont x-height ÷ fallback x-height` has no
   Arabic analogue, so these numbers cannot simply be recomputed — the method
   itself does not transfer.
2. **There is no reliable local Arabic fallback to measure against.** The Latin
   fallbacks name `local('Arial')`, `local('Helvetica Neue')` — fonts that are
   present and whose metrics are known. The Arabic equivalent differs by
   platform (Geeza Pro on macOS/iOS, Segoe UI on Windows, Noto Naskh on
   Android), with materially different metrics. A single `size-adjust` cannot be
   correct for all three.

**Honest position:** accept a CLS budget for Arabic that we do not accept for
Latin, or ship Arabic with `font-display: block` and a short block period and
take the blank-text risk instead of the shift risk. This needs a measured
decision in its own task; it is not a line of CSS.

### D4 — the hero in Arabic: what actually happens

Stating the problem, not fixing it.

The wordmark is one `<h1>` text node with:

```css
font-size: var(--wordmark-size);   /* ~206px at desktop */
line-height: 0.80;
letter-spacing: var(--tracking-tight);
text-transform: uppercase;
white-space: nowrap;
text-align: center;
padding-bottom: 0.10em;
```

...occluded across its middle by an opaque plate that sits above it in the
stacking order. Five things happen in Arabic, in descending order of severity:

**1. `letter-spacing` breaks the script.** Arabic is cursive and connected.
Letter-spacing inserts gaps *between joined letters*, severing the joins. The
word does not become airier; it becomes visibly broken, like English rendered as
`H E L L O` but worse, because the connecting strokes are part of the letterform.
This single property is the most damaging line in the file.

**2. The occlusion stops being readable.** In Latin you read `PHOT⟦plate⟧APHER`
and your eye reconstructs the word, because each letter is an independent glyph
that is either visible or not. In Arabic, a letter's *shape depends on its
neighbours* — initial, medial, final, isolated. Hiding the middle of a cursive
word leaves two fragments whose visible glyphs are in medial forms with nothing
to join to. They do not read as "a partially hidden word"; they read as
different letters, or as nothing. **The central conceit of the composition does
not survive translation.**

**3. `text-transform: uppercase` silently does nothing.** Arabic is unicase.
The typographic voice of the whole brand — heavy condensed capitals — has no
Arabic equivalent at all. The wordmark's authority in English comes from a
register that does not exist in the target script.

**4. `line-height: 0.80` will clip.** The value plus `padding-bottom: 0.10em`
was tuned so Latin descenders clear whatever follows. Arabic has deeper
descenders and marks that sit well above the cap line. At 206px, 0.80 line-height
will cut glyphs top and bottom.

**5. The interlock mirrors, and the rails must mirror with it.** The statement
is crossed by the wordmark's first line and the metadata and stage rails sit
beside a plate whose column is derived from image orientation. Under `dir="rtl"`
the reading order inverts, so the side the eye enters from inverts, and every
rail, every `transform-origin: left center` on the link rules, and the
asymmetric `clip-path` insets need logical equivalents. This part is mechanical.
Items 1–4 are not.

**What the composition would have to become** (stated, not proposed): either a
second, separately-designed Arabic hero that shares the grid but not the
occlusion idea, or an abandonment of mid-word occlusion in favour of a device
that survives both scripts. It cannot be the same composition with a different
font.

### D5 — surfaces holding English copy

| surface | count |
| --- | ---: |
| Settings keys (seeded, editable copy) | **60** |
| Public Blade views | 64 |
| Admin Blade views (labels, buttons, empty states) | 21 |
| Error pages (403, 404, 419, 429, 500, 503) | 6 |
| Custom mail views (inquiry received, acknowledged) | 2 |
| Vendor mail views (published Laravel templates) | 16 |
| `FormRequest` classes with messages/attributes | 6 |
| Inline custom validation messages | 5 |
| Enum `label()` methods (BlockType, InquiryStatus, MediaStatus, PublishStatus, UserRole) | 5 |
| Seeders producing text content | 7 |
| **`lang/` translation files today** | **0 — the directory does not exist** |

**Total surfaces requiring a second locale: 192**, of which the 60 settings keys
are the only ones already editable without a deploy.

That last row is the real headline: this project has never been localised at
all. There is no `lang/` directory, no `__()` calls to convert, no locale
middleware. Every one of the 192 surfaces is a first-time extraction.

---

## Recommended sequence

Order matters more than anything else in this plan. **Building Filament
resources before translatable columns exist means building every form twice** —
once against `title`, then again against `title->en` / `title->ar` with a locale
tab. Eleven resources is eleven double-builds.

The inverse risk is smaller: translatable columns in front of the *existing*
admin need the existing forms touched once, and those forms are being deleted
anyway.

**Therefore: translation first, Filament second.** Specifically:

| # | step | risk | why |
| --- | --- | --- | --- |
| 1 | Add `ar` to the catch-all route blocklist; register the locale prefix group; locale middleware and a switcher. No content translated yet. | **low** | Pure routing. Fully testable. Reversible in one commit. |
| 2 | Make `HandleRedirects` locale-aware (strip prefix, match, re-apply). | **low** | One middleware, existing tests cover the map. |
| 3 | Translatable JSON columns on the 25 columns + generated slug columns and unique indexes. Backfill every existing row with `{"en": "<current value>"}`. | **high** | Migration over live content with a unique-index change on five tables. The backfill must be idempotent and reversible. **This is the step most likely to go wrong** — see below. |
| 4 | Update the **existing** admin forms and public views to read translated attributes. Keep the custom admin working. | medium | Touches 85 views. Mechanical but broad. The payoff is that step 6 builds each form once. |
| 5 | `hreflang`, canonical alternates, sitemap `xhtml:link`. | low | Additive to `SeoResolver`. Asserted by the existing SEO tests. |
| 6 | Install Filament. Build the panel, auth wiring (keep Fortify + its 2FA middleware), and the three `*Any` methods on all ten policies. | medium | The policy gap is known and cheap; the auth choice is the judgement call. |
| 7 | Build the eleven resources + the settings Page against already-translatable models. | medium | Volume, not difficulty. |
| 8 | Story builder: `Builder` field, accepting the three losses — **or** the `Livewire::make()` island. | **high** | The only step where capability is actually destroyed. Decide explicitly, in writing, before starting. |
| 9 | Arabic fonts and the Arabic hero composition. | **high** | Design work, not engineering. Depends on a decision that does not exist yet (D3, D4). |
| 10 | Retire the seventeen Livewire components, their views and their tests. | low once 7–8 land | Deletion. |

### The step most likely to go wrong: step 3

Not step 8, which is where the *loss* is — step 8's losses are known and
chosen. Step 3 is where something can silently break.

Converting five single-column unique slug indexes into generated columns with
per-locale unique indexes, while backfilling every existing row into JSON, over
content that is live and that `RedirectsOnSlugChange` writes redirect rows from.
Three specific failure modes:

- A partial backfill leaves rows where `slug->en` is null, and the generated
  column is then null, and the unique index permits unlimited nulls — so
  duplicate slugs become possible without any error being raised.
- `RedirectsOnSlugChange` fires on slug change. A migration that writes slugs
  will fire it for every row, filling the redirect map with hundreds of
  self-referential rows unless it is suppressed during the migration.
- Rollback is not symmetric: dropping a generated column is easy, but recovering
  the original single-column unique index requires the JSON to be collapsible
  back to one value per row, which is only true before anyone enters Arabic.

Mitigation: do step 3 behind a maintenance window, with a verified backup, a
dry-run against a database copy, and a test that asserts the row count and
distinct slug count are identical before and after.

### The point of no return

**Step 3's migration, specifically the commit that drops the single-column
unique slug indexes.**

Everything up to and including step 2 is additive and revertible with
`git revert`. Step 3 changes the shape of live content: once `title` holds
`{"en": "…", "ar": "…"}` and a second locale has real text in it, reverting
means choosing which locale to throw away. The custom admin cannot read the new
shape, so reverting the code without reverting the data leaves a broken admin,
and reverting the data loses Arabic content.

Steps 6–10 are *expensive* to reverse but not *lossy*: the seventeen Livewire
components still exist in git and still work against translatable models if step
4 was done properly. **That is the argument for doing step 4 — keeping the old
admin alive against the new column shape — even though it is 85 views of work
that gets deleted at step 10.** It buys a working fallback across the entire
Filament build, and it is the only thing standing between the project and a
one-way door at step 3.

Concretely: **the last cheap moment to abandon Filament is the commit before
step 6.** The last cheap moment to abandon *bilingual* is the commit before
step 3.

---

## Consequences

**Accepted:**

- 32 new composer packages (7.56 MB of dist archives) replacing a dependency
  footprint ADR 0001 recorded as "+1, no transitive deps".
- ~1,100 lines of working, tested story-builder code deleted, and with it
  undo/redo, localStorage draft recovery, one-call-per-session saves, and
  cross-block image drag.
- The reorder-then-save pattern on projects and clients becomes
  persist-on-every-drop.
- Conditional media polling becomes unconditional polling.
- A permanent dependency on Filament's release cadence for the admin, where the
  project currently depends only on Livewire's.

**Gained:**

- Eleven CRUD surfaces maintained by someone else.
- Bulk actions, exports, filters and a notification system we have not built.
- A conventional admin a second developer can work on without reading
  seventeen bespoke components.
- Bilingual content, which is the actual client requirement and is not reachable
  cheaply in the current admin either way.

**Unchanged:**

- The public site. Nothing in this decision touches the 49 kB of public
  JavaScript, the motion architecture, the SEO infrastructure or the media
  pipeline.
- `UserRole`, the ten policies (plus 30 small methods), and
  `EnsureTwoFactorEnabled`.
- Fortify as the authentication backend, and every user's existing 2FA
  enrolment — provided we decline Filament's own MFA.
