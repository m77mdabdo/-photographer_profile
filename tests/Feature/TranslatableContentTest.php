<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Projects\SaveStory;
use App\Enums\BlockType;
use App\Enums\UserRole;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Livewire\Studio\StoryBuilder;
use App\Models\Article;
use App\Models\Category;
use App\Models\Page;
use App\Models\Project;
use App\Models\ProjectBlock;
use App\Models\Redirect;
use App\Models\Service;
use App\Models\User;
use App\Support\Locale;
use App\Support\Settings;
use App\Support\SlugRedirects;
use App\Support\TranslatableSchema;
use App\Support\TranslationBackfill;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\DemoWorld;
use Tests\TestCase;

/**
 * Step 3: translatable content.
 *
 * The migration that gets us here is the one that cannot be cheaply undone, so
 * these assert the guarantees that make it safe rather than merely the
 * features that make it useful.
 */
class TranslatableContentTest extends TestCase
{
    use RefreshDatabase;

    // ----------------------------------------------------------------- 1

    /**
     * EVERY VALUE LANDED IN `en`, NOTHING WAS LOST, NOTHING WAS TRUNCATED.
     *
     * Asserted by counting rather than by reading, because the failure this
     * guards against is one row out of hundreds, and nobody finds that by
     * looking.
     */
    public function test_the_backfill_moves_every_value_with_no_nulls_or_truncation(): void
    {
        $project = Project::factory()->published()->create([
            'title' => 'Salt Line',
            // 255 characters: the old varchar's exact limit, so a JSON wrapper
            // added before the column was widened would have cut the tail off.
            'excerpt' => str_repeat('x', 255),
        ]);

        $before = [
            'title' => $project->getTranslation('title', 'en'),
            'excerpt' => $project->getTranslation('excerpt', 'en'),
        ];

        $this->assertSame(255, mb_strlen((string) $before['excerpt']), 'the long value survived the write');

        // Re-running the backfill must change nothing: it is idempotent.
        $moved = (new TranslationBackfill)->run();

        $project->refresh();

        $this->assertSame(0, $moved, 'a second backfill should find nothing to move');
        $this->assertSame($before['title'], $project->getTranslation('title', 'en'));
        $this->assertSame($before['excerpt'], $project->getTranslation('excerpt', 'en'));

        // And every translatable cell in the database is either null or a
        // locale-keyed object. No half-converted rows.
        $malformed = 0;

        foreach (TranslatableSchema::all() as $table => $columns) {
            foreach ($columns as $column) {
                foreach (DB::table($table)->select(['id', $column])->get() as $row) {
                    $value = $row->{$column};

                    if ($value === null) {
                        continue;
                    }

                    $decoded = json_decode((string) $value, true);

                    if (! is_array($decoded)) {
                        $malformed++;

                        continue;
                    }

                    foreach (array_keys($decoded) as $key) {
                        if (! Locale::supports((string) $key)) {
                            $malformed++;
                        }
                    }
                }
            }
        }

        $this->assertSame(0, $malformed, 'every translatable cell is null or a locale map');
    }

    // ----------------------------------------------------------------- 2

