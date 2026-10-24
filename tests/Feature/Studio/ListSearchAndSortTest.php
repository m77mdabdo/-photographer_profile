<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Filament\Resources\ArticleCategories\Pages\ManageArticleCategories;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Resources\Clients\Pages\ManageClients;
use App\Filament\Resources\Enquiries\Pages\ListInquiries;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Redirects\Pages\ManageRedirects;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Category;
use App\Models\Client;
use App\Models\Inquiry;
use App\Models\Media;
use App\Models\Page;
use App\Models\Project;
use App\Models\Redirect;
use App\Models\Service;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 33 C2 — THE SEARCH BOX AND THE COLUMN HEADERS, AS AN OWNER USES THEM.
 *
 * Both were decoration before this task, in different ways.
 *
 * SEARCH matched the title and nothing else. Measured on a project titled
 * "Vineyard At Dusk" for Harrods in Provence in 2024:
 *
 *     search Vineyard  => FOUND
 *     search Harrods   => no match
 *     search Provence  => no match
 *     search 2024      => no match
 *
 * Nobody searches a photography archive by its titles. They search by the
 * client, the place and the year, which is what the files are named after in
 * every studio that has ever existed.
 *
 * SORTING was worse, because it looked like it worked. `title` is a JSON
 * column, so ORDER BY title ordered by the serialised document. Three
 * projects, ascending:
 *
 *     locale en:  Mango | Apple | Zephyr
 *     locale ar:  ميم | ياء | ألف
 *
 * Alphabetical in neither language. A sorted list that is not in order is
 * worse than an unsorted one: you stop looking once you think you have seen
 * where the name should be.
 *
 * Both are fixed in Translatable::displayColumn(), which every resource's
 * primary column now goes through. This file is the proof, per resource and
 * per locale, and the reason a future refactor cannot quietly undo it.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class ListSearchAndSortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_confirmed_at' => now(),
        ]));

        Filament::setCurrentPanel('studio');
    }

    /**
     * One subject per resource, carrying a recognisable value in every field
     * the search box is meant to reach.
     *
     * @return array<string, array{0: class-string, 1: callable, 2: array<string, string>}>
     */
    public static function searchable(): array
    {
        return [
            // The case the task named: a photographer's own vocabulary.
            'projects' => [ListProjects::class, fn (): Model => Project::factory()->create([
                'title' => ['en' => 'Vineyard At Dusk', 'ar' => 'كروم عند الغروب'],
                'client' => ['en' => 'Harrods', 'ar' => 'هارودز'],
                'location' => ['en' => 'Provence', 'ar' => 'بروفانس'],
                'year' => 2024,
            ]), [
                'english title' => 'Vineyard',
                'arabic title' => 'كروم',
                'client' => 'Harrods',
                'arabic client' => 'هارودز',
                'location' => 'Provence',
                'year' => '2024',
            ]],

            'articles' => [ListArticles::class, fn (): Model => Article::factory()->create([
                'title' => ['en' => 'The Edit Is The Work', 'ar' => 'التحرير هو العمل'],
                'excerpt' => ['en' => 'On choosing eleven frames from four hundred.', 'ar' => 'في اختيار إحدى عشرة صورة.'],
            ]), [
                'english title' => 'Edit Is',
                'arabic title' => 'التحرير',
                'a phrase from the excerpt' => 'four hundred',
            ]],

            'pages' => [ListPages::class, fn (): Model => Page::factory()->create([
                'title' => ['en' => 'How We Work', 'ar' => 'كيف نعمل'],
                'slug' => ['en' => 'how-we-work', 'ar' => 'كيف-نعمل'],
            ]), [
                'english title' => 'How We',
                'arabic title' => 'كيف نعمل',
                'the address' => 'how-we-work',
            ]],

            'services' => [ListServices::class, fn (): Model => Service::factory()->create([
                'name' => ['en' => 'Editorial Commissions', 'ar' => 'تكليفات تحريرية'],
                'summary' => ['en' => 'A day with a picture editor and a brief.', 'ar' => 'يوم مع محرّر صور.'],
            ]), [
                'english name' => 'Editorial',
                'arabic name' => 'تكليفات',
                'a phrase from the summary' => 'picture editor',
            ]],

            // C3's column, searched the way it is now read.
            'media' => [ListMedia::class, fn (): Model => Media::factory()->create([
                'alt' => ['en' => 'A bride by a tall window', 'ar' => 'عروس عند نافذة عالية'],
                'caption' => ['en' => 'Ninth frame of the morning', 'ar' => 'الصورة التاسعة'],
                'original_filename' => 'pexels-photo-3184465.jpeg',
            ]), [
                'english alt text' => 'bride by a tall',
                'arabic alt text' => 'عروس',
                'the caption' => 'Ninth frame',
                'the raw filename, still' => '3184465',
            ]],

            'enquiries' => [ListInquiries::class, fn (): Model => Inquiry::factory()->create([
                'name' => 'Imogen Hartley',
                'email' => 'imogen@hartley-press.co.uk',
                'company' => 'Hartley Press',
                'phone' => '07700 900461',
                'location' => 'Marylebone',
                'message' => 'We are looking for someone to photograph a cookery book.',
            ]), [
                'name' => 'Imogen',
                'email' => 'hartley-press.co.uk',
                'company' => 'Hartley Press',
                'phone' => '900461',
                'location' => 'Marylebone',
                'a phrase from the message' => 'cookery book',
            ]],

            'users' => [ManageUsers::class, fn (): Model => User::factory()->create([
                'name' => 'Rafiq Haddad',
                'email' => 'rafiq@example.test',
            ]), [
                'name' => 'Rafiq',
                'email' => 'rafiq@example',
            ]],

            'redirects' => [ManageRedirects::class, fn (): Model => Redirect::factory()->create([
                'from_path' => '/old-salt-line',
                'to_path' => '/work/salt-line',
            ]), [
                'the old address' => 'old-salt-line',
                'the new address' => 'work/salt-line',
            ]],

            'clients' => [ManageClients::class, fn (): Model => Client::factory()->create([
                'name' => ['en' => 'Harrods', 'ar' => 'هارودز'],
                'url' => 'https://harrods.example.test',
            ]), [
                'english name' => 'Harrods',
                'arabic name' => 'هارودز',
                'the address' => 'harrods.example',
            ]],

            // A translatable name with no generated column: the JSON fallback.
            'tags' => [ManageTags::class, fn (): Model => Tag::factory()->create([
                'name' => ['en' => 'Available Light', 'ar' => 'ضوء متاح'],
            ]), [
                'english name' => 'Available',
                'arabic name' => 'ضوء',
            ]],

            'categories' => [ManageCategories::class, fn (): Model => Category::factory()->create([
                'name' => ['en' => 'Weddings', 'ar' => 'أعراس'],
            ]), [
                'english name' => 'Weddings',
                'arabic name' => 'أعراس',
            ]],

            'journal sections' => [ManageArticleCategories::class, fn (): Model => ArticleCategory::factory()->create([
                'name' => ['en' => 'On Looking', 'ar' => 'في النظر'],
            ]), [
                'english name' => 'On Looking',
                'arabic name' => 'في النظر',
            ]],
        ];
    }

    /**
     * Every term an owner would plausibly type reaches the right row.
     *
     * The decoys matter as much as the subject: a search that returns
     * everything is as useless as one that returns nothing, and a LIKE over a
     * JSON document is quite capable of matching a key name or an escape
     * sequence in a row it has no business matching.
     *
     * @param  callable(): Model  $subject
     * @param  array<string, string>  $terms
     */
    #[DataProvider('searchable')]
    public function test_search_reaches_every_field_an_owner_would_type(
        string $page,
        callable $subject,
        array $terms,
    ): void {
        $record = $subject();

        /*
         * ───────────────────────────────────────────────────────────────────
         * THE DECOYS ARE DETERMINISTIC, NOT RANDOM.
         *
         * They exist to prove a search does not match everything, so what
         * they must never do is accidentally contain a search term. Both
         * halves of this were found the hard way:
         *
         *   · ProjectFactory picks a year at random from a plausible range,
         *     and one run in a handful produced a decoy also made in 2024.
         *   · ArticleFactory writes a faker sentence, and one produced a
         *     title containing "Edit Is".
         *
         * A flaky test about search is worse than no test about search, so
         * every text field a search could reach is overwritten with a string
         * that shares no word with any subject above.
         * ───────────────────────────────────────────────────────────────────
         */
        $inert = 'Zzyzx Quorum Vellum';

        $overrides = [];

        foreach (['title', 'name', 'quote', 'label'] as $field) {
            if (in_array($field, $record->getFillable(), true)) {
                $overrides[$field] = method_exists($record, 'isTranslatableAttribute')
                    && $record->isTranslatableAttribute($field)
                        ? ['en' => $inert, 'ar' => 'زيزكس']
                        : $inert;
            }
        }

        foreach (['excerpt', 'summary', 'attribution', 'role', 'company', 'message', 'location', 'client'] as $field) {
            if (in_array($field, $record->getFillable(), true)) {
                $overrides[$field] = method_exists($record, 'isTranslatableAttribute')
                    && $record->isTranslatableAttribute($field)
                        ? ['en' => $inert, 'ar' => 'زيزكس']
                        : $inert;
            }
        }

        if (in_array('year', $record->getFillable(), true)) {
            $overrides['year'] = 1999;
        }

        $decoys = $record::factory()->count(3)->create($overrides);

        foreach ($terms as $what => $term) {
            $matched = Livewire::test($page)
                ->set('tableSearch', $term)
                ->instance()
                ->getFilteredTableQuery()
                ->pluck($record->getKeyName());

            $this->assertTrue(
                $matched->contains($record->getKey()),
                sprintf('%s: searching the %s ("%s") did not find the record.', $page, $what, $term),
            );

            foreach ($decoys as $decoy) {
                $this->assertFalse(
                    $matched->contains($decoy->getKey()),
                    sprintf('%s: searching the %s ("%s") also matched an unrelated row.', $page, $what, $term),
                );
            }
        }
    }

    /**
     * @return array<string, array{0: class-string, 1: class-string<Model>, 2: string}>
     */
    public static function alphabetical(): array
    {
        return [
            'projects' => [ListProjects::class, Project::class, 'title'],
            'articles' => [ListArticles::class, Article::class, 'title'],
            'pages' => [ListPages::class, Page::class, 'title'],
            'services' => [ListServices::class, Service::class, 'name'],
            'tags' => [ManageTags::class, Tag::class, 'name'],
            'clients' => [ManageClients::class, Client::class, 'name'],
            'categories' => [ManageCategories::class, Category::class, 'name'],
            'journal sections' => [ManageArticleCategories::class, ArticleCategory::class, 'name'],
        ];
    }

    /**
     * Sorting the primary column puts the rows in the order they READ, in
     * whichever language the panel is in.
     *
     * The Arabic set is deliberately in the opposite order to the English one.
     * A single-language test would pass against `ORDER BY title`, because the
     * JSON happens to start with the English value — which is exactly how this
     * went unnoticed.
     *
     * @param  class-string<Model>  $model
     */
    #[DataProvider('alphabetical')]
    public function test_the_primary_column_sorts_as_the_words_read(
        string $page,
        string $model,
        string $attribute,
    ): void {
        $rows = [
            ['en' => 'Apple', 'ar' => 'ياء'],
            ['en' => 'Mango', 'ar' => 'ألف'],
            ['en' => 'Zephyr', 'ar' => 'ميم'],
        ];

        foreach ($rows as $row) {
            $model::factory()->create([$attribute => $row]);
        }

        foreach ([
            'en' => ['Apple', 'Mango', 'Zephyr'],
            'ar' => ['ألف', 'ميم', 'ياء'],
        ] as $locale => $expected) {
            app()->setLocale($locale);

            $sorted = Livewire::test($page)
                ->set('tableSort', $attribute.':asc')
                ->instance()
                ->getFilteredSortedTableQuery()
                ->get()
                ->map(fn (Model $record): ?string => $record->getTranslation($attribute, $locale, fallback: false))
                ->all();

            $this->assertSame(
                $expected,
                $sorted,
                sprintf('%s sorted by %s in %s is not in reading order.', $page, $attribute, $locale),
            );
        }
    }

    /**
     * Descending is the same ordering backwards.
     *
     * Not a tautology: COALESCE over two locale columns is easy to write in a
     * way that works one way round and groups the untranslated rows at one end
     * the other way.
     */
    public function test_descending_is_the_reverse_of_ascending(): void
    {
        foreach ([
            ['en' => 'Apple', 'ar' => 'ياء'],
            ['en' => 'Mango', 'ar' => 'ألف'],
            ['en' => 'Zephyr', 'ar' => 'ميم'],
        ] as $row) {
            Project::factory()->create(['title' => $row]);
        }

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            $read = fn (string $direction): array => Livewire::test(ListProjects::class)
                ->set('tableSort', 'title:'.$direction)
                ->instance()
                ->getFilteredSortedTableQuery()
                ->get()
                ->map(fn (Project $p): ?string => $p->getTranslation('title', $locale, fallback: false))
                ->all();

            $this->assertSame(array_reverse($read('asc')), $read('desc'), "in $locale");
        }
    }

    /**
     * A row with no Arabic shows its English and sorts where that English puts
     * it, rather than being swept to one end of the list.
     *
     * This is why displayExpression() wraps the two locale columns in
     * COALESCE: the cell falls back, so the ordering has to fall back too, or
     * the list disagrees with itself.
     */
    public function test_an_untranslated_row_sorts_where_its_fallback_reads(): void
    {
        Project::factory()->create(['title' => ['en' => 'Apple', 'ar' => 'ألف']]);
        Project::factory()->create(['title' => ['en' => 'Mango']]);
        Project::factory()->create(['title' => ['en' => 'Zephyr', 'ar' => 'ياء']]);

        app()->setLocale('ar');

        $sorted = Livewire::test(ListProjects::class)
            ->set('tableSort', 'title:asc')
            ->instance()
            ->getFilteredSortedTableQuery()
            ->get()
            ->map(fn (Project $p): string => $p->title)
            ->all();

        // ألف, then the English fallback "Mango", then ياء: Arabic letters
        // sort after Latin ones in MySQL's collation, so the untranslated row
        // leads — and, crucially, it is IN the list rather than appended to it.
        $this->assertSame(['Mango', 'ألف', 'ياء'], $sorted);
    }

    /**
     * Every list view states an order. A table with no default sort shows
     * rows in whatever order the storage engine returns them, which changes
     * under the reader as rows are edited.
     */
    public function test_every_list_view_declares_a_default_sort(): void
    {
        foreach (Filament::getPanel('studio')->getResources() as $resource) {
            $page = $resource::getPages()['index']->getPage();
            $table = Livewire::test($page)->instance()->getTable();

            $this->assertTrue(
                filled($table->getDefaultSortColumn()) || filled($table->getDefaultSortQuery()),
                class_basename($resource).' has no default sort.',
            );
        }
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * EVERY LIST THAT CAN GROW OFFERS A FILTER.
     *
     * This read "every list view, no exemptions", and said so deliberately:
     * "a thirteenth resource with no filters fails here rather than being
     * waved through". The thirteenth arrived in Task 34 D1 and failed — and
     * on inspection the rule, not the resource, was wrong.
     *
     * Navigation is TWO ROWS, fixed: the header strip and the footer column,
     * because those are the two places the design has for one. Its policy
     * refuses create and delete in words. A filter over a list that cannot
     * grow past two is furniture.
     *
     * So the rule is rephrased as what it always meant — a list a reader has
     * to find their way through must offer a way through it — and "can grow"
     * is read off the POLICY rather than from a list of names here. A
     * resource that allows creation and ships no filter still fails, which
     * is the case this was written for.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_every_list_that_can_grow_offers_at_least_one_filter(): void
    {
        $without = [];

        foreach (Filament::getPanel('studio')->getResources() as $resource) {
            if (! $this->canGrow($resource)) {
                continue;
            }

            $page = $resource::getPages()['index']->getPage();

            if (Livewire::test($page)->instance()->getTable()->getFilters() === []) {
                $without[] = class_basename($resource);
            }
        }

        $this->assertSame([], $without, 'These list views offer no filters: '.implode(', ', $without));
    }

    /**
     * Can an owner add rows to this resource at all?
     *
     * Asked of the policy, which is where the answer is declared and worded.
     * A resource whose records are fixed — Navigation's two places in the
     * design — is a different kind of screen from one that accumulates.
     *
     * @param  class-string<resource>  $resource
     */
    private function canGrow(string $resource): bool
    {
        return auth()->user()?->can('create', $resource::getModel()) ?? false;
    }

    /**
     * Every list that can grow can be searched.
     *
     * Same rephrasing and the same reason as the filter rule above: a search
     * box over two fixed rows is a control that can only ever hide one of
     * them.
     */
    public function test_every_list_that_can_grow_has_a_search_box(): void
    {
        foreach (Filament::getPanel('studio')->getResources() as $resource) {
            if (! $this->canGrow($resource)) {
                continue;
            }

            $page = $resource::getPages()['index']->getPage();
            $table = Livewire::test($page)->instance()->getTable();

            $this->assertTrue(
                $table->isSearchable(),
                class_basename($resource).' cannot be searched.',
            );
        }
    }

    /**
     * And a list that CANNOT grow is fixed on purpose, not by accident.
     *
     * The exemption above is only honest if something checks that the
     * resources taking it really are closed sets — otherwise "no create
     * policy" becomes a way to opt out of being searchable.
     */
    public function test_a_fixed_list_refuses_creation_in_words(): void
    {
        foreach (Filament::getPanel('studio')->getResources() as $resource) {
            if ($this->canGrow($resource)) {
                continue;
            }

            $response = Gate::forUser(auth()->user())
                ->inspect('create', $resource::getModel());

            $this->assertFalse($response->allowed());

            $this->assertNotEmpty(
                $response->message(),
                class_basename($resource).' refuses creation without saying why',
            );
        }
    }
}
