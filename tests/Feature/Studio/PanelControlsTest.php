<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Models\Media;
use App\Models\User;
use App\Support\Locale;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 34d — THE RULES THE SCREENSHOTS TAUGHT, KEPT.
 *
 * Seven faults were visible in four screenshots: rows of six different
 * heights, a beaker that meant "stock" to nobody, a breadcrumb naming a PHP
 * class, a browser-drawn date, a toggle louder than the heading, 126px of
 * empty chrome, and a badge whose count landed on the wrong side of its own
 * word. Each is fixed; each is a RULE, and a rule nothing checks is a rule
 * until the next resource is added.
 *
 * So this file holds the rules rather than the seven instances:
 *
 *   · no table anywhere says something in an icon alone
 *   · no text column wraps without a clamp, because that is what made the
 *     library's rows uneven
 *   · no form draws a control the browser styles — no native date, no
 *     number spinner
 *   · a breadcrumb names a place or there is no breadcrumb
 *   · a badge carries its meaning in words
 *
 * The matrix is derived from the directory, so the fifteenth resource is
 * held to all of it the day it appears.
 * ───────────────────────────────────────────────────────────────────────────
 */
class PanelControlsTest extends TestCase
{
    use RefreshDatabase;

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

    /** @return array<string, array{class-string<Resource>}> */
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

    private function tableFor(string $resource): Table
    {
        $instance = Livewire::test($resource::getPages()['index']->getPage())->instance();

        return $resource::table(Table::make($instance));
    }

    // ----------------------------------------------------------------- A2

    /**
     * A2 — NOTHING IN A TABLE SPEAKS ONLY IN PICTURES.
     *
     * The library marked stock photography with a beaker, the pages screen
     * marked a locked page with a padlock, the redirects screen marked a
     * live row with a tick, and the people screen marked a confirmed second
     * factor with another tick. Four columns whose whole purpose is to warn
     * somebody, saying it in a glyph with the explanation in a tooltip —
     * which cannot be hovered on a phone, is not in the page's text, and is
     * not in what a screen reader reads out.
     *
     * @param  class-string<Resource>  $resource
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('resources')]
    public function test_no_column_says_it_in_an_icon_alone(string $resource): void
    {
        $icons = [];

        foreach ($this->tableFor($resource)->getColumns() as $column) {
            if ($column instanceof IconColumn) {
                $icons[] = $column->getName();
            }
        }

        $this->assertSame(
            [],
            $icons,
            class_basename($resource).' says it in an icon alone: '.implode(', ', $icons),
        );
    }

    /** And the widgets, which are tables on the one screen everybody opens. */
    public function test_no_widget_column_says_it_in_an_icon_alone(): void
    {
        foreach (glob(base_path('app/Filament/Widgets/*.php')) as $file) {
            $class = 'App\\Filament\\Widgets\\'.basename($file, '.php');
            $source = (string) file_get_contents($file);

            $this->assertStringNotContainsString(
                'IconColumn::make',
                $source,
                class_basename($class).' marks something with an icon alone',
            );
        }
    }

    // ----------------------------------------------------------------- A1

