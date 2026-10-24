<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Projects\SyncBlockMedia;
use App\Actions\Projects\SyncProjectMedia;
use App\Enums\BlockType;
use App\Enums\MediaStatus;
use App\Enums\PublishStatus;
use App\Models\Category;
use App\Models\Client;
use App\Models\Media;
use App\Models\Project;
use Database\Seeders\Concerns\ProtectsEditedContent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Enough real content to judge the homepage by.
 *
 * Idempotent: everything is keyed on slug and updated in place, so running it
 * twice changes nothing. It uses whatever processed media the dev database
 * already holds and cycles through it — there is no point generating more
 * images when the encoder has already produced some.
 */
class DemoContentSeeder extends Seeder
{
    use Concerns\SeedsDemoContent;
    use ProtectsEditedContent;

    public function run(): void
    {
        if ($this->refusesDemoContent()) {
            return;
        }

        /*
         * DEMO MEDIA ONLY.
         *
         * This is where the original leak was. Without the is_demo condition
         * a seeder reaches into whatever is in the library — and it did:
         * three synthetic test images generated during development were
         * picked up here, attached to published projects, and became the
         * first three frames of the public archive.
         *
         * A seeder may borrow from its own content and nothing else.
         */
        $media = Media::query()
            ->where('status', MediaStatus::Ready)
            ->where('is_demo', true)
            ->with('variants')
            ->orderBy('id')
            ->get();

        if ($media->isEmpty()) {
            $this->command?->warn(
                'No processed media found. Upload an image and run the media queue first — '
                .'the demo content needs real photographs to be worth looking at.'
            );

            return;
        }

        /*
         * Demo images arrive from the encoder with no alt text, which leaves
         * the hero — the most important image on the site — announced as
         * nothing. Describe anything undescribed, so the page can be judged
         * as it would actually ship.
         */
        $media->whereNull('alt')->each(function (Media $item, int $index): void {
            $item->update(['alt' => [
                'A winter shoreline under flat northern light',
                'Empty streets before sunrise, long shadows',
                'A single lamp falling across a studio wall',
                'Rusted gantries above a disused slipway',
                'A cut field in late afternoon',
                'A desk covered in papers beside a window',
            ][$index % 6]]);
        });

        $categories = collect([
            'Editorial' => 'Commissioned work for publications and brands.',
            'Portrait' => 'People, made slowly.',
            'Landscape' => 'Place and weather.',
            'Documentary' => 'Long-form reportage.',
        ])->map(fn (string $description, string $name): Category => $this->createIfMissing(
            Category::class,
            ['slug' => Str::slug($name)],
            ['name' => $name, 'description' => $description, 'sort_order' => 0]
        ));
        // createIfMissing, not updateOrCreate: categories carry no is_demo
        // flag, so there is no way to tell a seeded one from one the studio
        // has since renamed. Never touching an existing row is the only
        // answer that cannot destroy an edit.

        /*
         * Covers are chosen by shape, not by whatever came back first. The
         * layout planner branches on orientation and on what preceded it, so a
         * set of eight identical landscapes exercises exactly one of its
         * paths. This assigns a deliberate sequence — landscape, landscape,
         * portrait, portrait, portrait, square, landscape, portrait — which
         * walks every branch including the three-portrait fence break.
         */
        $byShape = $media->groupBy(function (Media $item): string {
            $w = (int) $item->width;
            $h = (int) $item->height;

            if ($h === 0) {
                return 'landscape';
            }

            return match (true) {
                $w > $h * 1.05 => 'landscape',
                $w < $h * 0.95 => 'portrait',
                default => 'square',
            };
        });

        $shapeSequence = ['landscape', 'landscape', 'portrait', 'portrait', 'portrait', 'square', 'landscape', 'portrait'];
        $shapeCursor = [];

        $coverFor = function (int $index) use ($byShape, $shapeSequence, &$shapeCursor, $media): Media {
            $wanted = $shapeSequence[$index % count($shapeSequence)];
            $pool = $byShape->get($wanted) ?? collect();

            if ($pool->isEmpty()) {
                $pool = $media;   // degrade rather than fail on a thin library
            }

            $shapeCursor[$wanted] = ($shapeCursor[$wanted] ?? -1) + 1;

            return $pool[$shapeCursor[$wanted] % $pool->count()];
        };

        $definitions = [
            [
                'slug' => 'coastline-in-winter',
                'title' => 'Coastline in Winter',
                'subtitle' => 'Six weeks on the northern shore',
                'excerpt' => 'A winter spent following one stretch of coast until the weather '
                    .'stopped being scenery and started being the subject.',
                'year' => 2026, 'location' => 'Northumberland', 'client' => 'Self-initiated',
                'categories' => ['Landscape', 'Documentary'],
                'featured' => true,
            ],
            [
                'slug' => 'the-quiet-hours',
                'title' => 'The Quiet Hours',
                'subtitle' => 'Before the city wakes',
                'excerpt' => 'Made between four and seven in the morning, when the light is '
                    .'doing most of the work and nobody is watching.',
                'year' => 2025, 'location' => 'Lisbon', 'client' => 'Kinfolk',
                'categories' => ['Editorial'],
            ],
            [
                'slug' => 'studio-portraits',
                'title' => 'Studio Portraits',
                'subtitle' => 'One light, one chair',
                'excerpt' => 'A year of sittings with a single lamp and no retouching.',
                'year' => 2025, 'location' => 'London', 'client' => 'Various',
                'categories' => ['Portrait'],
            ],
            [
                'slug' => 'salt-and-iron',
                'title' => 'Salt and Iron',
                'subtitle' => 'The last working yards',
                'excerpt' => 'Shipbuilding towns photographed across two winters, as the yards '
                    .'that named them closed one by one.',
                'year' => 2024, 'location' => 'Clydeside', 'client' => 'The Gentlewoman',
                'categories' => ['Documentary', 'Landscape'],
            ],
            [
                'slug' => 'after-the-harvest',
                'title' => 'After the Harvest',
                'subtitle' => 'Fields in their off season',
                'excerpt' => 'What is left in a field once the work is finished and the light '
                    .'has gone flat.',
                'year' => 2024, 'location' => 'Andalusia', 'client' => 'Self-initiated',
                'categories' => ['Landscape'],
            ],
            [
                'slug' => 'a-room-of-ones-own',
                'title' => 'A Room of One&rsquo;s Own',
                'subtitle' => 'Writers at their desks',
                'excerpt' => 'Twelve writers, photographed where they work, with nothing moved.',
                'year' => 2023, 'location' => 'Various', 'client' => 'Granta',
                'categories' => ['Portrait', 'Editorial'],
            ],
            [
                'slug' => 'concrete-and-light',
                'title' => 'Concrete and Light',
                'subtitle' => 'Brutalist interiors at midday',
                'excerpt' => 'Buildings photographed only in the two hours when the light '
                    .'reaches the bottom of the stairwells.',
                'year' => 2023, 'location' => 'Marseille', 'client' => 'Self-initiated',
                'categories' => ['Landscape', 'Documentary'],
            ],
            [
                'slug' => 'the-long-table',
                'title' => 'The Long Table',
                'subtitle' => 'Objects, arranged and left',
                'excerpt' => 'A still life made once a week for a year, always on the same '
                    .'table, always in the same window.',
                'year' => 2022, 'location' => 'Studio', 'client' => 'Self-initiated',
                'categories' => ['Editorial'],
            ],
        ];

        foreach ($definitions as $index => $definition) {
            $project = Project::withTrashed()->firstOrNew(['slug' => $definition['slug']]);

            /*
             * Refuse to touch a project the studio has taken over or thrown
             * away. Previously this filled and saved unconditionally AND set
             * deleted_at to null, so every run reverted any edit to a demo
             * project and un-binned one that had been deleted on purpose.
             */
            if (! $this->mayOverwrite($project)) {
                continue;
            }

            $project->fill([
                'title' => html_entity_decode($definition['title']),
                'subtitle' => $definition['subtitle'],
                'excerpt' => $definition['excerpt'],
                'year' => $definition['year'],
                'location' => $definition['location'],
                'client' => $definition['client'],
                'is_featured' => $definition['featured'] ?? false,
                'sort_order' => $index,
                'cover_media_id' => $coverFor($index)->id,
                'hero_media_id' => $media[($index + 3) % $media->count()]->id,
            ]);

            $project->slug = $definition['slug'];
            $project->status = PublishStatus::Published;
            $project->published_at ??= now()->subDays(($index + 1) * 9);
            $project->is_demo = true;
            $project->save();

            $project->categories()->sync(
                $categories->only($definition['categories'])->pluck('id')->all()
            );

            app(SyncProjectMedia::class)($project);

            $this->seedStory($project, $media);
        }

        /*
         * NO DEMO CLIENTS. There were nine, shown on the homepage and the about
         * page under "Selected clients" — invented names plus one real
         * magazine. A client list is a claim about the business, so the owner
         * decided (22 Oct 2026) it is never fabricated, even locally. Clients
         * are added in Studio → Clients, and the sections hide while there are
         * none.
         */

        /*
         * Two that must never reach the homepage, so the filtering is
         * visibly doing something.
         *
         * Created only. These were updateOrCreate, which forced the status
         * back to Draft on every run — so a studio that published the project
         * sitting on this slug would find it unpublished by the next deploy.
         */
        $inProgress = $this->createIfMissing(
            Project::class,
            ['slug' => 'work-in-progress'],
            ['title' => 'Work in Progress', 'sort_order' => 90, 'is_demo' => true]
        );

        if ($inProgress->wasRecentlyCreated) {
            $inProgress->forceFill(['status' => PublishStatus::Draft, 'published_at' => null])->save();
        }

        $retired = $this->createIfMissing(
            Project::class,
            ['slug' => 'retired-series'],
            ['title' => 'Retired Series', 'sort_order' => 91, 'is_demo' => true]
        );

        if ($retired->wasRecentlyCreated) {
            $retired->forceFill(['status' => PublishStatus::Archived, 'published_at' => now()->subYear()])->save();
        }

        $this->command?->info(sprintf(
            'Demo content ready: %d published projects, %d categories, using %d processed images.',
            count($definitions),
            $categories->count(),
            $media->count()
        ));
    }

