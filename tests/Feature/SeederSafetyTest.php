<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PublishStatus;
use App\Models\Article;
use App\Models\Category;
use App\Models\Client;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoArabicSeeder;
use Database\Seeders\DemoContentSeeder;
use Database\Seeders\DemoJournalSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A seeder may create, and may refresh what it demonstrably owns, and may
 * never overwrite anything else.
 *
 * WHY THIS FILE EXISTS
 *
 * SettingsSeeder's docblock promised that re-running "never overwrites
 * something the client has since reworded" while its code used
 * updateOrCreate, which overwrites every value on every run. It was found the
 * way these things always are — it silently reverted a real edit, and somebody
 * happened to notice.
 *
 * A comment cannot enforce that. These tests can.
 */
class SeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tables whose rows a client can edit through the admin.
     *
     * If a seeder is ever pointed at one of these with updateOrCreate, the
     * static test below fails and names the file.
     *
     * @var list<string>
     */
    private const CLIENT_EDITABLE = [
        'settings', 'projects', 'categories', 'clients', 'media',
        'articles', 'article_categories',
    ];

    /**
     * The models those tables belong to, as written in seeder source.
     *
     * @var list<string>
     */
    private const CLIENT_EDITABLE_MODELS = [
        'Setting', 'Project', 'Category', 'Client', 'Media',
        'Article', 'ArticleCategory',
    ];

    // ----------------------------------------------------------------- 6

    /**
     * STATIC ANALYSIS, deliberately.
     *
     * A behavioural test can only catch the seeders it thinks to run. This
     * reads every seeder in the project, so a new one written next month is
     * covered the moment it lands, before anybody notices the content it
     * quietly reverted.
     */
    public function test_no_seeder_uses_update_or_create_on_client_editable_content(): void
    {
        $offences = [];

        foreach ($this->seederFiles() as $path) {
            $source = $this->withoutCommentsOrStrings((string) file_get_contents($path));

            foreach (self::CLIENT_EDITABLE_MODELS as $model) {
                // Model::updateOrCreate(...) and $model->updateOrCreate(...)
                $patterns = [
                    '/\b'.$model.'::(?:query\(\)->)?updateOrCreate\s*\(/',
                    '/\b'.$model.'::(?:query\(\)->)?upsert\s*\(/',
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $source)) {
                        $offences[] = basename($path).' calls updateOrCreate/upsert on '.$model;
                    }
                }
            }

            // Table-level writes bypass the model entirely.
            foreach (self::CLIENT_EDITABLE as $table) {
                if (preg_match('/DB::table\([\'"]'.$table.'[\'"]\)->(?:updateOrInsert|upsert|update)\s*\(/', $source)) {
                    $offences[] = basename($path).' writes directly to the '.$table.' table';
                }
            }
        }

        $this->assertSame(
            [],
            $offences,
            "A seeder can overwrite content a client has edited:\n  ".implode("\n  ", $offences)
                ."\n\nUse Database\\Seeders\\Concerns\\ProtectsEditedContent instead."
        );
    }

    // --- and the behaviour the static check is standing in for ------------

    /**
     * Re-seeding settings keeps every value a client has changed.
     */
    public function test_reseeding_settings_never_reverts_an_edit(): void
    {
        $this->seed(SettingsSeeder::class);

        Settings::flush();
        Settings::set('site.name', 'Marsden & Co', 'string', 'identity');
        Settings::set('about.portrait_media_id', '42', 'string', 'about');
        Settings::flush();

        $this->seed(SettingsSeeder::class);
        Settings::flush();

        $this->assertSame('Marsden & Co', Settings::get('site.name'));
        $this->assertSame('42', Settings::get('about.portrait_media_id'));

        // And a key that did not exist is still created, or the seeder is
        // useless rather than merely safe.
        Setting::query()->where('key', 'journal.heading')->delete();
        Settings::flush();

        $this->seed(SettingsSeeder::class);
        Settings::flush();

        $this->assertNotNull(Settings::get('journal.heading'));
    }

    /**
     * Re-seeding the journal leaves an edited piece alone, and does not
     * resurrect one that was binned.
     */
    public function test_reseeding_the_journal_respects_edits_and_deletions(): void
    {
        Storage::fake('media_originals');
        Storage::fake('media_public', config('filesystems.disks.media_public'));

        $this->seed(DemoJournalSeeder::class);

        $edited = Article::query()->where('slug', 'one-camera')->sole();
        $edited->forceFill([
            'title' => 'A title the studio wrote',
            'is_demo' => false,   // taken over: no longer the seeder's
        ])->save();

        $binned = Article::query()->where('slug', 'available-dark')->sole();
        $binned->delete();

        $untouched = Article::query()->where('slug', 'the-edit-is-the-work')->sole();

        $this->seed(DemoJournalSeeder::class);

        // The studio's title survives.
        $this->assertSame('A title the studio wrote', $edited->fresh()->title);

        // The binned piece stays binned.
        $this->assertNull(Article::query()->find($binned->id));
        $this->assertNotNull(Article::withTrashed()->find($binned->id));

        // And a demo piece nobody touched is still refreshed, so the seeder
        // is still doing its job.
        $this->assertSame($untouched->title, $untouched->fresh()->title);
        $this->assertTrue($untouched->fresh()->is_demo);
    }

    /**
     * The same guarantee for the two tables the demo content seeder touches
     * that have no ownership flag at all.
     */
    public function test_a_renamed_category_or_client_survives_reseeding(): void
    {
        $category = new Category(['name' => 'Documentary', 'description' => 'Long-form reportage.']);
        $category->slug = 'documentary';
        $category->save();

        $category->update(['name' => 'Reportage', 'description' => 'What the studio calls it now.']);

        $client = Client::query()->create([
            'name' => 'Meridian Press',
            'url' => 'https://meridian.example',
            'sort_order' => 3,
            'is_active' => false,
        ]);

        $this->runDemoContentCategoriesAndClients();

        $this->assertSame('Reportage', $category->fresh()->name);
        $this->assertSame('What the studio calls it now.', $category->fresh()->description);

        $this->assertSame('https://meridian.example', $client->fresh()->url);
        $this->assertFalse($client->fresh()->is_active, 'a client the studio hid was made active again');
        $this->assertSame(3, $client->fresh()->sort_order);
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * TASK 38 0.3 — THE ONE SEEDER PASS THAT OVERWRITES, AND ITS GUARD.
     *
     * DemoArabicSeeder::projectClients() replaces an Arabic value that is
     * already there, which nothing else in any seeder does. It exists because
     * 0.3 transliterated clients.name while Task 36b had filled
     * projects.client with the Latin name in a bidi isolate, and the same
     * brand rendering two ways on one page is one decision made twice rather
     * than two defensible choices.
     *
     * THE STATIC SCAN ABOVE CANNOT SEE IT. That scan looks for
     * updateOrCreate, upsert and DB::table()->update(); this pass is
     * setTranslation() followed by save(), which is the shape the scan misses
     * and is noted in Part 0's report as a gap. So the guarantee is asserted
     * behaviourally here instead of being trusted.
     *
     * THE GUARANTEE: it replaces EXACTLY the string 36b generated — the
     * English name between U+2068 and U+2069 — and nothing else. An owner who
     * has typed their own Arabic keeps it through any number of re-runs.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_reseeding_replaces_the_generated_client_credit_but_never_an_owners(): void
    {
        /*
         * BOTH STATES ARE CONSTRUCTED HERE rather than seeded, and the first
         * draft of this test did seed them and asserted nothing as a result:
         * the generated credit only ever exists BETWEEN the two passes of
         * DemoArabicSeeder, so by the time the seeder returns there is none
         * left to find and the fixture search came back null. Building them
         * also lets the owner's value be a DIFFERENT transliteration of a
         * name the seeder knows, so "left alone" cannot be mistaken for
         * "happened to agree".
         */
        $generated = new Project(['title' => 'A credited commission']);
        $generated->slug = 'a-credited-commission';
        // A demo row: the pass refuses anything else, which is ProtectsEditedContent
        // doing its job and was the first reason this test failed.
        $generated->is_demo = true;
        $generated->setTranslation('client', 'en', 'Nord & Vale');
        $generated->setTranslation('client', 'ar', "\u{2068}Nord & Vale\u{2069}");
        $generated->save();

        $owned = new Project(['title' => 'Another credited commission']);
        $owned->slug = 'another-credited-commission';
        $owned->is_demo = true;
        $owned->setTranslation('client', 'en', 'Meridian Press');
        $owned->setTranslation('client', 'ar', 'مِريديان الصحفيّة');
        $owned->save();

        $this->seed(DemoArabicSeeder::class);

        $after = $generated->fresh()->getTranslation('client', 'ar', fallback: false);

        $this->assertStringNotContainsString(
            "\u{2068}",
            (string) $after,
            'The generated Latin credit was not replaced.'
        );
        $this->assertMatchesRegularExpression(
            '/^\p{Arabic}/u',
            (string) $after,
            'The replacement is not in Arabic script.'
        );

        $this->assertSame(
            'مِريديان الصحفيّة',
            $owned->fresh()->getTranslation('client', 'ar', fallback: false),
            "The seeder overwrote an owner's own transliteration."
        );

        // And a second run changes neither.
        $this->seed(DemoArabicSeeder::class);

        $this->assertSame($after, $generated->fresh()->getTranslation('client', 'ar', fallback: false));
        $this->assertSame('مِريديان الصحفيّة', $owned->fresh()->getTranslation('client', 'ar', fallback: false));
    }

    /**
     * And the credit matches the client list, which is the whole reason that
     * pass exists: the same brand rendered two ways on one page.
     */
    public function test_a_clients_name_reads_the_same_in_both_places(): void
    {
        $client = Client::query()->create([
            'name' => 'Nord & Vale',
            'url' => 'https://nordvale.example',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        // is_demo is not mass assignable — set after, or the pass refuses it.
        $client->is_demo = true;
        $client->save();

        $project = new Project(['title' => 'A credited commission']);
        $project->slug = 'a-credited-commission';
        $project->is_demo = true;
        $project->setTranslation('client', 'en', 'Nord & Vale');
        $project->setTranslation('client', 'ar', "\u{2068}Nord & Vale\u{2069}");
        $project->save();

        $this->seed(DemoArabicSeeder::class);

        $inTheList = $client->fresh()->getTranslation('name', 'ar', fallback: false);
        $inTheCredit = $project->fresh()->getTranslation('client', 'ar', fallback: false);

        $this->assertNotNull($inTheList, 'The client list was not transliterated at all.');
        $this->assertSame(
            $inTheList,
            $inTheCredit,
            'Nord & Vale is written one way in the client list and another in its project credit.'
        );
    }

    /**
     * A project the studio published must not be forced back to draft.
     */
    public function test_reseeding_cannot_unpublish_a_project(): void
    {
        $project = new Project(['title' => 'Work in Progress']);
        $project->slug = 'work-in-progress';
        $project->status = PublishStatus::Published;
        $project->published_at = now()->subMonth();
        $project->save();

        $this->runDemoContentCategoriesAndClients();

        $this->assertSame(PublishStatus::Published, $project->fresh()->status);
        $this->assertNotNull($project->fresh()->published_at);
    }

    /**
     * The default seeder must not put a known account into a real database.
     *
     * It created test@example.com with the factory's password — the literal
     * string "password" — with nothing stopping `php artisan db:seed` running
     * on staging or production.
     */
    public function test_the_default_seeder_creates_no_account_outside_local(): void
    {
        /*
         * The seeder is run DIRECTLY rather than through $this->seed().
         *
         * `db:seed` carries Laravel's own ConfirmableTrait, so in a production
         * environment it stops and asks — which made the first version of this
         * test assert the framework's prompt rather than this seeder's guard,
         * and it blew up on a mocked console rather than passing or failing
         * honestly. `--force` skips that prompt, and nothing stops
         * DatabaseSeeder::run() being called from code, so the guard has to be
         * in the seeder and has to be tested there.
         */
        foreach (['production', 'staging'] as $environment) {
            $this->app->detectEnvironment(fn (): string => $environment);

            (new DatabaseSeeder)->run();

            $this->assertSame(
                0,
                User::query()->count(),
                "DatabaseSeeder created an account in {$environment}"
            );
        }

        // And locally it still does its job, idempotently.
        $this->app->detectEnvironment(fn (): string => 'local');

        (new DatabaseSeeder)->run();
        (new DatabaseSeeder)->run();

        $this->assertSame(1, User::query()->where('email', 'test@example.com')->count());
    }

    /**
     * Runs only the part of DemoContentSeeder that does not need a processed
     * media library, by seeding it and tolerating the early return it makes
     * when there are no images.
     */
    private function runDemoContentCategoriesAndClients(): void
    {
        Storage::fake('media_originals');
        Storage::fake('media_public', config('filesystems.disks.media_public'));

        $this->seed(DemoContentSeeder::class);
    }

    /**
     * @return list<string>
     */
    private function seederFiles(): array
    {
        $files = glob(database_path('seeders/*.php')) ?: [];
        $nested = glob(database_path('seeders/*/*.php')) ?: [];

        $all = array_merge($files, $nested);

        $this->assertNotEmpty($all, 'no seeders were found — this test would pass vacuously');

        return $all;
    }

    /**
     * Strip comments and string literals before pattern matching.
     *
     * Without this the test reads its own explanatory comments — and the
     * comment in SettingsSeeder that says "updateOrCreate was wrong here" —
     * as offences, and fails on prose describing the bug it is guarding
     * against.
     */
    private function withoutCommentsOrStrings(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML], true)) {
                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }
}
