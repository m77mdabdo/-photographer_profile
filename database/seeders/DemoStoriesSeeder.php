<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Projects\SyncProjectMedia;
use App\Enums\BlockType;
use App\Enums\MediaStatus;
use App\Enums\PublishStatus;
use App\Models\Category;
use App\Models\Media;
use App\Models\Project;
use App\Models\ProjectBlock;
use App\Support\AccentColor;
use App\Support\AccentPalette;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Nine demonstration series, each with a real sequence of photographs.
 *
 * WHY THE SCORES ARE DATA
 *
 * The temptation with seeded content is a loop: eight projects, each twelve
 * images, each the same alternation of block types. That produces a site that
 * is full and still looks empty, because every page has the same shape and a
 * visitor learns after two of them that there is nothing to find in a third.
 *
 * A photo book does not work like that. One series opens full-bleed and runs
 * in pairs; another is a roll of street frames with a single page of text in
 * it; another is twelve pictures of a table. So each score below is written
 * out by hand — its own length, its own rhythm, its own ratio of pictures to
 * words — and one of them is deliberately three frames long, because studios
 * have those too and the templates have to hold at that length.
 *
 * IDEMPOTENT. Keyed on slug, blocks rebuilt from the score, images allocated
 * deterministically by id order. Running it twice produces the same site.
 */
