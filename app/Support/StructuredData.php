<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Article;
use App\Models\Media;
use App\Models\Project;
use App\Models\Service;

/**
 * schema.org JSON-LD, built from the records the page is rendering.
 *
 * THE ONLY RULE HERE IS TRUTHFULNESS.
 *
 * Structured data is the one part of a page written for a machine, which is
 * exactly why it is where sites lie: a fabricated author, a wordCount nobody
 * counted, an aggregateRating with no reviews behind it, a datePublished set
 * to today on every crawl to look fresh. Search engines treat one wrong field
 * as a reason to distrust the rest, and they are right to.
 *
 * So every value below is read from the same record the template renders, and
 * anything the record does not hold is OMITTED rather than filled in. There is
 * no default, no placeholder and no "" anywhere in this file. The final
 * array_filter in each builder is the last line of that defence.
 *
 * WHAT IS DELIBERATELY ABSENT
 *
 *   - aggregateRating / review — the platform holds no reviews. This is the
 *     single most commonly faked block on the web and it is worth naming.
 *   - priceRange on the organisation — a studio-wide price band is a guess.
 *     A Service that HOLDS a starting price states that price, and one that
 *     does not says nothing.
 *   - openingHours, telephone, address — not held by the settings table.
 *     A location string like "Northumberland, UK" is an areaServed, not a
 *     postal address, and emitting it as one would be an invented address.
 */
final class StructuredData
{
    public const CONTEXT = 'https://schema.org';

    /** See breadcrumbs(). */
    public const MIN_BREADCRUMBS = 3;

    // ------------------------------------------------------------------ site

    /**
     * The studio itself, emitted on every page.
     *
     * ProfessionalService rather than Person: the entity that takes
     * commissions is the studio, and the settings table describes a practice —
     * a name, a location, a set of disciplines — rather than a human being.
     * The photographer appears as the `founder`, which is the honest
     * relationship and is the field that carries a person's name.
     *
     * @return array<string, mixed>
     */
    public static function studio(): array
    {
        $name = Settings::get('site.name');

        if (blank($name)) {
            return [];
        }

        $data = [
            '@context' => self::CONTEXT,
            '@type' => 'ProfessionalService',
            '@id' => url('/').'#studio',
            'name' => $name,
            'url' => url('/'),
        ];

        if (filled($statement = Settings::get('site.statement'))) {
            $data['description'] = $statement;
        }

        /*
         * areaServed, not address. "Northumberland, UK" is where the studio
         * works, which is a real claim; a PostalAddress would assert a street
         * and a postcode the platform does not hold.
         */
        if (filled($location = Settings::get('site.location'))) {
            $data['areaServed'] = ['@type' => 'Place', 'name' => $location];
        }

        if (filled($founder = Settings::get('attribution.name'))) {
            $person = ['@type' => 'Person', 'name' => $founder];

            if (filled($role = Settings::get('attribution.role'))) {
                $person['jobTitle'] = $role;
            }

            $data['founder'] = $person;
        }

        /*
         * The disciplines string is a human sentence — "Portrait — Editorial —
         * Archive" — so it is split back into the list schema.org expects
         * rather than emitted as one run-on knowsAbout value.
         */
        if (filled($disciplines = Settings::get('site.disciplines'))) {
            $parts = array_values(array_filter(array_map(
                'trim',
                preg_split('/[—–\-\/,|]+/u', (string) $disciplines) ?: []
            )));

            if ($parts !== []) {
                $data['knowsAbout'] = $parts;
            }
        }

        if (filled($email = Settings::get('contact.recipient'))) {
            $data['email'] = $email;
        }

        return self::prune($data);
    }

    /**
     * The site as a thing, with its search-free identity.
     *
     * No SearchAction: the site has no search endpoint, and declaring one that
     * does not exist is the most common way this block is wrong.
     *
     * @return array<string, mixed>
     */
    public static function website(): array
    {
        $name = Settings::get('site.name');

        if (blank($name)) {
            return [];
        }

        $data = [
            '@context' => self::CONTEXT,
            '@type' => 'WebSite',
            '@id' => url('/').'#website',
            'name' => $name,
            'url' => url('/'),
            'inLanguage' => config('seo.locale'),
            'publisher' => ['@id' => url('/').'#studio'],
        ];

        return self::prune($data);
    }

    // ----------------------------------------------------------- breadcrumbs

