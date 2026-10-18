<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 36b A1/A2 — ONE DECISION, THREE WAYS OF WRITING IT DOWN.
 *
 * A value that fell back to another locale has to say so, or the browser
 * applies the page's direction to it. Measured on /ar/work/second-skin before
 * any of this existed:
 *
 *     <p class="project__excerpt">Eighteen frames … left exactly where it was.
 *
 * — no lang, no dir, inside <html lang="ar" dir="rtl">, so the full stop (a
 * neutral character under the bidirectional algorithm) took the paragraph's
 * base direction and rendered at the visual LEFT: ".left exactly where it
 * was".
 *
 * THE DECISION IS from(). Everything else here is that answer in whichever
 * form the context can accept, because three contexts can accept three
 * different things:
 *
 *   TEXT              the <x-t> component wraps it in <span lang dir>
 *   AN EXISTING ELEMENT  attributes() returns the two attributes to add,
 *                     which is what a rich-text <div> needs — its contents
 *                     are sanitised HTML and cannot be wrapped by an
 *                     escaping component
 *   AN ATTRIBUTE      isolate() uses Unicode, because markup cannot nest
 *                     inside <title>, a meta description or an alt
 *
 * They are all the same question asked once, which is the only way the three
 * can agree.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class Fallback
{
    /**
     * U+2068 FIRST STRONG ISOLATE … U+2069 POP DIRECTIONAL ISOLATE.
     *
     * FSI opens a run whose direction is decided by its own first strong
     * character and isolated from whatever surrounds it; PDI closes it. This
     * is the markup-free equivalent of dir="auto" on an element, and it is
     * the ONLY mechanism available inside an attribute value.
     *
     * Not LRI/RLI: FSI needs no decision about which direction to force, so
     * it cannot be wrong about a value whose language the caller guessed.
     */
    public const FSI = "\u{2068}";

    public const PDI = "\u{2069}";

    /**
     * ONE DEFINITION OF "A LATIN RUN", used both to isolate runs and to decide
     * whether a value is predominantly right-to-left.
     *
     * They have to be the same notion or the two disagree: counting with
     * [A-Za-z]+ split "studio@example.com" into three tokens, so a sentence of
     * three Arabic words and two addresses was judged mostly Latin and left
     * alone. A name or an address is ONE run, which is what the isolator does
     * with it.
     */
    private const LATIN_RUN = "/[A-Za-z][A-Za-z0-9]*(?:[.\\-'\u{2019}&@+][A-Za-z0-9]+)*(?:[ ]+[A-Za-z][A-Za-z0-9]*(?:[.\\-'\u{2019}&@+][A-Za-z0-9]+)*)*/u";

    /**
     * The locale this value came from, if that differs from the page's — and
     * therefore needs declaring. Null when nothing needs saying.
     *
     * Asked of the DOCUMENT's locale, not of the locale the caller wanted.
     * The two are the same thing in a fallback and different everywhere else:
     * a caller rendering an Arabic proper noun on an English page is handing
     * over right-to-left text that the page must still be told about.
     */
    public static function from(Model $model, string $field, ?string $locale = null): ?string
    {
        if (! method_exists($model, 'translationLocale')) {
            return null;
        }

        $page = Locale::current();
        $source = $model->translationLocale($field, $locale ?? $page);

        return $source !== null && $source !== $page ? $source : null;
    }

    /**
     * The two attributes to add to an element that already exists.
     *
     * Returns a leading space and nothing else when there is nothing to
     * declare, so a template can interpolate it unconditionally.
     *
     * SAFE TO ECHO UNESCAPED, and that is a property of the VALUES rather
     * than a promise about the caller: both come from Locale::LOCALES, a
     * constant map in this repository. Nothing a visitor or an editor can
     * write reaches this string. Asserted in FallbackAnnotationTest.
     */
    public static function attributes(Model $model, string $field, ?string $locale = null): string
    {
        return self::declare(self::from($model, $field, $locale));
    }

    /**
     * The same two attributes, from a locale that the caller already knows.
     *
     * For a value that does not live in a model column — a project block's
     * caption, which is a key inside a JSON document — where the fallback has
     * already been resolved and only needs writing down. Null means there is
     * nothing to declare.
     */
    public static function declare(?string $from): string
    {
        if ($from === null || $from === Locale::current()) {
            return '';
        }

        return sprintf(' lang="%s" dir="%s"', Locale::html($from), Locale::direction($from));
    }

    /**
     * A value for an attribute, wrapped in a bidi isolate if it fell back.
     *
     * WHY AN ATTRIBUTE NEEDS THIS AT ALL. <title>, meta description, alt and
     * aria-label are read in a document that has a direction, and a
     * right-to-left document reorders a Latin string's trailing punctuation
     * in the tab title exactly as it does in the body. There is nowhere to
     * put lang and dir, so the direction travels with the characters.
     *
     * The isolate characters are invisible, are ignored by every consumer
     * that does not implement bidi, and are explicitly permitted in these
     * contexts. They DO add two code points, so this is applied where a
     * human reads the value and not where a machine parses it — never to a
     * URL, a slug, a JSON-LD identifier or a canonical address.
     */
    public static function isolate(?string $value, Model $model, string $field, ?string $locale = null): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return self::from($model, $field, $locale) === null
            ? $value
            : self::FSI.$value.self::PDI;
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * TASK 36b — A FOREIGN NAME INSIDE NATIVE PROSE, ISOLATED AT RENDER TIME.
     *
     * The other methods here handle a value that is WHOLLY in another
     * language. This handles the commoner case, which four separate pieces of
     * content ran into before it existed:
     *
     *     "نُشرت أعمالي في Aperture Quarterly و The Gentlewoman"
     *     "مع فهرسٍ بصيغة CSV صريحة"
     *     "إحداثيّات الـGPS"
     *     "اكتب إلى studio@example.com"
     *
     * An Arabic sentence with a Latin masthead, acronym or address in it. The
     * value is genuinely Arabic, so from() reports no fallback and nothing is
     * declared — and the Latin run keeps the paragraph's direction, which
     * moves its trailing punctuation and reorders adjacent runs.
     *
     * WHY THIS IS DONE AT RENDER AND NOT IN THE CONTENT. It was tried in the
     * content first, by wrapping each name in the seeder. Three problems, all
     * met in practice: a nowdoc does not interpolate, so the marked-up version
     * shipped a literal PHP expression as prose; the existing settings
     * biography already contained two unmarked names and the seeder may not
     * overwrite it; and an editor writing Arabic in the panel will never type
     * an invisible control character, which makes any authoring convention a
     * guarantee that lasts exactly as long as the person who knows about it.
     *
     * So the software does it. The rule is narrow on purpose: only when the
     * page's direction differs from the run's, only for runs of real Latin
     * words, and never twice.
     * ───────────────────────────────────────────────────────────────────────
     */
    public static function isolateForeign(?string $value, ?string $pageLocale = null): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // Already isolated by hand, or by a previous pass. Leave it alone.
        if (str_contains($value, self::FSI)) {
            return $value;
        }

        $page = $pageLocale ?? Locale::current();

        if (Locale::direction($page) !== 'rtl') {
            return $value;
        }

        /*
         * A value that is MOSTLY Latin is not native prose with a foreign
         * name in it — it is a fallback, and the element around it carries
         * lang and dir already. Isolating inside that would be noise.
         */
        if (! self::isMostlyRightToLeft($value)) {
            return $value;
        }

        /*
         * A run is Latin letters plus the characters that belong INSIDE a
         * name or an address — a dot, an at sign, an ampersand, a hyphen,
         * digits — but never trailing punctuation, which belongs to the
         * Arabic sentence and must keep its direction.
         */
        $isolated = preg_replace_callback(
            self::LATIN_RUN,
            static fn (array $m): string => self::FSI.$m[0].self::PDI,
            $value,
        );

        return $isolated ?? $value;
    }

    /**
     * Is this value predominantly a right-to-left script?
     *
     * COUNTED IN WORDS, NOT IN CHARACTERS, and the first version counted
     * characters and got this wrong:
     *
     *     "اكتب إلى studio@example.com أو ico.org.uk."
     *
     * is four Arabic words and two addresses — plainly Arabic prose — but the
     * addresses are long, so by character count the Latin won and the value
     * was left alone. A word count is what "predominantly" means to a reader.
     */
    private static function isMostlyRightToLeft(string $value): bool
    {
        $rtl = preg_match_all('/[\p{Arabic}\p{Hebrew}]+/u', $value);
        $latin = preg_match_all(self::LATIN_RUN, $value);

        return $rtl > $latin;
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * THE SAME THING, INSIDE A FRAGMENT OF HTML.
     *
     * A sanitised rich-text body is Arabic prose with markup in it, and the
     * Latin runs inside it need isolating exactly as they do in a plain
     * string — but a regular expression over HTML would also rewrite tag
     * names, class attributes and the inside of every href. Measured as the
     * cause of the last two undeclared runs on /ar: "CSV" in a service
     * description and "GPS" in the privacy notice, both inside body HTML and
     * therefore untouched by the component that handles plain values.
     *
     * So the markup is parsed and only TEXT NODES are rewritten. An <a> whose
     * href is an address keeps the href byte for byte; its link text gets the
     * isolate, which is the half a reader sees.
     * ───────────────────────────────────────────────────────────────────────
     */
    public static function isolateForeignHtml(?string $html, ?string $pageLocale = null): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        $locale = $pageLocale ?? Locale::current();

        if (Locale::direction($locale) !== 'rtl') {
            return $html;
        }

        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);

        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8"?><div id="fallback-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return $html;
        }

        $root = $document->getElementById('fallback-root');

        if ($root === null) {
            return $html;
        }

        foreach (iterator_to_array((new \DOMXPath($document))->query('//text()') ?: []) as $node) {
            if (! $node instanceof \DOMText) {
                continue;
            }

            $isolated = self::isolateForeign($node->textContent, $locale);

            if ($isolated !== null && $isolated !== $node->textContent) {
                $node->textContent = $isolated;
            }
        }

        $inner = '';

        foreach ($root->childNodes as $child) {
            $inner .= $document->saveHTML($child);
        }

        return $inner;
    }

    /** Strip the isolates again, for a test or a comparison. */
    public static function strip(string $value): string
    {
        return str_replace([self::FSI, self::PDI], '', $value);
    }
}