    /**
     * A short story for each project, so the project pages are worth opening.
     *
     * @param  Collection<int, Media>  $media
     */
    private function seedStory(Project $project, $media): void
    {
        if ($project->blocks()->exists()) {
            return;   // idempotent: never duplicate a story
        }

        $pick = fn (int $offset): Media => $media[($project->id + $offset) % $media->count()];

        $opening = $project->blocks()->create([
            'type' => BlockType::FullWidthImage,
            'sort_order' => 0,
            'data' => ['caption' => $project->location.', '.$project->year],
        ]);
        app(SyncBlockMedia::class)($opening, [$pick(0)->id]);

        $project->blocks()->create([
            'type' => BlockType::FullWidthText,
            'sort_order' => 1,
            'data' => ['heading' => 'On making this', 'body' => $project->excerpt],
        ]);

        $pair = $project->blocks()->create([
            'type' => BlockType::LandscapePair,
            'sort_order' => 2,
            'data' => ['caption' => 'Two frames from the same morning.'],
        ]);
        app(SyncBlockMedia::class)($pair, [$pick(1)->id, $pick(2)->id]);

        $project->blocks()->create([
            'type' => BlockType::Quote,
            'sort_order' => 3,
            'data' => [
                'quote' => 'You wait, and then you stop waiting, and that is usually when it happens.',
                'attribution' => 'Notes from the shoot',
            ],
        ]);

        $project->credits()->createMany([
            ['role' => 'Photography', 'name' => 'The Studio', 'sort_order' => 0],
            ['role' => 'Production', 'name' => 'Field Office', 'sort_order' => 1],
        ]);
    }
}
