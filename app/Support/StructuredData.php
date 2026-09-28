<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Article;

/**
 * schema.org JSON-LD, built from the model the page is rendering.
 *
 * THE ONLY RULE HERE IS TRUTHFULNESS.
 *
 * Structured data is the one part of a page written for a machine, which is
 * exactly why it is where sites lie: a fabricated author, a wordCount nobody
 * counted, a datePublished set to today on every crawl to look fresh. Search
 * engines treat one wrong field as a reason to distrust the rest, and they are
 * right to.
 *
 * So every value below is read from the same record the template renders, and
 * anything the record does not have is OMITTED rather than filled in. An
 * absent author is better than an invented one.
 */
final class StructuredData
{
    /**
     * @return array<string, mixed>
     */
    public static function blogPosting(Article $article): array
    {
        $image = $article->featured;

        $data = [
            '@context' => 'https://schema.org',
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
            // Absolute, because a consumer of this has no page to resolve a
            // relative path against — the same reasoning as og:image.
            $data['image'] = [AbsoluteUrl::for($image->url())];
        }

        if ($article->category !== null) {
            $data['articleSection'] = $article->category->name;
        }

        $keywords = $article->tags->pluck('name')->all();

        if ($keywords !== []) {
            $data['keywords'] = implode(', ', $keywords);
        }

        // Counted from the rendered text, not estimated from the source.
        $data['wordCount'] = str_word_count(RichText::toText($article->body_html));

        return array_filter($data, static fn ($value): bool => $value !== null && $value !== []);
    }
}