class DemoStoriesSeeder extends Seeder
{
    /**
     * A block in a score: its type, how many frames it takes, and which
     * orientation those frames should be if the pool can supply them.
     */
    private const SCORES = [

        // ---- 1. Landscape, opening wide and running in pairs ------------
        'salt-line' => [
            'title' => 'Salt Line',
            'subtitle' => 'Eighteen months on one stretch of coast',
            'excerpt' => 'A shoreline photographed through three winters, from the same six vantage points, until the weather stopped being scenery and started being the subject.',
            'description' => "The brief was a single commission for a harbour trust. It became a standing arrangement: the same walk, the same order, whatever the light was doing.\n\nWhat the repetition makes visible is not the sea. It is the way a place keeps its shape while everything on it moves.",
            'year' => 2025, 'location' => 'Northumberland', 'client' => 'Harbour Trust',
            'categories' => ['landscape', 'documentary'],
            'set' => 'landscape',
            'score' => [
                ['type' => 'full_width_image', 'n' => 1, 'want' => 'landscape', 'caption' => 'First light, low water. The line the tide leaves is the only thing that repeats.'],
                ['type' => 'full_width_text', 'n' => 0, 'heading' => 'A place that keeps its shape', 'body' => "Six vantage points, walked in the same order, for eighteen months.\n\nThe sequence is the argument: nothing here is a decisive moment, and that is the point. The coast does not perform."],
                ['type' => 'landscape_pair', 'n' => 2, 'want' => 'landscape', 'caption' => 'November, then February, from the same standing.'],
                ['type' => 'contained_image', 'n' => 1, 'want' => 'landscape'],
                ['type' => 'quote', 'n' => 0, 'quote' => 'You cannot photograph weather. You can only photograph what it has already done.', 'attribution' => 'Working note, second winter'],
                ['type' => 'three_image_grid', 'n' => 3, 'want' => 'landscape', 'caption' => 'Three mornings, one week apart.'],
                ['type' => 'full_width_image', 'n' => 1, 'want' => 'landscape'],
                ['type' => 'landscape_pair', 'n' => 2, 'want' => 'landscape'],
                ['type' => 'gallery_strip', 'n' => 6, 'want' => 'any', 'caption' => 'Offcuts kept for the record.'],
                ['type' => 'credits', 'n' => 0, 'heading' => 'Made with'],
            ],
        ],

        // ---- 2. Portraiture, mostly pairs, text carried by the sitter ----
        'the-sitting' => [
            'title' => 'The Sitting',
            'subtitle' => 'Fifteen people, one chair, no direction',
            'excerpt' => 'Each sitter was given the room, the chair and forty minutes, and told nothing else. What they did with the silence is the series.',
            'description' => "A commission that began as staff portraits for an annual report and was allowed to become something else.\n\nThe single instruction was that there would be no instruction. Most people talk for the first ten minutes. The pictures are from the twenty after that.",
            'year' => 2025, 'location' => 'Newcastle', 'client' => 'Meridian Press',
            'categories' => ['portrait', 'editorial'],
            'set' => 'portrait',
            'score' => [
                ['type' => 'full_width_image', 'n' => 1, 'want' => 'portrait'],
                ['type' => 'image_text', 'n' => 1, 'want' => 'portrait', 'heading' => 'Forty minutes', 'body' => 'The chair was placed once, on the first morning, and never moved. Everything that changes between these frames is the person.'],
                ['type' => 'portrait_pair', 'n' => 2, 'want' => 'portrait', 'caption' => 'Two sitters, consecutive afternoons.'],
                ['type' => 'portrait_pair', 'n' => 2, 'want' => 'portrait'],
                ['type' => 'quote', 'n' => 0, 'quote' => 'I kept waiting to be told what to do with my hands. Nobody said anything, so eventually they just stopped.', 'attribution' => 'Sitter, eleventh session'],
                ['type' => 'portrait_pair', 'n' => 2, 'want' => 'portrait'],
                ['type' => 'contained_image', 'n' => 1, 'want' => 'portrait'],
                ['type' => 'text_image', 'n' => 1, 'want' => 'landscape', 'heading' => 'What the room did', 'body' => 'North light, one window, a wall that has been painted the same colour since 1974. None of it was chosen for the series; all of it is in every frame.'],
                ['type' => 'portrait_pair', 'n' => 2, 'want' => 'portrait'],
                ['type' => 'three_image_grid', 'n' => 3, 'want' => 'portrait', 'caption' => 'Hands, in the last ten minutes.'],
                ['type' => 'credits', 'n' => 0, 'heading' => 'Made with'],
            ],
        ],

        // ---- 3. Architecture, geometric, sparse ---------------------------
        'concrete-season' => [
            'title' => 'Concrete Season',
            'subtitle' => 'A post-war estate, photographed before the works',
            'excerpt' => 'Twelve frames made over four days in the last winter before refurbishment, when the building was empty and the scaffolding had not yet arrived.',
            'description' => "Commissioned as a record, which is a specific kind of brief: not to flatter the building and not to condemn it, but to describe it accurately enough that somebody can argue about it later.\n\nEverything here is available light. Nothing has been straightened that was not straight.",
            'year' => 2024, 'location' => 'Sheffield', 'client' => 'Civic Archive',
            'categories' => ['architecture', 'documentary'],
            'set' => 'architecture',
            'score' => [
                ['type' => 'contained_image', 'n' => 1, 'want' => 'landscape', 'caption' => 'West elevation, four in the afternoon.'],
                ['type' => 'full_width_text', 'n' => 0, 'heading' => 'Before the works', 'body' => "Four days, empty, mid-January.\n\nThe refurbishment will keep the frame and replace everything hung on it. These are the last pictures in which the two are the same object."],
                ['type' => 'three_image_grid', 'n' => 3, 'want' => 'landscape', 'caption' => 'Deck access, three levels.'],
                ['type' => 'full_width_image', 'n' => 1, 'want' => 'landscape'],
                ['type' => 'landscape_pair', 'n' => 2, 'want' => 'landscape'],
                ['type' => 'image_text', 'n' => 1, 'want' => 'portrait', 'heading' => 'The stair', 'body' => 'Cast in one pour, and the only part of the building everybody who lived here can describe from memory.'],
                ['type' => 'three_image_grid', 'n' => 3, 'want' => 'landscape'],
                ['type' => 'spacer', 'n' => 0, 'height' => 120],
                ['type' => 'full_width_image', 'n' => 1, 'want' => 'landscape', 'caption' => 'Last frame, last afternoon.'],
                ['type' => 'credits', 'n' => 0, 'heading' => 'Made with'],
            ],
        ],

        // ---- 4. Fashion, dense, a long strip in the middle ----------------
        'second-skin' => [
            'title' => 'Second Skin',
            'subtitle' => 'A collection shot in the room it was made in',
            'excerpt' => 'Eighteen frames made in a working studio over two days, with the cutting table left exactly where it was.',
            'description' => "The garments were photographed where they were made rather than in a hired space, which was the designer's condition and turned out to be the whole idea.\n\nPins, chalk lines and offcuts are in shot throughout. Nothing was tidied.",
            'year' => 2025, 'location' => 'London', 'client' => 'Nord & Vale',
            'categories' => ['fashion', 'editorial'],
            'set' => 'fashion',
            'score' => [
                ['type' => 'full_width_image', 'n' => 1, 'want' => 'portrait'],
                ['type' => 'portrait_pair', 'n' => 2, 'want' => 'portrait', 'caption' => 'Look four, two exposures apart.'],
                ['type' => 'text_image', 'n' => 1, 'want' => 'portrait', 'heading' => 'In the room it was made in', 'body' => 'Two days, one window, the cutting table where it always is. The studio is not a set dressed to look like a studio.'],
                ['type' => 'gallery_strip', 'n' => 8, 'want' => 'any', 'caption' => 'The full run, in order.'],
                ['type' => 'quote', 'n' => 0, 'quote' => 'If you move the table you get a different collection. That is not a joke.', 'attribution' => 'The designer, first morning'],
                ['type' => 'portrait_pair', 'n' => 2, 'want' => 'portrait'],
                ['type' => 'three_image_grid', 'n' => 3, 'want' => 'any', 'caption' => 'Cloth, pinned and unpinned.'],
                ['type' => 'full_width_image', 'n' => 1, 'want' => 'portrait'],
                ['type' => 'credits', 'n' => 0, 'heading' => 'Made with'],
            ],
        ],

        // ---- 5. Street, a roll with one page of text ----------------------
        'nine-streets' => [
            'title' => 'Nine Streets',
            'subtitle' => 'One route, walked at the same hour for a year',
            'excerpt' => 'Twenty-three frames from a single repeated walk, presented as they were shot: in order, uncropped, with the failures left in.',
            'description' => "There is no editing here beyond choosing the roll. The sequence is the walk.\n\nStreet photography is mostly a record of what you missed. Leaving the near-misses in seemed more honest than pretending to a year of good luck.",
            'year' => 2024, 'location' => 'Glasgow', 'client' => 'Self-initiated',
            'categories' => ['street', 'documentary'],
            'set' => 'street',
            'score' => [
                ['type' => 'gallery_strip', 'n' => 12, 'want' => 'any', 'caption' => 'First half of the route, in order.'],
                ['type' => 'full_width_text', 'n' => 0, 'heading' => 'The same nine corners', 'body' => "Same route, same hour, fifty-one weeks.\n\nWhat changes is never the architecture. It is the light, the weather and whoever happens to be standing in it, which between them are enough."],
                ['type' => 'gallery_strip', 'n' => 10, 'want' => 'any', 'caption' => 'Second half, same order.'],
                ['type' => 'contained_image', 'n' => 1, 'want' => 'landscape', 'caption' => 'Last frame of the last walk.'],
                ['type' => 'credits', 'n' => 0, 'heading' => 'Made with'],
            ],
        ],

        // ---- 6. Still life, small, quiet, grid-heavy ----------------------
        'table-studies' => [
            'title' => 'Table Studies',
            'subtitle' => 'Twelve objects, one surface, north light',
            'excerpt' => 'A set made across four mornings for a ceramicist, photographing finished work beside the tools that made it.',
            'description' => "The condition was that nothing be lit. Everything here is the window, a sheet of card, and waiting for the right part of the morning.\n\nThe pieces are for sale. The tools are not, and were the more interesting subject.",
            'year' => 2024, 'location' => 'Northumberland', 'client' => 'Studio Halcyon',
            'categories' => ['still-life', 'editorial'],
            'set' => 'still-life',
            'score' => [
                ['type' => 'contained_image', 'n' => 1, 'want' => 'any', 'caption' => 'Bowl, unglazed, third firing.'],
                ['type' => 'image_text', 'n' => 1, 'want' => 'any', 'heading' => 'Nothing was lit', 'body' => 'One window facing north, one sheet of card, four mornings. The longest exposure in the set is two seconds.'],
                ['type' => 'three_image_grid', 'n' => 3, 'want' => 'any', 'caption' => 'Finished work.'],
                ['type' => 'three_image_grid', 'n' => 3, 'want' => 'any', 'caption' => 'And the tools that made it.'],
                ['type' => 'quote', 'n' => 0, 'quote' => 'Photograph the chipped one. People trust a chipped one.', 'attribution' => 'The ceramicist'],
                ['type' => 'landscape_pair', 'n' => 2, 'want' => 'any'],
                ['type' => 'contained_image', 'n' => 1, 'want' => 'any'],
                ['type' => 'contained_image', 'n' => 1, 'want' => 'any', 'caption' => 'Last of the fourth morning.'],
                ['type' => 'credits', 'n' => 0, 'heading' => 'Made with'],
            ],
        ],

        // ---- 7. Travel, reportage rhythm ----------------------------------
        'north-by-degrees' => [
            'title' => 'North by Degrees',
            'subtitle' => 'Four coastal towns, travelling only by bus',
            'excerpt' => 'Fifteen frames from a commission with one rule attached: no car, which turned a week of locations into a month of waiting.',
            'description' => "The constraint was the client's, and it was the right one. Arriving somewhere slowly means arriving at the hour the bus arrives, not the hour the light is good.\n\nMost of these were made while waiting to leave.",
            'year' => 2023, 'location' => 'North Sea coast', 'client' => 'Aperture Quarterly',
            'categories' => ['travel', 'documentary'],
            'set' => 'travel',
            'score' => [
                ['type' => 'full_width_image', 'n' => 1, 'want' => 'landscape'],
                ['type' => 'full_width_text', 'n' => 0, 'heading' => 'No car', 'body' => "Four towns, one month, public transport only.\n\nYou photograph a place differently when you cannot leave it on your own schedule."],
                ['type' => 'landscape_pair', 'n' => 2, 'want' => 'landscape'],
                ['type' => 'image_text', 'n' => 1, 'want' => 'landscape', 'heading' => 'The second town', 'body' => 'Two buses a day in winter. The harbour is a twenty minute walk from the stop, which is why there are eleven frames of the walk and four of the harbour.'],
                ['type' => 'three_image_grid', 'n' => 3, 'want' => 'landscape'],
                ['type' => 'full_width_image', 'n' => 1, 'want' => 'landscape', 'caption' => 'Waiting to leave, third town.'],
                ['type' => 'portrait_pair', 'n' => 2, 'want' => 'portrait'],
                ['type' => 'gallery_strip', 'n' => 5, 'want' => 'any'],
                ['type' => 'credits', 'n' => 0, 'heading' => 'Made with'],
            ],
        ],

        // ---- 8. Landscape again, but a different shape entirely -----------
        'field-notes' => [
            'title' => 'Field Notes',
            'subtitle' => 'Inland, away from the coast, for once',
            'excerpt' => 'Fourteen frames made on foot across one valley in a single autumn, kept as a set because none of them works alone.',
            'description' => "An exercise rather than a commission: to photograph somewhere with no edge to it, after four years of shorelines.\n\nThe difficulty is that a valley gives you nothing to put at the side of the frame.",
            'year' => 2023, 'location' => 'Cumbria', 'client' => 'Self-initiated',
            'categories' => ['landscape'],
            'set' => 'landscape',
            'score' => [
                ['type' => 'full_width_image', 'n' => 1, 'want' => 'landscape'],
                ['type' => 'quote', 'n' => 0, 'quote' => 'Four years of coastline teaches you to compose against an edge. Take the edge away and you find out how much of it was the sea doing the work.', 'attribution' => 'Working note, first week'],
                ['type' => 'landscape_pair', 'n' => 2, 'want' => 'landscape'],
                ['type' => 'contained_image', 'n' => 1, 'want' => 'any'],
                ['type' => 'text_image', 'n' => 1, 'want' => 'landscape', 'heading' => 'No edge to it', 'body' => 'Nothing in a valley ends. The pictures that work are the ones that stopped trying to find a boundary and used the weather as one instead.'],
                ['type' => 'three_image_grid', 'n' => 3, 'want' => 'landscape'],
                ['type' => 'landscape_pair', 'n' => 2, 'want' => 'landscape'],
                ['type' => 'three_image_grid', 'n' => 3, 'want' => 'any', 'caption' => 'Ground, three times.'],
                ['type' => 'full_width_image', 'n' => 1, 'want' => 'landscape'],
                ['type' => 'credits', 'n' => 0, 'heading' => 'Made with'],
            ],
        ],

        // ---- 9. THE SHORT ONE --------------------------------------------
        'three-rooms' => [
            'title' => 'Three Rooms',
            'subtitle' => 'A single afternoon, three frames kept',
            'excerpt' => 'Everything that survived the edit from one commission. Three pictures is a complete series when three pictures is what there is.',
            'description' => "Included at full size and without apology.\n\nMost studio sites pad a short job out to twelve frames with variants of the same picture. This is what the job actually was.",
            'year' => 2025, 'location' => 'Edinburgh', 'client' => 'The Gentlewoman',
            'categories' => ['editorial', 'architecture'],
            'set' => 'editorial',
            'score' => [
                ['type' => 'full_width_image', 'n' => 1, 'want' => 'landscape', 'caption' => 'First room, one exposure.'],
                ['type' => 'text_image', 'n' => 1, 'want' => 'any', 'heading' => 'Three is the series', 'body' => 'The afternoon produced eleven frames. Three of them are these. Padding the other eight back in would make a longer page and a worse set.'],
                ['type' => 'contained_image', 'n' => 1, 'want' => 'any', 'caption' => 'Third room, last light.'],
                ['type' => 'credits', 'n' => 0, 'heading' => 'Made with'],
            ],
        ],
    ];

