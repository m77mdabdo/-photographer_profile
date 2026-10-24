# Contributing

How to make a safe change. Read `ARCHITECTURE.md` first for where things live.

## Conventions

- **PHP:** `declare(strict_types=1);`, typed parameters and returns, `final`
  for classes nothing extends. Formatting is Laravel Pint's `laravel` preset
  (`pint.json`): run `vendor/bin/pint` before committing; `vendor/bin/pint --test`
  must pass.
- **Naming:** Laravel's. Controllers per page type (`ProjectController`),
  Actions as verbs (`StoreUploadedMedia`, `SaveStory`), Support classes as
  nouns for what they know (`HeroSplit`, `StructuredData`). Tests describe
  behaviour: `test_a_wrong_code_is_refused_in_arabic`, not `test_login_2`.
- **Comments say why, not what.** The codebase explains decisions next to the
  code they constrain, often with the measurement that forced them. Keep that
  habit; delete a comment when the code it explains changes.
- **No copy in templates.** A sentence a visitor reads is either a setting
  (`Settings::get('group.key')`), a record's field, or — for the dashboard and
  shared interface words — a `lang/` string. Never a literal in Blade or PHP.
- **Both languages, always.** Every `lang/en` key has a `lang/ar` twin; every
  layout rule uses logical properties (`margin-inline-start`, not
  `margin-left`); every new screen is checked in Arabic.
- **CSS:** use the tokens in `resources/css/tokens.css` (spacing `--sp-*`,
  type `--fs-*`, colour `--text-*`/`--bg*`/`--accent`), no new literal colours.
  `!important` only to beat third-party CSS, with a comment.
- **JavaScript:** small ES modules, no dependencies; the page must work with
  scripts off; motion must stop under `prefers-reduced-motion`.

## Recipes

### Add a field to an existing dashboard form

1. **Migration** — additive only (`$table->string('x')->nullable()->after(…)`),
   with a working `down()`. Translatable text is a `json` column.
2. **Model** — add to `$fillable`. For translatable text, add the column to
   its table's list in `app/Support/TranslatableSchema.php` (the single list
   the models, the migration helpers and the backfill all read) *and* to the
   Resource's `TRANSLATED` constant.
3. **Resource form** — in `app/Filament/Resources/<Thing>/<Thing>Resource.php`.
   Translatable text goes inside `Translatable::tabs([...])` so it gets the
   English/العربية tabs. Label with `__('studio.field.x')`; add the key to
   `lang/en/studio.php` and `lang/ar/studio.php`. A field that is easy to
   misread gets `->hintAction(HelpHint::action('x'))` plus texts under
   `studio.help_hint.x` and an entry in `HelpHint::ARTICLES`.
4. **Public view** — read it from the record; translatable fields render
   through `<x-t :of="$record" field="x" />` so the Arabic fallback is marked.
5. **Tests** — a feature test that saves it through the form
   (`Livewire::test(Edit…::class)->fillForm([...])->call('save')`) and one that
   sees it on the public page in both locales.
6. **Help** — if the owner needs to know about it, add or extend an article
   in `resources/help/articles/` (see below).

### Add a sentence the owner can edit

1. Add a row to the registry in `app/Support/SiteCopy.php`
   (`key`, English `value`, `ar`, `type`, `group`).
2. Add its field name to `lang/en/setting_labels.php` and
   `lang/ar/setting_labels.php` (key with dots as underscores).
3. Read it with `Settings::get('group.key')` in the view.
4. Deploy runs `SettingsSeeder`, which creates the new key and never touches
   existing values. The Settings page and the Help Center's settings map pick
   it up automatically.

### Add a public section or page

Route in `routes/public.php` (registered for every locale automatically),
controller returning a view, view composed from existing components. Add the
page to `bin/browser-check.mjs`'s targets and to
`tests/Support/responsive-check.mjs`'s `PAGES`, and to the sitemap if it is
indexable (`Services/Seo/SitemapBuilder`).

### Add interface translations

Both files in the same commit. Arabic plurals take Laravel's six forms
(zero, one, two, 3–10, 11–99, other) when the count is shown. Run
`php artisan test --filter=PanelArabicRenderTest` — it renders the dashboard
in Arabic and fails on stray English.

### Add or change a Help article

Articles are PHP arrays in `resources/help/articles/<topic>.php`, English and
Arabic side by side. Mark every word the person must find on screen as
`[[Label]]` — `HelpKnowledgeBaseTest` fails if it is not in the interface in
that language. Give it `intents` (concepts from `resources/help/synonyms.php`)
so search finds it by meaning. Mark `verified` as `workflow` only when a
browser check in `tests/Support/` actually performs it.

### Add tests

- Behaviour through HTTP or Livewire: `tests/Feature/<Area>/…Test.php`,
  `RefreshDatabase`.
- Pure logic: `tests/Unit`.
- Anything visual, timed or cross-page: a browser check
  (`tests/Support/*.mjs`), documented in `docs/operations/browser-checks.md`.
- A test that pins a built asset's hash or size (`StudioPanelTest`) changes
  when CSS/JS changes: update the pin with a one-line reason.

## Migrations: the safe way

- Additive first (new nullable column, backfill command, then constraints).
- Never drop or rename a column with data in the same release that stops
  using it.
- Generated columns (`slug_en`, `slug_ar`) are derived — do not write them.
- Test `down()` locally; note anything irreversible in
  `docs/ROLLBACK_AND_RECOVERY.md`.
- Never run destructive commands (`migrate:fresh`, `db:wipe`) against a
  database with real content.

## Review checklist

- [ ] `php artisan test` passes (config **not** cached) and `vendor/bin/pint --test` passes.
- [ ] New interface words exist in English and Arabic; no literal copy in templates.
- [ ] Checked in the browser in both languages, on a phone width and a desktop width.
- [ ] Works with JavaScript off and with reduced motion.
- [ ] Policies cover any new action; refusals have a translated reason.
- [ ] Uploads validated by content; no new public write endpoint without throttling.
- [ ] No secrets, credentials or local-only data in the diff.
- [ ] Help / user guide updated if the owner sees the change.
- [ ] Relevant browser checks run (see `docs/operations/browser-checks.md`).
