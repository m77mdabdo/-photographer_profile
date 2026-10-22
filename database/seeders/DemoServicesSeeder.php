<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MediaStatus;
use App\Enums\PublishStatus;
use App\Models\Media;
use App\Models\Service;
use Illuminate\Database\Seeder;

/**
 * Five ways the studio works, written out rather than generated.
 *
 * WHY THE PRICES ARE UNEVEN AND ONE IS ABSENT
 *
 * A seeded price list of £500/£1000/£1500 tests nothing: it never exercises
 * the thousands separator, never the case where a price has pence, and never
 * the case a real studio actually has — the job that cannot be priced without
 * a conversation. So one service holds no price at all, and the page has to
 * cope with that rather than printing "from £0" or "POA".
 *
 * THE LIMIT: invented services for a demo studio. No real rate card, no real
 * client's terms.
 *
 * IDEMPOTENT and safe in the way Task 24 established — keyed on slug, and
 * anything the studio has edited or binned is left alone. See
 * Concerns\ProtectsEditedContent.
 */
class DemoServicesSeeder extends Seeder
{
    use Concerns\SeedsDemoContent;
    use Concerns\ProtectsEditedContent;

    public function run(): void
    {
        if ($this->refusesDemoContent()) {
            return;
        }

        /*
         * DEMO MEDIA ONLY. Without this a real client's photograph ends up
         * illustrating an invented rate card — the leak ArchiveTest exists to
         * catch, and which this seeder would otherwise reintroduce.
         */
        $media = Media::query()
            ->where('status', MediaStatus::Ready)
            ->where('is_demo', true)
            ->orderBy('id')
            ->get();

        if ($media->isEmpty()) {
            $this->command?->warn('No processed demo media. Services seed without images.');
        }

        foreach ($this->definitions() as $index => $definition) {
            $service = Service::withTrashed()->firstOrNew(['slug' => $definition['slug']]);

            if (! $this->mayOverwrite($service)) {
                continue;
            }

            $service->forceFill([
                'slug' => $definition['slug'],
                'name' => $definition['name'],
                'summary' => $definition['summary'],
                // description_html is written by the model's saving hook.
                'description' => $definition['description'],
                'price_from_pence' => $definition['price_from_pence'],
                'price_note' => $definition['price_note'],
                'cta_label' => $definition['cta_label'],
                'status' => PublishStatus::Published,
                'is_demo' => true,
                'sort_order' => $index,
                'cover_media_id' => $media->isNotEmpty()
                    ? $media[($index * 7 + 4) % $media->count()]->id
                    : null,
            ]);

            $service->published_at ??= now()->subDays(60 - $index);
            $service->save();

            // A small gallery, deterministic so re-running produces the same site.
            if ($media->isNotEmpty()) {
                $gallery = [];

                for ($n = 0; $n < 4; $n++) {
                    $frame = $media[($index * 7 + 9 + $n * 3) % $media->count()];
                    $gallery[$frame->id] = ['sort_order' => $n];
                }

                $service->gallery()->sync($gallery);
            }

            $this->syncUsages($service);
        }

        $this->command?->info(sprintf('Services ready: %d.', count($this->definitions())));
    }