    /**
     * A breadcrumb trail, only where a real hierarchy exists.
     *
     * THREE entries or nothing.
     *
     * Two was the bar in Task 25 and it was too low: "Home → Services" has one
     * real ancestor, and the rich result it produces is the site name followed
     * by the page title — which a reader already has from the nav and the
     * heading. A trail earns its place when there is a step BETWEEN the root
     * and the page, because that step is the thing a reader cannot otherwise
     * see.
     *
     * @param  list<array{name: string, url: string}>  $trail
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $trail): array
    {
        $trail = array_values(array_filter(
            $trail,
            static fn (array $crumb): bool => filled($crumb['name'] ?? null) && filled($crumb['url'] ?? null)
        ));

        if (count($trail) < self::MIN_BREADCRUMBS) {
            return [];
        }

        return [
            '@context' => self::CONTEXT,
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(
                static fn (int $index, array $crumb): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $crumb['name'],
                    'item' => $crumb['url'],
                ],
                array_keys($trail),
                $trail,
            ),
        ];
    }

    // --------------------------------------------------------------- project

    /**
     * A project as a CreativeWork, with its photographs as ImageObjects.
     *
     * The images are the point on a photography platform, and each one carries
     * the credit and copyright held on the media record — not a site-wide
     * default, and not the studio's name applied to a photograph somebody else
     * took. A frame with no credit stored emits no creditText.
     *
     * @return array<string, mixed>
     */
    public static function project(Project $project): array
    {
        $data = [
            '@context' => self::CONTEXT,
            '@type' => 'CreativeWork',
            'name' => $project->title,
            'url' => route('projects.show', $project),
            'isAccessibleForFree' => true,
            'publisher' => ['@id' => url('/').'#studio'],
        ];

        if (filled($description = $project->excerpt ?: $project->subtitle)) {
            $data['description'] = $description;
        }

        if ($project->published_at !== null) {
            $data['datePublished'] = $project->published_at->toIso8601String();
        }

        if ($project->updated_at !== null
            && $project->published_at !== null
            && $project->updated_at->greaterThan($project->published_at)) {
            $data['dateModified'] = $project->updated_at->toIso8601String();
        }

        /*
         * The year the work was MADE, which is a different fact from when the
         * page was published and is the one a reader cares about.
         */
        if ($project->year !== null) {
            $data['copyrightYear'] = $project->year;
        }

        if (filled($project->location)) {
            $data['locationCreated'] = ['@type' => 'Place', 'name' => $project->location];
        }

        /*
         * The commissioning client, when there is one. "Self-initiated" is a
         * real answer in the data and is deliberately NOT emitted as a
         * sponsor — there was no sponsor.
         */
        if (filled($project->client) && mb_strtolower((string) $project->client) !== 'self-initiated') {
            $data['sponsor'] = ['@type' => 'Organization', 'name' => $project->client];
        }

        if ($project->relationLoaded('categories') && $project->categories->isNotEmpty()) {
            $data['genre'] = $project->categories->pluck('name')->values()->all();
        }

        $images = self::imagesFor($project);

        if ($images !== []) {
            $data['image'] = $images;
        }

        if ($project->relationLoaded('credits') && $project->credits->isNotEmpty()) {
            $data['contributor'] = $project->credits
                ->map(static fn ($credit): array => array_filter([
                    '@type' => 'Person',
                    'name' => $credit->name,
                    'jobTitle' => $credit->role ?: null,
                ]))
                ->values()
                ->all();
        }

        return self::prune($data);
    }

    /**
     * Every photograph a project actually shows, as ImageObject.
     *
     * @return list<array<string, mixed>>
     */
    private static function imagesFor(Project $project): array
    {
        $media = collect();

        if ($project->relationLoaded('cover') && $project->cover !== null) {
            $media->push($project->cover);
        }

        if ($project->relationLoaded('blocks')) {
            foreach ($project->blocks as $block) {
                if (! $block->relationLoaded('blockMedia')) {
                    continue;
                }

                foreach ($block->blockMedia as $blockMedium) {
                    if ($blockMedium->relationLoaded('media') && $blockMedium->media !== null) {
                        $media->push($blockMedium->media);
                    }
                }
            }
        }

        return $media
            ->filter(static fn (?Media $item): bool => $item !== null)
            ->unique('id')
            // A frame with no rendition has no URL to point at.
            ->map(static fn (Media $item): array => self::image($item))
            ->filter(static fn (array $image): bool => $image !== [])
            ->values()
            ->all();
    }

    /**
     * One photograph, with only the facts the media record holds.
     *
     * @return array<string, mixed>
     */
    public static function image(Media $media): array
    {
        $url = AbsoluteUrl::for($media->url());

        if ($url === null) {
            return [];
        }

        $data = [
            '@type' => 'ImageObject',
            'contentUrl' => $url,
        ];

        if (filled($media->alt)) {
            // The alt is a description of the picture, which is exactly what
            // schema.org means by description here.
            $data['description'] = $media->alt;
        }

        if (filled($media->caption)) {
            $data['caption'] = $media->caption;
        }

        if (filled($media->credit)) {
            $data['creditText'] = $media->credit;
            $data['creator'] = ['@type' => 'Person', 'name' => $media->credit];
        }

        if (filled($media->copyright)) {
            $data['copyrightNotice'] = $media->copyright;
        }

        if ($media->width !== null && $media->height !== null) {
            $data['width'] = $media->width;
            $data['height'] = $media->height;
        }

        /*
         * The capture date, when the EXIF actually carried one. Never the
         * upload time dressed up as a shooting date.
         */
        if ($media->captured_at !== null) {
            $data['dateCreated'] = $media->captured_at->toIso8601String();
        }

        return $data;
    }

