<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Turns an author's Markdown into the only HTML this application will render.
 *
 * WHY MARKDOWN AND NOT A WYSIWYG
 *
 * A rich text editor means shipping an editor to the browser — a hundred and
 * fifty kilobytes of JavaScript into an admin bundle that is currently
 * twenty-one — and then sanitising whatever HTML it produces anyway, because
 * the contents of a contenteditable are attacker-controlled the moment an
 * account is compromised or a draft is pasted from a web page. Markdown is
 * rich text without that: emphasis, links, headings, lists, quotes and
 * images, typed as text, stored as text, editable forever.
 *
 * TWO INDEPENDENT DEFENCES, and the second exists because the first is
 * configuration:
 *
 *   1. CommonMark with html_input: strip. Raw HTML in the source is removed
 *      rather than escaped or passed through, so <script>, <iframe>, <style>
 *      and every event handler attribute never become nodes at all.
 *
 *   2. An allowlist pass over the result. Anything the converter could emit
 *      that is not on the list is unwrapped, and every attribute not on the
 *      list is dropped. This is what catches a future config change, a
 *      CommonMark extension somebody enables, or an upstream default moving:
 *      the first defence is a setting, and a setting is one edit from being
 *      wrong.
 *
 * The output is written to `articles.body_html`, which nothing else ever
 * writes. The public template renders that column and never `body`, so
 * "never trust stored HTML" holds by construction rather than by discipline.
 */
final class RichText
{
    /**
     * Elements an article is allowed to contain.
     *
     * No <style>, no <iframe>, no <form>, no <object>. Images are allowed
     * because a journal about photographs needs them inline; their src is
     * checked like any other URL below.
     *
     * @var list<string>
     */
    private const ALLOWED_ELEMENTS = [
        'p', 'br', 'hr',
        'h2', 'h3', 'h4',
        'strong', 'em', 'del', 'sup', 'sub',
        'ul', 'ol', 'li',
        'blockquote',
        'a',
        'code', 'pre',
        'figure', 'figcaption', 'img',
    ];

    /**
     * Attributes allowed, per element. Anything else is removed, which
     * includes every on* handler by construction rather than by blocklist —
     * a blocklist of event handlers is a list that grows with the platform.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading', 'decoding'],
    ];

    /**
     * URL schemes a link or an image may use.
     *
     * `javascript:` is the obvious one. `data:` is here because a data URI can
     * carry an SVG, and an SVG is a document that can script.
     *
     * @var list<string>
     */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * The sanitised HTML for a Markdown source. Safe to render unescaped, and
     * safe only because it came from here.
     */
    /**
     * ───────────────────────────────────────────────────────────────────────
     * TASK 36b C — RENDER THE SANITISED HTML FOR EVERY LOCALE, NOT FOR
     * WHICHEVER ONE HAPPENED TO BE ACTIVE.
     *
     * The hook that calls this used to be one line:
     *
     *     if ($record->isDirty('<source>')) {
     *         $record-><source>_html = RichText::toHtml($record-><source>);
     *     }
     *
     * Both sides of that assignment go through the Translatable accessor and
     * mutator, which read and write THE CURRENT LOCALE. So a save made while
     * the application was in English rendered the English markdown and wrote
     * it into the English slot — whatever locale had actually changed.
     *
     * MEASURED, on a service with an Arabic description written by a seeder:
     * description_html had no ar value at all, and /ar/services/… rendered
     * the English body under an Arabic heading. The same hook exists on
     * Article::body_html and Page::body_html, so the journal and the legal
     * pages had it too.
     *
     * An editor writing Arabic in the panel hit exactly this: the Arabic they
     * typed was stored and never rendered. Nothing failed; the page simply
     * showed the other language.
     *
     * So the locales are walked. A source locale with markdown gets HTML; a
     * source locale that has been emptied has its HTML dropped with it, or
     * the page would keep rendering a body the editor deleted.
     * ───────────────────────────────────────────────────────────────────────
     *
     * @param  Model  $record
     */
    public static function syncHtmlLocales(object $record, string $source, string $target): void
    {
        if (! method_exists($record, 'translations') || ! method_exists($record, 'setTranslations')) {
            return;
        }

        $markdown = $record->translations($source);
        $html = [];

        foreach (Locale::codes() as $code) {
            $rendered = self::toHtml($markdown[$code] ?? null);

            if ($rendered !== null) {
                /*
                 * TASK 36b — a foreign name, acronym or address inside this
                 * locale's prose gets a bidi isolate, so it keeps its own
                 * direction inside a right-to-left body. Done HERE, per
                 * locale, because this is the one place that knows which
                 * locale the markup it just rendered belongs to; a template
                 * echoing body_html does not.
                 *
                 * The isolates live in the stored HTML, which is derived data
                 * — `php artisan content:rerender-html` rebuilds all of it
                 * from the markdown beside it.
                 */
                $html[$code] = Fallback::isolateForeignHtml($rendered, $code) ?? $rendered;
            }
        }

        $record->setTranslations($target, $html);
    }