    /**
     * A1 — A WRAPPED COLUMN IS A CLAMPED COLUMN.
     *
     * The media library's rows measured 153px to 177px because the alt text
     * — a sentence about a photograph — wrapped to as many lines as it
     * liked. Every row a different height is a list the eye has to re-read
     * the shape of on each row.
     *
     * The rule is the one thing that keeps that fixed as columns are added:
     * ->wrap() without ->lineClamp() is how it happened.
     *
     * @param  class-string<Resource>  $resource
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('resources')]
    public function test_every_wrapped_column_is_clamped(string $resource): void
    {
        $unclamped = [];

        foreach ($this->tableFor($resource)->getColumns() as $column) {
            if (! $column instanceof TextColumn || ! $column->canWrap()) {
                continue;
            }

            if ($column->getLineClamp() === null) {
                $unclamped[] = $column->getName();
            }
        }

        /*
         * Asserted even when there is nothing to wrap — a resource with no
         * wrapped column still has to say so, or eleven of these cases pass
         * by making no assertion at all and the matrix reads as if it had
         * checked them.
         */
        $this->assertSame(
            [],
            $unclamped,
            class_basename($resource).' wraps without a clamp: '.implode(', ', $unclamped),
        );
    }

    /** The same rule in the widgets' source, which cannot be instantiated here. */
    public function test_every_wrapped_widget_column_is_clamped(): void
    {
        foreach (glob(base_path('app/Filament/Widgets/*.php')) as $file) {
            $source = (string) file_get_contents($file);
            $wraps = substr_count($source, '->wrap()');
            $clamps = substr_count($source, '->lineClamp(');

            $this->assertGreaterThanOrEqual(
                $wraps,
                $clamps,
                basename($file).": {$wraps} wrapped column(s), {$clamps} clamped",
            );
        }
    }

    // ----------------------------------------------------------------- A4

    /**
     * A4 — THE PANEL DRAWS ITS OWN CONTROLS.
     *
     * published_at rendered as <input type="datetime-local">, which the
     * browser draws: 09/28/2026, 05:37 PM in a US locale, with its own
     * calendar glyph, on a panel that is otherwise entirely typeset. The
     * project year rendered as <input type="number">, with a spinner and a
     * scroll wheel that changes the value.
     *
     * Asserted on the real form schemas, so a new resource that takes a date
     * the easy way fails here.
     *
     * @param  class-string<Resource>  $resource
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('resources')]
    public function test_no_form_control_is_drawn_by_the_browser(string $resource): void
    {
        $pages = $resource::getPages();
        $page = $pages['create'] ?? $pages['index'];

        $instance = Livewire::test($page->getPage())->instance();

        $schema = $resource::form(\Filament\Schemas\Schema::make($instance));

        /*
         * THE SOURCE AS WELL AS THE SCHEMA, because two resources cannot be
         * walked: a Repeater bound to a relationship resolves its children
         * against a model instance, and outside a mounted form there is none
         * ("Call to a member function hasAttribute() on null"). Menus and
         * Projects would therefore be exempt from the rule by accident. The
         * file-level check is cruder and covers them.
         */
        $source = $this->codeOf($resource);

        $this->assertStringNotContainsString(
            '->numeric()',
            $source,
            class_basename($resource).' asks for a number spinner',
        );

        if (str_contains($source, 'DateTimePicker::make')) {
            $this->assertStringContainsString(
                '->native(false)',
                $source,
                class_basename($resource).' leaves a date control to the browser',
            );
        }

        foreach ($this->fieldsOf($schema->getComponents()) as $field) {
            if ($field instanceof \Filament\Forms\Components\DateTimePicker) {
                $this->assertFalse(
                    $field->isNative(),
                    class_basename($resource).': '.$field->getName().' is the browser\'s date control',
                );
            }

            if ($field instanceof \Filament\Forms\Components\TextInput) {
                $this->assertNotSame(
                    'number',
                    $field->getType(),
                    class_basename($resource).': '.$field->getName().' is a number spinner',
                );
            }
        }
    }

    /**
     * A resource's code with its comments removed.
     *
     * The first version of the check below read the raw file and failed on
     * ProjectResource — whose comment explains why the year field is NOT
     * ->numeric(). A rule that cannot be written about in a comment is a rule
     * nobody can explain.
     *
     * @param  class-string<Resource>  $resource
     */
    private function codeOf(string $resource): string
    {
        $source = (string) file_get_contents((new \ReflectionClass($resource))->getFileName());

        return implode('', array_map(
            fn (array | string $token): string => is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1])
                : $token,
            token_get_all($source),
        ));
    }

    /**
     * @param  array<\Filament\Schemas\Components\Component>  $components
     * @return list<\Filament\Forms\Components\Field>
     */
    private function fieldsOf(array $components): array
    {
        $fields = [];

        foreach ($components as $component) {
            if ($component instanceof \Filament\Forms\Components\Field) {
                $fields[] = $component;
            }

            try {
                $children = $component->getChildSchemas();
            } catch (\Throwable) {
                // A relationship repeater outside a mounted form; see above.
                continue;
            }

            foreach ($children as $child) {
                $fields = array_merge($fields, $this->fieldsOf($child->getComponents()));
            }
        }

        return $fields;
    }

    // ----------------------------------------------------------------- A3

    /**
     * A3 — "MEDIA › LIST" NAMED THE SOFTWARE'S OWN SCREEN TYPE.
     *
     * Eight screens read like that, and the nine Manage-style resources
     * showed no breadcrumb at all, so the panel disagreed with itself about
     * the same kind of screen. An index shows none now; a record screen
     * names the record.
     *
     * @param  class-string<Resource>  $resource
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('resources')]
    public function test_an_index_has_no_breadcrumb_trail(string $resource): void
    {
        $page = Livewire::test($resource::getPages()['index']->getPage())->instance();

        $this->assertSame(
            [],
            $page->getBreadcrumbs(),
            class_basename($resource).' still shows a trail on its index',
        );
    }

    /** A record screen names the record, and never the screen. */
    public function test_a_record_screen_names_the_record(): void
    {
        $page = \App\Models\Page::factory()->create(['title' => ['en' => 'Terms', 'ar' => 'الشروط']]);

        $crumbs = Livewire::test(
            \App\Filament\Resources\Pages\Pages\EditPage::class,
            ['record' => $page->getKey()],
        )->instance()->getBreadcrumbs();

        $this->assertContains('Terms', array_values($crumbs));

        foreach (['List', 'Edit', 'Create', 'View', 'Manage'] as $screenType) {
            $this->assertNotContains($screenType, array_values($crumbs), "the trail still names a screen type: {$screenType}");
        }

        // A trail of one is the page you are on; this one goes somewhere.
        $this->assertGreaterThan(1, count($crumbs));
    }

    /** A create screen says what is being made, in the model's own words. */
    public function test_a_create_screen_names_the_thing_being_made(): void
    {
        $crumbs = array_values(
            Livewire::test(\App\Filament\Resources\Projects\Pages\CreateProject::class)
                ->instance()
                ->getBreadcrumbs(),
        );

        $this->assertContains(
            __('studio.breadcrumb.new', ['thing' => \App\Filament\Resources\Projects\ProjectResource::getModelLabel()]),
            $crumbs,
        );
        $this->assertNotContains('Create', $crumbs);
    }

    // ----------------------------------------------------------------- A7

    /**
     * A7 — A BADGE DOES NOT MIX SCRIPTS INLINE.
     *
     * The translation badge read "العربية — 1" in an English table: an RTL
     * run, a neutral dash and a Western digit inside an LTR cell, which the
     * bidi algorithm laid out as "1 — العربية" — the count first, which is
     * not what the badge says. It names the language in the READER's
     * language now, so there is no mixed run to lay out either way round.
     */
    public function test_the_translation_badge_is_in_one_script(): void
    {
        $page = \App\Models\Page::factory()->create([
            'title' => ['en' => 'Terms', 'ar' => null],
            'standfirst' => ['en' => 'Only English', 'ar' => null],
        ]);

        foreach (['en' => '/\p{Arabic}/u', 'ar' => '/[A-Za-z]/'] as $locale => $foreign) {
            $this->app->setLocale($locale);

            /*
             * A FRESH COLUMN PER LOCALE. Filament caches a column's state on
             * the instance, so asking the same column twice returned the
             * English badge under the Arabic locale and the test read as if
             * the fix had not worked.
             */
            $column = collect($this->tableFor(\App\Filament\Resources\Pages\PageResource::class)->getColumns())
                ->first(fn ($c): bool => $c->getName() === 'translation_status');

            $this->assertNotNull($column);

            $states = $column->record($page)->getState();

            $this->assertNotEmpty($states);

            foreach ((array) $states as $state) {
                $this->assertDoesNotMatchRegularExpression(
                    $foreign,
                    (string) $state,
                    "the {$locale} badge mixes scripts: {$state}",
                );
            }
        }
    }

    // ----------------------------------------------------------------- B2

    /**
     * B2 — A COUNT SAYS WHAT IT COUNTS, AND A STATE SAYS ITS NAME.
     *
     * Every badge that used to be a bare number or a coloured glyph, with
     * the words it carries now. Listed one at a time on purpose: these are
     * the sentences somebody reads off the screen, and a test that only
     * asserted "not empty" would pass on "0".
     */
    public function test_every_badge_carries_words(): void
    {
        $media = Media::factory()->create([
            'is_demo' => true,
            'status' => \App\Enums\MediaStatus::Ready,
        ]);

        $columns = collect($this->tableFor(\App\Filament\Resources\Media\MediaResource::class)->getColumns())
            ->keyBy(fn ($c) => $c->getName());

        $this->assertSame(__('studio.media.demo'), $columns['is_demo']->formatState(true));
        $this->assertSame('', $columns['is_demo']->formatState(false));

        $this->assertSame(
            trans_choice('studio.media.usage_short', 0, ['count' => 0]),
            $columns['usages_count']->formatState(0),
        );

        $redirects = collect($this->tableFor(\App\Filament\Resources\Redirects\RedirectResource::class)->getColumns())
            ->keyBy(fn ($c) => $c->getName());

        $this->assertSame(__('studio.redirects.permanent'), $redirects['status_code']->formatState(301));
        $this->assertSame(__('studio.redirects.live'), $redirects['is_active']->formatState(true));
        $this->assertSame(__('studio.redirects.paused'), $redirects['is_active']->formatState(false));

        $users = collect($this->tableFor(\App\Filament\Resources\Users\UserResource::class)->getColumns())
            ->keyBy(fn ($c) => $c->getName());

        $this->assertSame(__('studio.users.two_factor_on'), $users['two_factor_confirmed_at']->formatState(true));
        $this->assertSame(__('studio.users.two_factor_off'), $users['two_factor_confirmed_at']->formatState(false));

        $categories = collect($this->tableFor(\App\Filament\Resources\Categories\CategoryResource::class)->getColumns())
            ->keyBy(fn ($c) => $c->getName());

        $this->assertSame(
            trans_choice('studio.table.used_count', 0, ['count' => 0]),
            $categories['projects_count']->formatState(0),
        );
        $this->assertSame(
            trans_choice('studio.table.used_count', 3, ['count' => 3]),
            $categories['projects_count']->formatState(3),
        );

        // None of those is a bare number or an empty string standing for a state.
        foreach ([
            $redirects['status_code']->formatState(301),
            $users['two_factor_confirmed_at']->formatState(false),
            $categories['projects_count']->formatState(3),
        ] as $rendered) {
            $this->assertMatchesRegularExpression('/\p{L}/u', (string) $rendered, "\"{$rendered}\" is not words");
        }
    }

    /** And the words exist in both languages. */
    public function test_the_new_words_are_translated(): void
    {
        foreach ([
            'studio.media.usage_short',
            'studio.table.used_count',
            'studio.menus.item_count',
        ] as $key) {
            foreach ([0, 1, 5] as $count) {
                foreach (Locale::codes() as $locale) {
                    $this->app->setLocale($locale);

                    $rendered = trans_choice($key, $count, ['count' => $count]);

                    $this->assertNotSame($key, $rendered, "{$key} has no {$locale}");
                    $this->assertMatchesRegularExpression(
                        $locale === 'ar' ? '/\p{Arabic}/u' : '/[A-Za-z]/',
                        $rendered,
                        "{$key} ({$count}) reads wrong in {$locale}: {$rendered}",
                    );
                }
            }
        }
    }
}