    /**
     * Media already handed out in this run, so two series never share a frame.
     *
     * @var list<int>
     */
    private array $spent = [];

    public function run(): void
    {
        $categories = Category::query()->pluck('id', 'slug');

        $order = 0;

        foreach (self::SCORES as $slug => $score) {
            $this->buildStory($slug, $score, $categories, $order++);
        }

        $this->markInheritedDemoWork();
        $this->chooseFeatured();
    }

    /**
     * Earlier seeders left projects behind that were never flagged.
     *
     * A project built entirely out of is_demo photographs is demonstration
     * content whatever seeded it, and the admin has no other way to tell. The
     * inference is deliberately strict — EVERY frame must be demo — so a real
     * series that happens to borrow one stock image is never mislabelled.
     */
    private function markInheritedDemoWork(): void
    {
        $projects = Project::query()
            ->where('is_demo', false)
            ->with('cover')
            ->get();

        foreach ($projects as $project) {
            $ids = DB::table('media_usages')
                ->leftJoin('project_blocks', function ($join): void {
                    $join->on('project_blocks.id', '=', 'media_usages.usable_id')
                        ->where('media_usages.usable_type', '=', ProjectBlock::class);
                })
                ->where(function ($q) use ($project): void {
                    $q->where(function ($inner) use ($project): void {
                        $inner->where('media_usages.usable_type', Project::class)
                            ->where('media_usages.usable_id', $project->id);
                    })->orWhere('project_blocks.project_id', $project->id);
                })
                ->pluck('media_usages.media_id')
                ->unique();

            if ($ids->isEmpty()) {
                continue;
            }

            $real = Media::query()->whereIn('id', $ids)->where('is_demo', false)->exists();

            if (! $real) {
                $project->forceFill(['is_demo' => true])->save();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $score
     * @param  Collection<string, int>  $categories
     */
    private function buildStory(string $slug, array $score, Collection $categories, int $order): void
    {
        $project = Project::withTrashed()->firstOrNew(['slug' => $slug]);

        /*
         * forceFill rather than fill: slug and status are outside $fillable on
         * Project by design, so that no mass assignment anywhere can move a
         * project's public address or publish a draft. A seeder saying both
         * explicitly is the intended way past that guard.
         */
        $project->forceFill([
            'slug' => $slug,
            'title' => $score['title'],
            'subtitle' => $score['subtitle'],
            'excerpt' => $score['excerpt'],
            'description' => $score['description'],
            'year' => $score['year'],
            'location' => $score['location'],
            'client' => $score['client'],
            'status' => PublishStatus::Published,
            'is_demo' => true,
            'sort_order' => $order,
        ]);

        $project->published_at ??= now()->subDays(30 - $order);
        $project->deleted_at = null;
        $project->save();

        $project->categories()->sync(
            collect($score['categories'])->map(fn (string $s): ?int => $categories[$s] ?? null)->filter()->all()
        );

        $pool = $this->poolFor($score['set']);

        /*
         * Blocks are rebuilt from the score every run rather than diffed.
         * Deleting them first also clears their media_usages through the
         * ProjectBlock::deleting hook, so no usage row is ever orphaned —
         * which is the only way this stays idempotent without leaking.
         */
        $project->blocks()->get()->each->delete();

        $first = null;
        $position = 0;

        foreach ($score['score'] as $spec) {
            $media = $this->take($pool, (int) $spec['n'], (string) ($spec['want'] ?? 'any'));

            $first ??= $media[0] ?? null;

            $block = ProjectBlock::create([
                'project_id' => $project->id,
                'type' => BlockType::from($spec['type']),
                'sort_order' => $position++,
                'data' => array_filter([
                    'caption' => $spec['caption'] ?? null,
                    'heading' => $spec['heading'] ?? null,
                    'body' => $spec['body'] ?? null,
                    'quote' => $spec['quote'] ?? null,
                    'attribution' => $spec['attribution'] ?? null,
                    'height' => $spec['height'] ?? null,
                ], static fn ($v): bool => $v !== null),
            ]);

            foreach ($media as $index => $item) {
                // Through media_usages, never an id inside data.
                $block->mediaUsages()->create([
                    'media_id' => $item->id,
                    'role' => \App\Actions\Projects\SyncBlockMedia::ROLE,
                    'sort_order' => $index,
                ]);
            }
        }

        /*
         * The cover is the story's own first frame, which is what a printed
         * contact sheet would give you and what makes the index read as the
         * work rather than as a set of unrelated thumbnails.
         */
        if ($first !== null) {
            $project->forceFill([
                'cover_media_id' => $first->id,
                'hero_media_id' => $first->id,
            ])->save();
        }

        app(SyncProjectMedia::class)($project);
    }

    /**
     * Frames available to a series, its own set first.
     *
     * @return Collection<int, Media>
     */
    private function poolFor(string $set): Collection
    {
        $own = Media::query()
            ->where('status', MediaStatus::Ready)
            // Redundant alongside demo_set in practice, and stated anyway: the
            // rule is "a seeder draws from demo media", not "a seeder draws
            // from rows that happen to carry a set name".
            ->where('is_demo', true)
            ->where('demo_set', $set)
            ->whereNotNull('width')
            ->whereNotIn('id', $this->spent)
            ->orderBy('id')
            ->get();

        /*
         * Anything left over, for a set the fetch under-filled — but DEMO
         * media only.
         *
         * Without that condition the fallback reaches into the client's real
         * library. It already did: three synthetic test images generated in an
         * early task, with no demo_set, were pulled into a gallery strip and
         * published, and turned up as the first three frames of the public
         * archive. A seeder may borrow from its own content and nothing else.
         */
        $rest = Media::query()
            ->where('status', MediaStatus::Ready)
            ->where('is_demo', true)
            ->whereNotNull('width')
            ->whereNotIn('id', array_merge($this->spent, $own->pluck('id')->all()))
            ->orderBy('id')
            ->get();

        return $own->concat($rest);
    }

    /**
     * Take frames of a wanted orientation, in id order so a re-run allocates
     * identically. Falls back to any shape rather than returning short: a
     * block with one of its three images missing is worse than a block whose
     * three images are not all the same shape.
     *
     * @param  Collection<int, Media>  $pool
     * @return list<Media>
     */
    private function take(Collection &$pool, int $count, string $want): array
    {
        if ($count === 0) {
            return [];
        }

        $taken = [];

        foreach ([$want, 'any'] as $pass) {
            foreach ($pool as $key => $item) {
                if (count($taken) >= $count) {
                    break 2;
                }

                if ($pass !== 'any' && $this->orientationOf($item) !== $pass) {
                    continue;
                }

                $taken[] = $item;
                $this->spent[] = $item->id;
                $pool->forget($key);
            }
        }

        return $taken;
    }

    private function orientationOf(Media $media): string
    {
        if (! $media->width || ! $media->height) {
            return 'landscape';
        }

        $ratio = $media->width / $media->height;

        return match (true) {
            $ratio > 1.05 => 'landscape',
            $ratio < 0.95 => 'portrait',
            default => 'square',
        };
    }

    /**
     * Feature the story whose cover can actually colour the page.
     *
     * The hero derives its accent from the featured cover. A monochrome or
     * near-neutral frame is a perfectly good photograph and a perfectly bad
     * choice here: it yields no hue, the page falls back to the token accent,
     * and the one thing the composition is built around stops happening.
     *
     * So the flag goes to the first series in order whose cover survives
     * AccentPalette AND AccentColor in BOTH registers. Monochrome frames stay
     * everywhere else — in the stories, in the archive, as ordinary covers.
     */
    private function chooseFeatured(): void
    {
        /*
         * Cleared on EVERY project, not only the demo ones.
         *
         * The home page takes the first featured project it finds, so two
         * flagged projects is not "two features" — it is an ambiguous page
         * that changes with sort order. Leaving an older seeder's flag in
         * place is exactly how that happened.
         */
        Project::query()->where('is_featured', true)->update(['is_featured' => false]);

        $candidates = Project::query()
            ->where('is_demo', true)
            ->published()
            ->with('cover')
            ->orderBy('sort_order')
            ->get();

        foreach ($candidates as $project) {
            $cover = $project->cover;

            if ($cover === null) {
                continue;
            }

            $source = AccentPalette::select($cover->palette);

            if ($source === null) {
                continue;   // no hue in the frame: correct, and not for the hero
            }

            if (AccentColor::derive($source, '#0b0b0c') === null
                || AccentColor::derive($source, '#f4f1ea') === null) {
                continue;   // a hue that cannot be made readable is no better
            }

            $project->forceFill(['is_featured' => true])->save();

            return;
        }
    }

    /**
     * The slugs this seeder owns, for tests and for a targeted purge.
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::SCORES);
    }
}