    public static function toHtml(?string $markdown): ?string
    {
        $markdown = trim((string) $markdown);

        if ($markdown === '') {
            return null;
        }

        $html = Str::markdown($markdown, [
            // Defence 1.
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $clean = self::allowlist($html);

        return $clean === '' ? null : $clean;
    }

    /**
     * A plain-text reduction, for excerpts, meta descriptions and the reading
     * estimate. Tags are removed rather than escaped, and entities decoded, so
     * "&amp;" is one character rather than five.
     */
    public static function toText(?string $html): string
    {
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Whole minutes, at 200 words — the figure most reading-time estimates
     * use for prose. Always at least one: "0 min read" is not information.
     */
    public static function readingMinutes(?string $html): int
    {
        $words = str_word_count(self::toText($html));

        return max(1, (int) ceil($words / 200));
    }

    /**
     * Defence 2: walk the parsed document and keep only what is on the list.
     */
    private static function allowlist(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument;

        /*
         * libxml chatters about HTML5 elements it does not recognise. The
         * parse result is what matters, not its opinion of the input.
         */
        $previous = libxml_use_internal_errors(true);

        /*
         * The meta charset is how DOMDocument is told this is UTF-8; without
         * it, loadHTML assumes ISO-8859-1 and mangles every accented
         * character and every em dash in the article.
         */
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="richtext-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('richtext-root');

        if (! $root instanceof DOMElement) {
            return '';
        }

        self::clean($root);

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * Depth-first, and iterating over a STATIC copy of the child list.
     *
     * DOMNodeList is live: removing or replacing a node while iterating it
     * skips the next sibling, which in a sanitiser means silently letting
     * every other disallowed element through.
     */
    private static function clean(DOMNode $node): void
    {
        $children = iterator_to_array($node->childNodes);

        foreach ($children as $child) {
            if (! $child instanceof DOMElement) {
                continue;   // text and everything else is inert
            }

            $name = strtolower($child->nodeName);

            if (! in_array($name, self::ALLOWED_ELEMENTS, true)) {
                /*
                 * Unwrapped, not deleted. A disallowed wrapper around real
                 * prose should cost the wrapper, not the paragraph — except
                 * for elements whose content is not prose at all, which go
                 * entirely.
                 */
                self::clean($child);

                if (in_array($name, ['script', 'style', 'iframe', 'object', 'embed', 'template'], true)) {
                    $child->parentNode?->removeChild($child);

                    continue;
                }

                while ($child->firstChild !== null) {
                    $child->parentNode?->insertBefore($child->firstChild, $child);
                }

                $child->parentNode?->removeChild($child);

                continue;
            }

            self::stripAttributes($child, $name);
            self::clean($child);
        }
    }

    private static function stripAttributes(DOMElement $element, string $name): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$name] ?? [];

        // Static copy again: DOMNamedNodeMap is live too.
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $attributeName = strtolower($attribute->nodeName);

            if (! in_array($attributeName, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if (in_array($attributeName, ['href', 'src'], true)
                && ! self::schemeIsAllowed($attribute->nodeValue)) {
                $element->removeAttribute($attribute->nodeName);
            }
        }

        /*
         * Every outbound link gets rel="noopener". It is added here rather
         * than in the template because the template renders a string and
         * cannot see the links inside it.
         */
        if ($name === 'a' && $element->hasAttribute('href')) {
            $element->setAttribute('rel', 'noopener');
        }
    }

    private static function schemeIsAllowed(?string $url): bool
    {
        $url = trim((string) $url);

        if ($url === '') {
            return false;
        }

        // Relative and root-relative URLs carry no scheme and are fine.
        if (! preg_match('#^([a-z][a-z0-9+.-]*):#i', $url, $m)) {
            return true;
        }

        return in_array(strtolower($m[1]), self::ALLOWED_SCHEMES, true);
    }
}
