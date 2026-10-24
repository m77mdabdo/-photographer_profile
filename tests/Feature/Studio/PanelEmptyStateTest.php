<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * E2 — THE EMPTY SCREEN IS A SCREEN, AND IT IS TESTED LIKE ONE.
 *
 * Fourteen tables now say what will appear in them instead of "No Projects /
 * Create a Project to get started" under an ✗ in a circle. A claim like that
 * decays the moment somebody adds the fifteenth resource, so the matrix here
 * is DERIVED from the directory exactly as CrudPathsTest's is: a table that
 * ships Filament's default fails this file rather than quietly looking like
 * the software talking about its own tables.
 *
 * Writing it caught two things I had got wrong by hand, which is the only
 * reason it is worth its length:
 *
 *   · ArticleCategoryResource was reading the PROJECT category's strings —
 *     "The archive offers only the ones with published work in them" on the
 *     journal's sections screen. Two resources, one set of words, and no
 *     amount of reading the diff showed it to me.
 *   · MenuResource had no empty state at all. I had written "thirteen
 *     resources" in a commit message and there are fifteen.
 * ───────────────────────────────────────────────────────────────────────────
 */
class PanelEmptyStateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * UserResource is the one table with no bespoke empty state, and the
     * reason is not an oversight: the list cannot be empty. Reaching it means
     * being signed in, and the signed-in owner is a row in it. The test below
     * asserts that, so the exemption stays honest.
     */
    private const NO_EMPTY_STATE = ['UserResource'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('studio');

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]));
    }

    /** @return array<string, array{class-string<resource>}> */
    public static function resources(): array
    {
        $resources = [];

        foreach (glob(dirname(__DIR__, 3).'/app/Filament/Resources/*/*Resource.php') as $file) {
            $class = 'App\\Filament\\Resources\\'
                .basename(dirname($file)).'\\'
                .basename($file, '.php');

            $resources[class_basename($class)] = [$class];
        }

        ksort($resources);

        return $resources;
    }

    /**
     * @param  class-string<resource>  $resource
     */
    #[DataProvider('resources')]
    public function test_every_table_says_what_will_appear_in_it(string $resource): void
    {
        if (in_array(class_basename($resource), self::NO_EMPTY_STATE, true)) {
            $this->markTestSkipped(class_basename($resource).' cannot be empty; see the test below.');
        }

        $table = $this->tableFor($resource);
        $default = Table::make($this->instanceFor($resource));

        $heading = $table->getEmptyStateHeading();
        $body = $table->getEmptyStateDescription();

        $this->assertNotEmpty($heading, class_basename($resource).' ships Filament\'s derived heading.');
        $this->assertNotEmpty($body, class_basename($resource).' has no empty-state description.');

        /*
         * A missing key renders as the key. Filament would show
         * "studio.empty.project_heading" to the owner and no assertion on
         * "not empty" would notice.
         */
        $this->assertStringNotContainsString('studio.empty', (string) $heading);
        $this->assertStringNotContainsString('studio.empty', (string) $body);

        // Filament's default heading names the model's plural label.
        $this->assertNotSame(
            $default->getEmptyStateHeading(),
            $heading,
            class_basename($resource).' is still showing the generated heading.',
        );

        /*
         * And the icon. Filament's default is an ✗ in a circle, which is the
         * mark for something wrong — an empty list is not an error.
         */
        $icon = $table->getEmptyStateIcon();

        $this->assertNotNull($icon, class_basename($resource).' has no empty-state icon.');
        $this->assertNotSame($default->getEmptyStateIcon(), $icon);
        $this->assertStringNotContainsString('x-mark', $this->iconName($icon));
        $this->assertStringNotContainsString('x-circle', $this->iconName($icon));
    }

    /**
     * The words, not the wiring: no two tables may describe themselves with
     * the same sentence. This is what caught the journal's sections reading
     * the archive's categories.
     */
    public function test_no_two_tables_share_their_empty_state(): void
    {
        $seen = [];

        foreach (array_keys(self::resources()) as $name) {
            if (in_array($name, self::NO_EMPTY_STATE, true)) {
                continue;
            }

            [$resource] = self::resources()[$name];
            $table = $this->tableFor($resource);

            $seen[$name] = (string) $table->getEmptyStateHeading()
                .'|'.(string) $table->getEmptyStateDescription();
        }

        $this->assertSame(
            array_keys($seen),
            array_keys(array_unique($seen)),
            'Two tables share an empty state: '.implode(', ', array_keys(array_diff_key($seen, array_unique($seen)))),
        );
    }

    /**
     * Arabic. A string that was never translated falls back to English and
     * reads as a lapse in a panel that is otherwise whole, so each pair is
     * compared across the two locales rather than merely being present.
     */
    #[DataProvider('resources')]
    public function test_the_empty_state_is_translated(string $resource): void
    {
        if (in_array(class_basename($resource), self::NO_EMPTY_STATE, true)) {
            $this->markTestSkipped(class_basename($resource).' has no empty state by design.');
        }

        app()->setLocale('en');
        $english = $this->tableFor($resource);
        $englishHeading = (string) $english->getEmptyStateHeading();
        $englishBody = (string) $english->getEmptyStateDescription();

        app()->setLocale('ar');
        $arabic = $this->tableFor($resource);
        $arabicHeading = (string) $arabic->getEmptyStateHeading();
        $arabicBody = (string) $arabic->getEmptyStateDescription();

        $this->assertNotSame($englishHeading, $arabicHeading, class_basename($resource).' heading is not translated.');
        $this->assertNotSame($englishBody, $arabicBody, class_basename($resource).' description is not translated.');

        // Arabic script, not a transliteration or a leftover English string.
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $arabicHeading);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $arabicBody);
    }

    /** Every heading has a body, in both files, and nothing is orphaned. */
    public function test_the_empty_group_is_paired_and_in_lockstep(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $group = require base_path("lang/{$locale}/studio.php");
            $keys = array_keys($group['empty']);

            foreach ($keys as $key) {
                $other = str_ends_with($key, '_heading')
                    ? substr($key, 0, -8).'_body'
                    : substr($key, 0, -5).'_heading';

                $this->assertContains($other, $keys, "studio.empty.{$key} ({$locale}) has no {$other}.");
            }

            $this->assertSame([], array_filter($group['empty'], fn (string $v): bool => trim($v) === ''));
        }
    }

    /** The exemption, asserted rather than asserted-in-a-comment. */
    public function test_the_users_table_cannot_be_empty(): void
    {
        $this->assertGreaterThanOrEqual(1, User::query()->count());

        Livewire::test(UserResource::getPages()['index']->getPage())
            ->assertCanSeeTableRecords([auth()->user()]);
    }

    /** @param  class-string<resource>  $resource */
    private function tableFor(string $resource): Table
    {
        return $resource::table(Table::make($this->instanceFor($resource)));
    }

    /** @param  class-string<resource>  $resource */
    private function instanceFor(string $resource): Component
    {
        return Livewire::test($resource::getPages()['index']->getPage())->instance();
    }

    /** An icon is a string or a Heroicon enum depending on how it was set. */
    private function iconName(mixed $icon): string
    {
        return $icon instanceof Heroicon
            ? $icon->value
            : (string) $icon;
    }
}
