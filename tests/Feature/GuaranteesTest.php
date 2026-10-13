<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Page;
use App\Models\User;
use App\Support\TranslatableSchema;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * PART 0 — THE GUARANTEES THE COMMENTS CLAIM, EXECUTED.
 *
 * Three times in this project a confident comment described an intention the
 * code did not implement, and each hid a real defect: ADR 0002 said a missing
 * policy method fails closed (it fails open, and three models were reachable
 * by any Editor for four tasks); MediaResource said Filament surfaced a
 * policy's refusal (it hid the button); SLUG_PATTERN said it was built from
 * RESERVED (it was hand-written, had drifted, and /legacy 404'd).
 *
 * Task 33 swept the codebase for comments asserting a GUARANTEE — a claim
 * about what cannot happen, what is always true, or what two things cannot
 * drift apart — and verified each by running something. 192 such claims; the
 * ones that were true but that NOTHING CHECKED are below.
 *
 * A guarantee nothing checks will drift the moment somebody edits nearby.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class GuaranteesTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    // ----------------------------------------------------------------- 1

    /**
     * CLAIM — Models/Concerns/Translatable.php:51
     * "Read from TranslatableSchema by table name rather than declared per
     *  model, so the migration, the backfill and the models cannot disagree."
     *
     * TRUE, and nothing checked it. A column added to TranslatableSchema
     * without a migration would return from columnsFor(), and the first write
     * would fail at runtime on a column that does not exist.
     *
     * Asserted against the DATABASE, not against another PHP list — the whole
     * point is that the schema is the arbiter.
     */
    public function test_every_declared_translatable_column_exists_as_json(): void
    {
        $drift = [];

        foreach (TranslatableSchema::all() as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "TranslatableSchema names a missing table: $table");

            $json = [];

            foreach (DB::select("SHOW COLUMNS FROM `{$table}`") as $column) {
                if (strtolower($column->Type) === 'json') {
                    $json[] = $column->Field;
                }
            }

            foreach ($columns as $column) {
                if (! in_array($column, $json, true)) {
                    $drift[] = "{$table}.{$column} is declared translatable but is not a json column";
                }
            }
        }

        $this->assertSame([], $drift, implode("\n  ", $drift));

        /*
         * NOT the reverse. media.palette is a json column holding a colour
         * array — json is a storage choice, translation is a content one, and
         * requiring every json column to be translatable would be a different
         * and wrong claim.
         */
    }

    /**
     * And every translatable slug really is NOT NULL and uniquely indexed per
     * locale — the property ResourceDefaults::slugField() validates against.
     *
     * CLAIM — ResourceDefaults.php:166 "exactly what the index checks, which
     * is why the two cannot disagree."
     */
    public function test_every_slug_column_is_not_null_and_unique_per_locale(): void
    {
        $problems = [];

        foreach (TranslatableSchema::all() as $table => $columns) {
            if (! in_array('slug', $columns, true)) {
                continue;
            }

            foreach (['en', 'ar'] as $locale) {
                $name = "slug_{$locale}";

                $column = collect(DB::select("SHOW COLUMNS FROM `{$table}`"))->firstWhere('Field', $name);

                if ($column === null) {
                    $problems[] = "{$table}.{$name} does not exist";

                    continue;
                }

                if ($column->Null !== 'NO') {
                    $problems[] = "{$table}.{$name} is nullable";
                }

                $unique = collect(DB::select("SHOW INDEX FROM `{$table}`"))
                    ->where('Column_name', $name)
                    ->where('Non_unique', 0);

                if ($unique->isEmpty()) {
                    $problems[] = "{$table}.{$name} has no unique index";
                }
            }
        }

        $this->assertSame([], $problems, implode("\n  ", $problems));
    }

    // ----------------------------------------------------------------- 2

    /**
     * CLAIM — config/media.php:47
     * "The single source of truth for file extensions."
     *
     * TRUE — StoreUploadedMedia and ProcessMediaVariants both read
     * config('media.extensions'). Unchecked: an accepted mime with no entry
     * in that map throws at upload time, on the one path that accepts files
     * from the internet.
     */
    public function test_every_accepted_mime_has_an_extension(): void
    {
        $extensions = config('media.extensions');

        foreach (config('media.accepted_mimes') as $mime) {
            $this->assertArrayHasKey(
                $mime,
                $extensions,
                "$mime is accepted for upload but has no extension in config('media.extensions')"
            );
        }

        /*
         * And every rendition format the pipeline builds has one too.
         * config('media.formats') is keyed BY FORMAT with per-format quality
         * and widths underneath, so the keys are the formats.
         */
        foreach (array_keys(config('media.formats')) as $format) {
            $this->assertArrayHasKey(
                $format,
                $extensions,
                "the pipeline builds $format renditions but has no extension for them"
            );
        }
    }

    // ----------------------------------------------------------------- 3

    /**
     * CLAIM — Models/Page.php:69
     * "A locked page cannot be deleted through any path ... The footer links
     *  to these by slug and a 404 there is a compliance problem."
     *
     * ───────────────────────────────────────────────────────────────────────
     * TRUE OF DELETION, AND THE REASONING COVERED MORE THAN THE CODE DID.
     *
     * That sentence is about the FOOTER LINK, not about deletion: what must
     * not happen is a 404 where the privacy notice is linked by name.
     * Deleting the page produces one. So does moving its address, and nothing
     * stopped that at all.
     *
     * The custom admin's page manager refused a slug change by hand. The
     * panel had no equivalent, so renaming the privacy notice through
     * /admin/pages moved /privacy and broke both footer links silently —
     * found in Task 34 Part B, when a test that had driven the old admin was
     * repointed at the panel and failed for a reason that had nothing to do
     * with the move.
     *
     * The guard is now on the model, where no admin can be written around it.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_a_locked_pages_address_cannot_be_moved_by_any_path(): void
    {
        $this->seed(\Database\Seeders\LegalPagesSeeder::class);

        $page = Page::query()->where('slug', Page::PRIVACY)->sole();
        $this->assertTrue($page->is_locked);

        $arabic = $page->getTranslation('slug', 'ar', fallback: false);

        // 1. Through the model.
        $page->slug = 'privacy-policy';
        $page->save();
        $this->assertSame(Page::PRIVACY, $page->fresh()->slug, 'the model let the address move');

        // 2. Through the other locale, which is a different generated column.
        $page = $page->fresh();
        $page->setTranslation('slug', 'ar', 'khususiyya-jadida');
        $page->save();
        $this->assertSame(
            $arabic,
            $page->fresh()->getTranslation('slug', 'ar', fallback: false),
            'the Arabic address moved'
        );

        // 3. And the pages the footer names still resolve.
        foreach ([Page::PRIVACY, Page::TERMS] as $slug) {
            $this->get('/'.$slug)->assertOk();
        }
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * CLAIM — Models/Page.php:70
     * "A locked page cannot be deleted through any path — not the admin, not
     *  a seeder, not a stray ->delete() in a future feature."
     *
     * TRUE, and the sweep found the defect underneath it. The guard is a
     * `deleting` model hook returning false, which cancels the delete
     * SILENTLY — and PagePolicy said yes, so the panel drew an enabled Delete
     * button, the owner clicked it, Filament reported success, and the page
     * was still there with no explanation.
     *
     * The claim is right; what was missing is anybody being told. The policy
     * now refuses with a reason and the panel surfaces it.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_a_locked_page_cannot_be_deleted_by_any_path(): void
    {
        $page = Page::factory()->create(['is_locked' => true]);

        // 1. A stray ->delete().
        $page->delete();
        $this->assertFalse($page->fresh()->trashed(), 'a locked page was soft-deleted');

        // 2. A force delete.
        $page->forceDelete();
        $this->assertNotNull(Page::withTrashed()->find($page->getKey()), 'a locked page was force-deleted');

        // 3. A mass delete, which does not fire model events at all.
        Page::query()->whereKey($page->getKey())->delete();
        $this->assertNotNull(
            Page::withTrashed()->find($page->getKey()),
            'a locked page was removed by a query-builder delete'
        );
    }

    /** And the owner is TOLD, rather than watching a success message do nothing. */
    public function test_a_locked_page_refuses_visibly_in_the_panel(): void
    {
        Filament::setCurrentPanel('studio');
        $this->actingAs($this->owner());

        $page = Page::factory()->create(['is_locked' => true]);

        $response = Gate::inspect('delete', $page);

        $this->assertTrue($response->denied(), 'the policy allows deleting a locked page');
        $this->assertStringContainsString('locked', (string) $response->message());

        $action = TestAction::make('delete')->table($page);

        Livewire::test(\App\Filament\Resources\Pages\Pages\ListPages::class)
            ->assertActionVisible($action)
            ->callAction($action)
            ->assertNotified((string) $response->message());

        $this->assertFalse($page->fresh()->trashed());
    }

    /** An unlocked page still deletes, or the guard is just a wall. */
    public function test_an_unlocked_page_still_deletes(): void
    {
        Filament::setCurrentPanel('studio');
        $this->actingAs($this->owner());

        $page = Page::factory()->create(['is_locked' => false]);

        Livewire::test(\App\Filament\Resources\Pages\Pages\ListPages::class)
            ->callAction(TestAction::make('delete')->table($page));

        $this->assertTrue($page->fresh()->trashed());
    }

    // ----------------------------------------------------------------- 4

    /**
     * CLAIM — Models/Article.php:73, Project.php:74, Service.php:71
     * "Built from the route so it cannot drift from where the page actually
     *  lives."
     *
     * TRUE — each publicPath() calls route(). Unchecked: a hand-written
     * string would pass every existing test, and the redirect rows written on
     * a slug change would point at an address that does not exist.
     */
    public function test_every_public_path_comes_from_the_router(): void
    {
        $cases = [
            \App\Models\Article::class => ['journal.show', 'a-piece'],
            \App\Models\Project::class => ['projects.show', 'a-project'],
            \App\Models\Service::class => ['services.show', 'a-service'],
        ];

        foreach ($cases as $model => [$route, $slug]) {
            $expected = (string) parse_url(route($route, $slug), PHP_URL_PATH);

            $this->assertSame(
                $expected,
                (new $model)->publicPath($slug),
                class_basename($model).'::publicPath has drifted from its route'
            );
        }
    }

    // ----------------------------------------------------------------- 5

    /**
     * CLAIM — EnsureUserIsAdmin.php:15 and User.php:89
     * "A user whose role column holds anything unrecognised fails closed."
     *
     * TRUE. Unchecked at the PANEL boundary, which is new since the comment
     * was written.
     */
    public function test_an_unrecognised_role_fails_closed_everywhere(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);

        DB::table('users')->where('id', $user->getKey())->update(['role' => 'subscriber']);

        $user = $user->fresh();

        $this->assertFalse(
            $user->canAccessPanel(Filament::getPanel('studio')),
            'an unrecognised role can open the panel'
        );

        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->actingAs($user)->get('/legacy/media')->assertForbidden();
    }

    // ----------------------------------------------------------------- 6

    /**
     * CLAIM — Support/RichText.php:41/67
     * "never trust stored HTML holds by construction rather than by
     *  discipline", and the handler rule "includes every on* handler by
     *  construction rather than by blocklist".
     *
     * TRUE. Unchecked anywhere directly — the nearest test asserts a rendered
     * page ships no executable JavaScript, which would also pass if the
     * sanitiser were bypassed by a template that did not use it.
     */
    public function test_the_sanitiser_strips_every_script_vector(): void
    {
        /*
         * MARKDOWN, NOT RAW HTML, because that is what the column holds and
         * what the sanitiser is given.
         *
         * The first version of this test fed a single line of bare tags and
         * got null back — Str::markdown runs with html_input => 'strip', so a
         * body that is ONLY html reduces to nothing and toHtml() returns null
         * for an empty result. That is the sanitiser being stricter than the
         * test expected, which is the right direction and still a test that
         * proved nothing. These are vectors embedded in prose.
         */
        $cases = [
            'a script tag' => ["Prose with <script>alert(1)</script> inside.", '<script'],
            'a javascript: link' => ['[a link](javascript:alert(2)) in prose.', 'javascript:'],
            'mixed case' => ['[mixed](JaVaScRiPt:alert(3)) case.', 'avaScRiPt:'],
            'a data: uri' => ['[data](data:text/html;base64,PHNjcmlwdD4=) link.', 'data:text/html'],
            'an inline handler' => ["<div onclick=\"alert(5)\">x</div>\n\nProse after.", 'onclick'],
            'an svg handler' => ["<svg onload=\"alert(6)\"></svg>\n\nProse after.", 'onload'],
            'an iframe' => ["<iframe src=\"https://example.com\"></iframe>\n\nProse after.", '<iframe'],
            'an object' => ["<object data=\"x\"></object>\n\nProse after.", '<object'],
        ];

        foreach ($cases as $name => [$markdown, $vector]) {
            $clean = (string) \App\Support\RichText::toHtml($markdown);

            $this->assertStringNotContainsString(
                $vector,
                $clean,
                "the sanitiser let $name through: {$clean}"
            );
        }

        /*
         * NO ATTRIBUTE SURVIVES THAT IS NOT ON THE ALLOWLIST — the claim is
         * "every on* handler by construction rather than by blocklist", so
         * this looks for the SHAPE rather than for named handlers.
         */
        $clean = (string) \App\Support\RichText::toHtml(
            "A [link](https://example.com) and **bold**.\n\n".
            "<p class=\"x\" onmouseenter=\"a()\" data-evil=\"1\" style=\"x\">styled</p>"
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\son[a-z]+\s*=/i',
            $clean,
            "an event handler attribute survived: {$clean}"
        );

        $this->assertStringNotContainsString('style=', $clean);
        $this->assertStringNotContainsString('data-evil', $clean);

        // And it is not simply deleting everything.
        $this->assertStringContainsString('<a href="https://example.com"', $clean);
        $this->assertStringContainsString('<strong>bold</strong>', $clean);
        $this->assertStringContainsString('<p>', \App\Support\RichText::toHtml('A paragraph.'));
    }

    // ----------------------------------------------------------------- 7


    // ----------------------------------------------------------------- 12

    /**
     * CLAIM — Support/TranslatableSchema.php:148 (generatedColumns)
     * "Indexed columns get one per locale because a list view sorts in the
     *  locale the reader is in."
     *
     * And — TranslatableSchema.php:94 (INDEXED)
     * "Admin list views sort by title/name and search it."
     *
     * And — Models/Concerns/Translatable.php:330 (localeColumn)
     * "This is what makes ordering, filtering and searching work without
     *  touching JSON at query time."
     *
     * ─────────────────────────────────────────────────────────────────────
     * FALSE WHEN WRITTEN, FALSE FOR FOUR TASKS, AND THE MOST EXPENSIVE OF
     * THE THREE BECAUSE IT WAS PAID FOR.
     *
     * Sixteen STORED generated columns and sixteen indexes were created for
     * a purpose, documented in three places, that NOTHING EVER USED. Every
     * list view in the panel sorted `ORDER BY title` — the JSON document —
     * and searched `WHERE title LIKE '%x%'` against the same blob. Measured
     * on three projects titled Apple, Mango and Zephyr:
     *
     *     locale en, sorted asc:  Mango | Apple | Zephyr
     *     locale ar, sorted asc:  ميم | ياء | ألف
     *
     * Alphabetical in neither language, on every title and name column in
     * the panel, while an index built to do it correctly sat unread.
     *
     * The mechanism that should have caught it is TranslatableBuilder, which
     * rewrites a bare translatable column onto its generated column and
     * argues in its own header that a silent failure mode demands a
     * mechanism that cannot be forgotten. Filament forgets it anyway:
     * Filament\Support\apply_search_constraint() ends in
     * `$query->whereRaw("{$column} like ?")`, and whereRaw is not where.
     * applySort has no translatable branch at all.
     *
     * So this test does not assert that the columns exist — an earlier test
     * does that. It asserts they are USED, which is the claim that was
     * false: that ordering a list view touches the indexed column and not
     * the JSON.
     * ─────────────────────────────────────────────────────────────────────
     */
    public function test_a_list_view_sorts_on_the_indexed_column_and_not_the_json(): void
    {
        $this->actingAs($this->owner());
        Filament::setCurrentPanel('studio');

        $sql = Livewire::test(\App\Filament\Resources\Projects\Pages\ListProjects::class)
            ->set('tableSort', 'title:asc')
            ->instance()
            ->getFilteredSortedTableQuery()
            ->toRawSql();

        $this->assertStringContainsString(
            'title_en',
            $sql,
            'the list view is ordering by the JSON column again; the generated columns are back to being dead weight',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/order by\s+`?projects`?\.?`?title`?[\s,]/i',
            $sql,
            'ORDER BY title orders by the serialised JSON, which is alphabetical in no language',
        );
    }

    /**
     * Every locale of every indexed column is reachable by the search box.
     *
     * The generated columns exist per locale. A search that only ever looked
     * at the active one would make a bilingual archive findable in half of
     * itself, which is the failure this project keeps finding in other
     * shapes — so it is pinned here rather than left to each resource.
     */
    public function test_the_search_box_reaches_every_locale_of_an_indexed_column(): void
    {
        $this->actingAs($this->owner());
        Filament::setCurrentPanel('studio');

        $sql = Livewire::test(\App\Filament\Resources\Projects\Pages\ListProjects::class)
            ->set('tableSearch', 'anything')
            ->instance()
            ->getFilteredTableQuery()
            ->toRawSql();

        foreach (\App\Support\Locale::codes() as $locale) {
            $this->assertStringContainsString(
                'title_'.$locale,
                $sql,
                "a search does not reach the $locale title",
            );
        }
    }

    /**
     * CLAIM — TranslatableBuilder.php:44
     * "Closures. where(fn ($q) => …) nests, and the nested builder is this
     *  same class, so the inner conditions are rewritten when they are added
     *  rather than being guessed at here."
     *
     * TRUE, and worth pinning, because the sentence is load-bearing: the
     * whole argument for rewriting in the builder rather than at fifteen call
     * sites rests on a nested closure being covered. If a Laravel upgrade
     * ever nests with a plain Query\Builder instead, every translatable
     * condition written inside a closure silently compares a bare value to a
     * JSON document and matches nothing — which is the exact failure that
     * created duplicate articles on the second seeder run.
     */
    public function test_a_condition_inside_a_closure_is_still_localised(): void
    {
        $sql = \App\Models\Project::query()
            ->where(fn ($query) => $query->where('slug', 'salt-line')->orWhere('slug', 'other'))
            ->toRawSql();

        $this->assertStringContainsString('slug_en', $sql);
        $this->assertStringNotContainsString("`slug` =", $sql);
    }

    /**
     * CLAIM — Support/PageLinks.php:81
     * "Looping the closed set is the only version of this that cannot leave a
     *  stale entry behind."
     *
     * TRUE. Unchecked: a cache key added for a third locale, or a key
     * renamed, would leave the footer showing a deleted legal page in one
     * language only — which is the hardest kind of bug to see.
     */
    public function test_forgetting_the_legal_links_clears_every_locale(): void
    {
        $keys = [];

        foreach (\App\Support\Locale::codes() as $locale) {
            $key = \App\Support\PageLinks::CACHE_KEY.'.'.$locale;
            Cache::put($key, ['stale'], 60);
            $keys[] = $key;
        }

        Cache::put(\App\Support\PageLinks::CACHE_KEY, ['stale'], 60);
        $keys[] = \App\Support\PageLinks::CACHE_KEY;

        \App\Support\PageLinks::forget();

        foreach ($keys as $key) {
            $this->assertFalse(Cache::has($key), "$key survived PageLinks::forget()");
        }
    }

    // ----------------------------------------------------------------- 8

    /**
     * CLAIM — Filament/Support/Translatable.php:35
     * "There is no code path through these helpers that produces a partial
     *  map."
     *
     * TRUE and well tested for the editing paths. This asserts the PROPERTY
     * of the helper itself, which is what the sentence actually claims.
     */
    public function test_the_translatable_helper_cannot_emit_a_partial_map(): void
    {
        $locales = \App\Support\Locale::codes();

        foreach ([
            ['title' => 'only english'],
            ['title' => ['en' => 'one locale']],
            ['title' => []],
            [],
        ] as $input) {
            $out = \App\Filament\Support\Translatable::save(['title'], $input);

            if (! array_key_exists('title', $out)) {
                // An absent key is left absent — nothing to make partial.
                continue;
            }

            foreach ($locales as $locale) {
                $this->assertArrayHasKey(
                    $locale,
                    $out['title'],
                    'save() emitted a map missing '.$locale.' for '.json_encode($input)
                );
            }
        }
    }
}