    /**
     * Claim every image the service uses, so the media library refuses to
     * delete a photograph a published page is still showing.
     */
    private function syncUsages(Service $service): void
    {
        $service->mediaUsages()->delete();

        if ($service->cover_media_id !== null) {
            $service->mediaUsages()->create([
                'role' => 'cover',
                'media_id' => $service->cover_media_id,
                'sort_order' => 0,
            ]);
        }

        foreach ($service->gallery()->get() as $position => $frame) {
            $service->mediaUsages()->create([
                'role' => 'gallery',
                'media_id' => $frame->id,
                'sort_order' => $position + 1,
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            [
                'slug' => 'editorial-commissions',
                'name' => 'Editorial commissions',
                'summary' => 'Portraits and reportage for magazines, newspapers and long-form features, shot to a brief and delivered to a deadline.',
                'price_from_pence' => 120000,
                'price_note' => 'per day, plus expenses',
                'cta_label' => 'Discuss a feature',
                'description' => <<<'MD'
                    Most of this work is a day, occasionally two, built around somebody else's schedule. A subject who has half an hour, a location nobody has seen in advance, and a picture desk that needs a frame that will hold a cover line across the top third.

                    ## What a day looks like

                    I arrive earlier than the call time, because the thirty minutes before anybody is ready is when you find out where the light is. One camera, one lens, no assistant unless the job genuinely needs one — a second person in a small room changes how a subject behaves, and usually not for the better.

                    ## What you get

                    Between twelve and thirty frames, edited and finished, delivered as high-resolution files within five working days. Faster if the deadline needs it; say so at the start and it is built into the day rather than rushed at the end.

                    Every set includes at least one frame composed for a cover and one for a spread, because a picture editor should not have to ask.

                    ## What it costs

                    The day rate covers the shoot, the edit and the delivery. Travel, assistants, permits and any hired equipment are quoted separately and never marked up. Usage beyond the commissioning title is agreed before the shoot, not after.
                    MD,
            ],
            [
                'slug' => 'portrait-sittings',
                'name' => 'Portrait sittings',
                'summary' => 'An hour in the studio or somewhere that means something to you. Available light, few frames, printed properly.',
                'price_from_pence' => 45000,
                'price_note' => 'per sitting, including two prints',
                'cta_label' => 'Arrange a sitting',
                'description' => <<<'MD'
                    A sitting is an hour, and the first twenty minutes of it are conversation. That is not a warm-up — it is the part that decides whether the photographs are of a person or of somebody being photographed.

                    ## Where

                    The studio has one large north window and very little else in it, which is usually enough. If there is somewhere that means something to you — a workshop, a kitchen, a stretch of coast — that is almost always the better picture, and it costs no more within an hour's drive.

                    ## What you get

                    Eight to twelve finished frames, and two prints made by hand on fibre paper. The prints are the point. A photograph that only exists on a screen is not finished, and a file in a folder is not something anybody looks at twice.

                    Additional prints, and larger sizes, are quoted at cost plus time.
                    MD,
            ],
            [
                'slug' => 'archive-and-documentation',
                'name' => 'Archive and documentation',
                'summary' => 'Systematic photographic records of places, collections and processes, made to last and catalogued so they can be found.',
                'price_from_pence' => 95000,
                'price_note' => 'per day, catalogue included',
                'cta_label' => 'Talk about a record',
                'description' => <<<'MD'
                    This is the least glamorous work the studio does and the work most likely to matter in thirty years. A building before it is altered. A collection before it is dispersed. A process that three people still know how to do.

                    ## How it differs from a shoot

                    A commission produces a set. A record produces a *system*: consistent lighting, consistent distances, a written scheme of what was photographed and what was deliberately not, and file names somebody who has never met me can understand.

                    The photographs matter less individually and much more as a whole. A record with three beautiful frames and no coverage has failed.

                    ## What you get

                    Every file delivered twice — a working set and an archival set — with a catalogue in plain CSV rather than in whatever database happens to be fashionable. Originals are kept for twelve months and handed over on request at any point.

                    > A record that cannot be searched is a box of photographs, and a box of photographs is what people commission a record to avoid.
                    MD,
            ],
            [
                'slug' => 'prints-and-editions',
                'name' => 'Prints and editions',
                'summary' => 'Hand-made prints from the archive, and small editions for galleries and private collections.',
                'price_from_pence' => 18000,
                'price_note' => 'per print, unframed',
                'cta_label' => 'Ask about a print',
                'description' => <<<'MD'
                    Anything in the archive can be printed, and a good number of things that are not in it can be found.

                    ## How they are made

                    Fibre-based paper, selenium-toned, dried flat and left for a week before anybody decides whether it is finished. Digital work is printed on cotton rag with pigment inks. Either way the print is made one at a time and looked at in daylight before it is signed.

                    ## Sizes and editions

                    Standard sizes are 12×16 and 20×24 inches; anything larger is quoted. Editions are small — usually fifteen — numbered, and closed when they close. There is no "artist's proof" arrangement that quietly adds five more to the market.

                    ## Framing

                    Not offered, deliberately. A frame is a local decision about a wall and a room, and shipping glass three hundred miles to get it wrong is worse than recommending a framer near you.
                    MD,
            ],
            [
                'slug' => 'long-term-projects',
                'name' => 'Long-term projects',
                'summary' => 'Work made over seasons or years, for institutions, trusts and publishers. Scoped and quoted properly, because no two are alike.',
                // DELIBERATELY NO PRICE. A three-year commission cannot be
                // quoted from a rate card, and the page must hold without one.
                'price_from_pence' => null,
                'price_note' => null,
                'cta_label' => 'Start a conversation',
                'description' => <<<'MD'
                    The work the studio is actually for. A shoreline over three winters. A harbour through a redevelopment. A trade with four people left in it.

                    ## Why these are quoted and not priced

                    A day rate multiplied by an estimate is how long projects go wrong. The real variables are access, seasonality and how much of the outcome is decided in advance — and those are conversations, not line items.

                    What is fixed is the shape of the agreement: an agreed scope, a schedule with real dates, a payment structure tied to delivery rather than to time passing, and a written statement of who owns what at the end.

                    ## What has been made this way

                    Two harbour trusts wanted a record rather than a brochure and got one. A publisher wanted eighteen months of a single valley. Neither could have been bought off a list.

                    ## How to start

                    Describe the thing, not the job. What it is, why now, and who needs to see it afterwards. The scope comes out of that conversation rather than before it.
                    MD,
            ],
        ];
    }
}