    // --------------------------------------------------------------- article

    /**
     * @return array<string, mixed>
     */
    public static function blogPosting(Article $article): array
    {
        $image = $article->featured;

        $data = [
            '@context' => self::CONTEXT,
            '@type' => 'BlogPosting',
            'headline' => $article->title,
            'url' => route('journal.show', $article),
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $article->canonical_url ?: route('journal.show', $article),
            ],
            'datePublished' => $article->published_at?->toIso8601String(),
            'isAccessibleForFree' => true,
        ];

        /*
         * Only when it differs from publication. dateModified equal to
         * datePublished on every article is noise; dateModified moving every
         * time a cache warms is a lie.
         */
        if ($article->updated_at !== null
            && $article->published_at !== null
            && $article->updated_at->greaterThan($article->published_at)) {
            $data['dateModified'] = $article->updated_at->toIso8601String();
        }

        if (($description = $article->summary()) !== null) {
            $data['description'] = $description;
        }

        /*
         * The byline the page actually shows. The template falls back from
         * the article's author to the studio's attribution, and this follows
         * the same order so the two can never disagree.
         */
        $author = $article->author?->name ?? Settings::get('attribution.name');

        if (filled($author)) {
            $data['author'] = ['@type' => 'Person', 'name' => $author];
        }

        if (filled($publisher = Settings::get('site.name'))) {
            $data['publisher'] = ['@type' => 'Organization', 'name' => $publisher];
        }

        if ($image !== null) {
            $data['image'] = [self::image($image)];
        }

        if ($article->category !== null) {
            $data['articleSection'] = $article->category->name;
        }

        $keywords = $article->relationLoaded('tags') ? $article->tags->pluck('name')->all() : [];

        if ($keywords !== []) {
            $data['keywords'] = implode(', ', $keywords);
        }

        // Counted from the rendered text, not estimated from the source.
        $data['wordCount'] = str_word_count(RichText::toText($article->body_html));

        return self::prune($data);
    }

    // --------------------------------------------------------------- service

    /**
     * A service the studio offers.
     *
     * NO aggregateRating, NO review: the platform holds neither, and a Service
     * block with invented stars is the single most penalised piece of
     * structured data on the web.
     *
     * The offer is emitted ONLY when a starting price is stored. A studio that
     * quotes on request gets a Service with no Offer, which is correct — the
     * alternative is inventing a number.
     *
     * @return array<string, mixed>
     */
    public static function service(Service $service): array
    {
        $data = [
            '@context' => self::CONTEXT,
            '@type' => 'Service',
            'name' => $service->name,
            'url' => route('services.show', $service),
            'provider' => ['@id' => url('/').'#studio'],
        ];

        if (filled($description = $service->summaryText())) {
            $data['description'] = $description;
        }

        if (filled($location = Settings::get('site.location'))) {
            $data['areaServed'] = ['@type' => 'Place', 'name' => $location];
        }

        if ($service->cover !== null && ($image = self::image($service->cover)) !== []) {
            $data['image'] = [$image];
        }

        /*
         * priceSpecification with a minPrice, not `price`.
         *
         * "From £1,200" is a floor, and `price` asserts an exact figure. The
         * distinction matters: a search engine showing "£1,200" for a job that
         * costs £4,000 is a promise the studio has to honour or explain.
         */
        if ($service->price_from_pence !== null) {
            $data['offers'] = array_filter([
                '@type' => 'Offer',
                'priceSpecification' => [
                    '@type' => 'PriceSpecification',
                    'minPrice' => round($service->price_from_pence / 100, 2),
                    'priceCurrency' => 'GBP',
                ],
                'description' => $service->price_note ?: null,
                'availability' => 'https://schema.org/InStock',
                'url' => route('contact.show'),
            ]);
        }

        return self::prune($data);
    }

    // ----------------------------------------------------------------- utils

    /**
     * Drop anything empty, at every depth.
     *
     * The last line of the no-invented-fields defence: a builder that assembles
     * a key from a value that turned out to be null must not emit
     * `"description": null` or `"image": []`, both of which are worse than the
     * key being absent.
     *
     * Zero and false are KEPT — `"wordCount": 0` and `"isAccessibleForFree":
     * false` are facts, not absences.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function prune(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = self::prune($value);
                $data[$key] = $value;
            }

            if ($value === null || $value === '' || $value === []) {
                unset($data[$key]);
            }
        }

        return $data;
    }
}