    /**
     * A NULL SLUG IN ANY LOCALE IS IMPOSSIBLE AT THE DATABASE LEVEL.
     *
     * Raw SQL, bypassing every model, every cast and every validation rule —
     * because the guarantee is worthless if it only holds for writes that go
     * through the application. A unique index permits unlimited nulls, so a
     * nullable slug in a locale would mean no uniqueness in that locale at
     * all.
     */
    public function test_a_null_slug_in_any_locale_is_rejected_by_the_database(): void
    {
        foreach (array_keys(TranslatableSchema::slugs()) as $table) {
            foreach (Locale::codes() as $missing) {
                $slug = [];

                foreach (Locale::codes() as $locale) {
                    if ($locale !== $missing) {
                        $slug[$locale] = 'only-'.$locale.'-'.$table;
                    }
                }

                try {
                    DB::table($table)->insert([
                        'slug' => json_encode($slug),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $this->fail("$table accepted a slug with no '$missing' value");
                } catch (QueryException $e) {
                    $this->assertStringContainsString(
                        'cannot be null',
                        $e->getMessage(),
                        "$table should refuse a slug missing '$missing' because the generated column is NOT NULL"
                    );
                }
            }
        }
    }

    // ----------------------------------------------------------------- 3

    /** Duplicates within a locale are refused; across locales they are fine. */
    public function test_slug_uniqueness_is_per_locale(): void
    {
        $first = Category::factory()->create();
        $first->setTranslations('slug', ['en' => 'architecture', 'ar' => 'عمارة']);
        $first->save();

        // The same slug in the OTHER locale is allowed, and has to be: an
        // untranslated record's Arabic slug is its English one.
        $second = Category::factory()->create();
        $second->setTranslations('slug', ['en' => 'buildings', 'ar' => 'architecture']);
        $second->save();

        $this->assertDatabaseCount('categories', 2);

        // The same slug in the SAME locale is not.
        $third = Category::factory()->make();
        $third->setTranslations('slug', ['en' => 'architecture', 'ar' => 'something-else']);

        $this->expectException(UniqueConstraintViolationException::class);

        $third->save();
    }

    // ----------------------------------------------------------------- 4

    /** A missing Arabic value falls back, and the admin still says it is missing. */
    public function test_a_missing_value_falls_back_but_the_admin_shows_it_as_untranslated(): void
    {
        $project = Project::factory()->published()->create(['title' => 'Salt Line']);
        $project->setTranslation('title', 'ar', 'خط الملح');
        $project->save();

        App::setLocale('ar');

        // Translated: the Arabic value.
        $this->assertSame('خط الملح', $project->title);

        // Untranslated: the English one, not a blank page.
        $this->assertSame(
            $project->getTranslation('subtitle', 'en'),
            $project->subtitle,
            'an untranslated field falls back to the default locale'
        );

        // But the truth is still available, and it is what the admin asks.
        $this->assertTrue($project->isTranslated('title', 'ar'));
        $this->assertFalse($project->isTranslated('subtitle', 'ar'));
        $this->assertContains('subtitle', $project->untranslatedAttributes('ar'));
        $this->assertNotContains('title', $project->untranslatedAttributes('ar'));

        App::setLocale('en');

        // And the admin list renders that state rather than hiding it.
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        Filament::setCurrentPanel('studio');

        $html = Livewire::actingAs($owner)
            ->test(ListProjects::class)
            ->html();

        /*
         * The panel's own way of saying it: Translatable::statusColumn marks
         * each row with the locales it is missing, which is the same fact the
         * custom admin printed as the word "untranslated".
         */
        $this->assertStringContainsString(__('studio.table.translated'), $html);
    }

    // ----------------------------------------------------------------- 5

    /** Block captions survived the data migration, compared before and after. */
    public function test_block_text_is_per_locale_and_survived_the_migration(): void
    {
        $project = Project::factory()->published()->create();

        $captions = [
            'First light, low water.',
            'November, then February.',
            'Three mornings, one week apart.',
        ];

        foreach ($captions as $index => $caption) {
            ProjectBlock::create([
                'project_id' => $project->id,
                'type' => BlockType::FullWidthImage->value,
                'sort_order' => $index,
                'data' => ['caption' => $caption],
            ]);
        }

        // Convert, exactly as the migration does.
        [$blocks, $values] = (new TranslationBackfill)->runBlocks();

        $this->assertSame(3, $blocks);
        $this->assertSame(3, $values);

        // Every caption is intact, and now addressable per locale.
        foreach ($project->blocks()->orderBy('sort_order')->get() as $index => $block) {
            $this->assertSame($captions[$index], $block->rawData()['caption']['en']);
            $this->assertSame($captions[$index], $block->data['caption'], 'the accessor resolves to a string');
        }

        // A key with no language is untouched, byte for byte.
        $spacer = ProjectBlock::create([
            'project_id' => $project->id,
            'type' => BlockType::Spacer->value,
            'sort_order' => 9,
            'data' => ['height' => 120],
        ]);

        (new TranslationBackfill)->runBlocks();

        $this->assertSame(['height' => 120], $spacer->fresh()->rawData());
    }

    /** An English save must never destroy the Arabic text. */
    public function test_saving_one_locale_preserves_the_other(): void
    {
        $project = Project::factory()->published()->create();

        $block = ProjectBlock::create([
            'project_id' => $project->id,
            'type' => BlockType::FullWidthImage->value,
            'sort_order' => 0,
            'data' => ['caption' => ['en' => 'English caption', 'ar' => 'تعليق عربي']],
        ]);

        app(SaveStory::class)($project, [[
            'id' => $block->id,
            'key' => 'b'.$block->id,
            'type' => BlockType::FullWidthImage->value,
            'data' => ['caption' => 'Changed in English'],
            'media' => [],
        ]], 'en');

        $raw = $block->fresh()->rawData();

        $this->assertSame('Changed in English', $raw['caption']['en']);
        $this->assertSame('تعليق عربي', $raw['caption']['ar'], 'the Arabic caption must survive an English save');
    }

    // ----------------------------------------------------------------- 6

    /** The migration created no redirect rows. */
    public function test_the_migration_created_no_redirects(): void
    {
        /*
         * The migration has already run by the time any test executes, so this
         * asserts the state it left: an empty table. The suppression itself is
         * asserted below, because an empty table could also mean the hook
         * simply never fired for another reason.
         */
        $this->assertSame(0, Redirect::query()->where('source', 'slug_change')->count());

        $project = Project::factory()->published()->create(['title' => 'Salt Line']);
        $project->forceFill(['slug' => 'original'])->save();

        Redirect::query()->delete();
        Redirect::forgetMap();

        // Inside the suppression, a rename records nothing.
        SlugRedirects::without(function () use ($project): void {
            $this->assertTrue(SlugRedirects::suppressed());

            $project->forceFill(['slug' => 'renamed-under-suppression'])->save();
        });

        $this->assertSame(0, Redirect::query()->count(), 'suppressed renames write nothing');

        // And it cannot be left on.
        $this->assertFalse(SlugRedirects::suppressed(), 'suppression must not outlive the callback');

        // Outside it, the hook is live again.
        $project->forceFill(['slug' => 'renamed-normally'])->save();

        $this->assertSame(1, Redirect::query()->count(), 'the hook is live after suppression ends');
    }

    /** Suppression is restored even when the callback throws. */
    public function test_suppression_survives_an_exception(): void
    {
        try {
            SlugRedirects::without(static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse(SlugRedirects::suppressed(), 'a throwing callback must still restore the counter');
    }

    // ----------------------------------------------------------------- 7

    /** Ordering, filtering and search work per locale, at the pinned counts. */
    public function test_ordering_and_search_work_per_locale(): void
    {
        $alpha = Project::factory()->published()->create(['title' => 'Alpha']);
        $omega = Project::factory()->published()->create(['title' => 'Omega']);

        $alpha->setTranslation('title', 'ar', 'ياء');   // last letter
        $alpha->save();
        $omega->setTranslation('title', 'ar', 'ألف');   // first letter
        $omega->save();

        App::setLocale('en');
        $this->assertSame(
            ['Alpha', 'Omega'],
            Project::query()->orderByTranslated('title')->pluck('title')->all()
        );

        App::setLocale('ar');
        $this->assertSame(
            ['ألف', 'ياء'],
            Project::query()->orderByTranslated('title')->pluck('title')->all(),
            'the Arabic order is not the English order'
        );

        // Search finds the active locale's value.
        $this->assertTrue(
            Project::query()->whereTranslatedLike('title', '%ياء%')->exists()
        );

        // And still finds a record by its English title while Arabic is active,
        // so a half-translated library stays searchable.
        $this->assertTrue(
            Project::query()->whereTranslatedLike('title', '%Alpha%')->exists()
        );

        App::setLocale('en');
    }

    /** The pinned per-page query counts did not move. */
    public function test_query_counts_are_unchanged(): void
    {
        $this->seedWorld();

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $measure = function (string $url) use (&$count): int {
            $this->get($url);

            $count = 0;
            $this->get($url)->assertOk();

            return $count;
        };

        /*
         * THE SAME NUMBERS AS BEFORE THE COLUMNS BECAME JSON.
         *
         * This is what the generated columns bought. A per-locale value read
         * from the row it is already on costs nothing extra; the alternative —
         * a translations table — would have added a join per locale to every
         * one of these.
         */
        // The home page rose 11 → 12 in Task 34 D2 for the testimonial strip.
        // 12 -> 13: the cinematic sections, one select whatever their number (lighting brief, 22 Oct 2026).
        $expected = ['/' => 13, '/journal' => 3, '/services' => 1, '/work' => 6];

        foreach ($expected as $path => $queries) {
            foreach (Locale::codes() as $locale) {
                $url = Locale::pathFor($locale, $path);

                $this->assertSame($queries, $measure($url), "$url query count moved");
            }
        }
    }

    // ----------------------------------------------------------------- 8

    /**
     * The panel saves both locales.
     *
     * This drove the custom admin's project form, whose Arabic fields lived
     * under `tr.ar.*`. The panel's live under `title.ar`, through the
     * translatable tabs — a different shape, the same guarantee, and the one
     * that matters: writing one language leaves the other alone.
     */
    public function test_the_panel_saves_both_locales(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'two_factor_confirmed_at' => now()]);
        $project = Project::factory()->published()->create(['title' => 'Salt Line']);

        Filament::setCurrentPanel('studio');

        Livewire::actingAs($owner)
            ->test(EditProject::class, ['record' => $project->getKey()])
            ->fillForm(['title.ar' => 'خط الملح', 'excerpt.ar' => 'نص عربي'])
            ->call('save')
            ->assertHasNoFormErrors();

        $project->refresh();

        $this->assertSame('Salt Line', $project->getTranslation('title', 'en'));
        $this->assertSame('خط الملح', $project->getTranslation('title', 'ar', fallback: false));
        $this->assertSame('نص عربي', $project->getTranslation('excerpt', 'ar', fallback: false));
    }

    /** The story builder edits one locale and leaves the other alone. */
    public function test_the_story_builder_edits_a_chosen_locale(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $project = Project::factory()->published()->create();

        /*
         * A text block, not an image one: ValidateBlock requires an image
         * block to have media attached, and this test is about locales rather
         * than about media.
         */
        $block = ProjectBlock::create([
            'project_id' => $project->id,
            'type' => BlockType::FullWidthText->value,
            'sort_order' => 0,
            'data' => ['body' => ['en' => 'English body']],
        ]);

        $component = Livewire::actingAs($owner)
            ->test(StoryBuilder::class, ['project' => $project])
            ->call('switchLocale', 'ar');

        $component->assertSet('editingLocale', 'ar');

        /*
         * The Arabic panel shows an EMPTY caption, not the English one. A
         * pre-filled field would be saved as a translation and the record
         * would claim to be translated when it is not.
         */
        $blocks = $component->get('blocks');

        $this->assertSame('', $blocks[0]['data']['body'] ?? null);

        $component->call('saveStory', [[
            'id' => $block->id,
            'key' => 'b'.$block->id,
            'type' => BlockType::FullWidthText->value,
            'data' => ['body' => 'نص عربي'],
            'media' => [],
        ]]);

        $raw = $block->fresh()->rawData();

        $this->assertSame('نص عربي', $raw['body']['ar']);
        $this->assertSame('English body', $raw['body']['en'], 'the English body survived');
    }

    // ----------------------------------------------------------------- 9

    /** An English slug under /ar redirects permanently to the Arabic one. */
    public function test_an_english_slug_under_the_arabic_prefix_redirects(): void
    {
        $project = Project::factory()->published()->create(['title' => 'Salt Line']);
        $project->setTranslations('slug', ['en' => 'salt-line', 'ar' => 'khat-almilh']);
        $project->save();

        // Each locale's own address serves the page.
        $this->get('/work/salt-line')->assertOk();
        $this->get('/ar/work/khat-almilh')->assertOk();

        // The other locale's slug is a permanent redirect, not a 404 — the
        // language switcher produces exactly these URLs.
        $this->get('/ar/work/salt-line')
            ->assertStatus(301)
            ->assertRedirect('/ar/work/khat-almilh');

        $this->get('/work/khat-almilh')
            ->assertStatus(301)
            ->assertRedirect('/work/salt-line');

        // A slug that belongs to nothing is still a 404.
        $this->get('/ar/work/no-such-project')->assertNotFound();
    }

    /** The correction keeps the query string. */
    public function test_the_canonical_redirect_preserves_the_query_string(): void
    {
        $project = Project::factory()->published()->create(['title' => 'Salt Line']);
        $project->setTranslations('slug', ['en' => 'salt-line', 'ar' => 'khat-almilh']);
        $project->save();

        $this->get('/ar/work/salt-line?utm_source=newsletter')
            ->assertRedirect('/ar/work/khat-almilh?utm_source=newsletter');
    }

    // ----------------------------------------------------------------- 10

    /** hreflang, canonicals and sitemaps use each locale's own slug. */
    public function test_seo_uses_per_locale_slugs(): void
    {
        $project = Project::factory()->published()->create(['title' => 'Salt Line']);
        $project->setTranslations('slug', ['en' => 'salt-line', 'ar' => 'khat-almilh']);
        $project->save();

        $english = $this->get('/work/salt-line')->assertOk()->getContent();

        // The canonical is this page, in this locale.
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/work/salt-line').'">', $english);

        // The alternate names the ARABIC slug, not the English one under /ar.
        $this->assertStringContainsString(
            '<link rel="alternate" hreflang="ar" href="'.url('/ar/work/khat-almilh').'">',
            $english,
            'the Arabic alternate must use the Arabic slug'
        );

        $arabic = $this->get('/ar/work/khat-almilh')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.url('/ar/work/khat-almilh').'">', $arabic);
        $this->assertStringContainsString(
            '<link rel="alternate" hreflang="en" href="'.url('/work/salt-line').'">',
            $arabic
        );

        // And the sitemap carries both real addresses.
        $xml = $this->get('/sitemap-projects.xml')->assertOk()->getContent();

        $this->assertStringContainsString(url('/work/salt-line'), $xml);
        $this->assertStringContainsString(url('/ar/work/khat-almilh'), $xml);
    }

    /** Settings fall back per locale too. */
    public function test_settings_fall_back_per_locale(): void
    {
        Settings::set('site.statement', 'An English statement');
        Settings::flush();

        App::setLocale('ar');

        $this->assertSame('An English statement', Settings::get('site.statement'), 'untranslated settings fall back');
        $this->assertFalse(Settings::isTranslated('site.statement', 'ar'));

        App::setLocale('en');
    }

    // ----------------------------------------------------------------- helpers

    private function seedWorld(): void
    {
        $world = new DemoWorld;
        $world->story('salt-line', DemoWorld::simpleScore(6), ['landscape'], year: 2025);

        Project::query()->first()?->forceFill(['is_featured' => true])->save();

        Article::factory()->count(3)->create();
        Service::factory()->count(3)->create();
        Page::factory()->create(['title' => 'Privacy', 'slug' => 'privacy']);
    }
}
