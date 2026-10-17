<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * E1 — THE PANEL STYLESHEET'S OWN INVARIANTS.
 *
 * Three claims this project makes about resources/css/filament/studio, each
 * of which was false somewhere when the test was written: Arabic is never
 * tracked, the Latin display face is always handed back in Arabic, and a rule
 * here styles markup that exists.
 *
 * ADR 0003 demonstrated that +0.08em "breaks the joins" of a cursive script,
 * and D2 fixed it for the public site at the token: tokens.css zeroes
 * --tracking-wide and --tracking-tight under [lang='ar'], "so a new component
 * cannot forget it".
 *
 * A component CAN forget it, by writing the number instead of the token, and
 * four rules in the panel's own stylesheet had: the media screen's facts
 * list, its renditions table, the dashboard's cluster names and the unread
 * badge. Nothing failed, because nothing was looking.
 *
 * So this looks. It reads the two stylesheets the panel is built from, finds
 * every rule that tracks text, and requires each one to be reachable by the
 * token fix OR to have an Arabic counterpart that neutralises it. A rule
 * written either way passes; a fifth literal fails, naming its selector.
 *
 * The CSS is parsed rather than measured because the claim is about EVERY
 * tracked rule in the panel, not about the handful of screens a browser run
 * happens to visit — and because a rule for a screen nobody screenshotted is
 * exactly how these four survived.
 * ───────────────────────────────────────────────────────────────────────────
 */
class PanelStylesheetTest extends TestCase
{
    /** The stylesheets that make up the panel's own type. */
    private const SHEETS = [
        'resources/css/filament/studio/theme.css',
        'resources/css/filament/studio/components.css',
    ];

    public function test_every_tracked_rule_in_the_panel_is_neutralised_for_arabic(): void
    {
        $offenders = [];

        foreach (self::SHEETS as $sheet) {
            $rules = $this->rules(base_path($sheet));

            /*
             * The Arabic side first: every selector that any [dir='rtl'] or
             * [lang='ar'] rule sets back to normal, with its prefix stripped,
             * so the two sides can be compared as plain selectors.
             */
            $neutralised = [];

            foreach ($rules as [$selectors, $body]) {
                if (! preg_match('/letter-spacing\s*:\s*(normal|0)\b/', $body)) {
                    continue;
                }

                foreach ($selectors as $selector) {
                    if (preg_match("/^\[(?:dir='rtl'|lang='ar')\]\s+(.+)$/", $selector, $m)) {
                        $neutralised[trim($m[1])] = true;
                    }
                }
            }

            foreach ($rules as [$selectors, $body]) {
                if (! preg_match('/letter-spacing\s*:\s*([^;]+);/', $body, $m)) {
                    continue;
                }

                $value = trim($m[1]);

                // normal, 0, or a token the Arabic block already zeroes.
                if (preg_match('/^(normal|0)$/', $value) || str_contains($value, 'var(--tracking-')) {
                    continue;
                }

                foreach ($selectors as $selector) {
                    // An Arabic rule is its own exception.
                    if (str_contains($selector, "dir='rtl'") || str_contains($selector, "lang='ar'")) {
                        continue;
                    }

                    if (! isset($neutralised[$selector])) {
                        $offenders[] = basename($sheet).': '.$selector.' { letter-spacing: '.$value.' }';
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These rules track text with a literal value and no Arabic exception.\n"
            ."Either read a --tracking-* token, which tokens.css zeroes under [lang='ar'],\n"
            ."or add a [dir='rtl'] / [lang='ar'] rule beside it:\n  ".implode("\n  ", $offenders),
        );
    }

    /**
     * The five that were fixed, named one at a time. A regression in any of
     * them is one line in a stylesheet, and this says which line matters.
     *
     * Six were found. Three were literal tracking on live surfaces; the
     * fourth, .studio-badge, styled markup Task 33 had deleted and was
     * removed rather than corrected. The last two came out of the same scan
     * and were worse: .fi-modal-heading and .fi-wi-stats-overview-stat-value share
     * a rule with the page and section headings, which asks for the DISPLAY
     * face, and the Arabic exception beside it named only two of the four. A
     * delete confirmation in Arabic was therefore set in Archivo, which has no
     * Arabic glyphs — the per-glyph fallback D2 found on the public site.
     */
    public function test_the_rules_that_were_found_stay_fixed(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/filament/studio/theme.css'));

        foreach ([
            '.studio-media-detail__facts dt',
            '.studio-media-detail__table th',
            '.studio-figures__group',
            '.fi-modal-heading',
            '.fi-wi-stats-overview-stat-value',
        ] as $selector) {
            $this->assertMatchesRegularExpression(
                '/\[(?:dir=\'rtl\'|lang=\'ar\')\]\s+'.preg_quote($selector, '/').'\s*[,{]/',
                $css,
                $selector.' lost its Arabic exception.',
            );
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * THE SAME QUESTION, ANSWERED SOMEWHERE BETTER SINCE 34e.
     *
     * This used to require that every rule asking for --font-serif ALSO had
     * an Arabic counterpart handing the face back to var(--font-sans),
     * because Archivo has no Arabic glyphs and this panel had no Arabic
     * display face to offer instead. Five rules, five exceptions, and a test
     * to keep them in step — bookkeeping that existed because the TOKEN was
     * wrong.
     *
     * 34e gave the panel a real Arabic display face, so --font-serif is
     * remapped to Rubik under [lang='ar'] and every rule reading the token
     * gets an Arabic-capable face without anybody writing a counterpart. The
     * invariant worth keeping is therefore about the token rather than about
     * each rule: in Arabic, the display stack and the text stack must BEGIN
     * with a face that has Arabic glyphs.
     *
     * The per-rule exceptions still exist and still carry the tracking and
     * case corrections, which the token cannot express.
     * ═══════════════════════════════════════════════════════════════════════
     */
    public function test_the_arabic_stacks_begin_with_an_arabic_face(): void
    {
        $arabicFaces = ['Rubik', 'IBM Plex Sans Arabic'];

        $blocks = [
            'tokens.css --font-display' => [base_path('resources/css/tokens.css'), '--font-display'],
            'tokens.css --font-sans' => [base_path('resources/css/tokens.css'), '--font-sans'],
            'theme.css --font-serif' => [base_path('resources/css/filament/studio/theme.css'), '--font-serif'],
        ];

        foreach ($blocks as $what => [$file, $token]) {
            $css = (string) file_get_contents($file);

            /*
             * The LAST declaration of the token wins in these files: the
             * Latin default is declared first and the Arabic block restates
             * it further down.
             */
            preg_match_all('/'.preg_quote($token, '/').':\s*([^;]+);/', $css, $matches);

            $this->assertNotEmpty($matches[1], "{$what} is not declared at all");

            $arabicStack = trim(end($matches[1]));
            $first = trim(explode(',', $arabicStack)[0], " '\"");

            $this->assertContains(
                $first,
                $arabicFaces,
                "{$what} leads with {$first}, which has no Arabic glyphs: {$arabicStack}",
            );

            // And the Latin face stays behind it, for Latin runs in Arabic text.
            $this->assertMatchesRegularExpression(
                '/(Archivo|Inter Tight)/',
                $arabicStack,
                "{$what} drops the Latin face entirely: {$arabicStack}",
            );
        }
    }

    /** Nothing in the panel asks for a Latin-only face by NAME. */
    public function test_no_rule_names_a_latin_face_directly(): void
    {
        $offenders = [];

        foreach (self::SHEETS as $sheet) {
            foreach ($this->rules(base_path($sheet)) as [$selectors, $body]) {
                if (! preg_match("/font-family\s*:\s*'?(Archivo|Inter Tight|JetBrains Mono)/", $body, $m)) {
                    continue;
                }

                $offenders[] = basename($sheet).': '.$selectors[0].' names '.$m[1].' instead of a token';
            }
        }

        /*
         * A rule that names a Latin face directly cannot be remapped for
         * Arabic by the token block, and that is how this panel spent four
         * tasks rendering Arabic in whatever the system supplied. The tokens
         * are the only place a face is chosen.
         */
        $this->assertSame([], $offenders, implode("\n  ", $offenders));
    }

    /**
     * And the public site's token fix, which is what the panel's rules are
     * being held to. Asserted here because the whole argument above rests on
     * it: if these ever stopped being zero, every token-reading rule in the
     * panel would quietly start tracking Arabic again.
     *
     * @see docs/decisions/0003-the-arabic-composition.md
     */
    public function test_the_tokens_are_still_zero_in_arabic(): void
    {
        $tokens = (string) file_get_contents(base_path('resources/css/tokens.css'));

        $this->assertMatchesRegularExpression(
            "/\[lang='ar'\]\s*\{[^}]*--tracking-wide:\s*0;/s",
            $tokens,
            '--tracking-wide is no longer zeroed for Arabic.',
        );
        $this->assertMatchesRegularExpression(
            "/\[lang='ar'\]\s*\{[^}]*--tracking-tight:\s*0;/s",
            $tokens,
            '--tracking-tight is no longer zeroed for Arabic.',
        );
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * NO RULE HERE STYLES MARKUP THAT NO LONGER EXISTS.
     *
     * Ninety-five lines of this file styled the blade lists two dashboard
     * widgets used to render — .studio-list and its five parts, .studio-badge,
     * .studio-empty, .studio-empty__hint, .studio-more — and Task 33 replaced
     * those widgets with Filament tables in e47783d. The markup went; the
     * stylesheet stayed; a comment WRITTEN IN THAT COMMIT, five lines above
     * the orphans, says "dead CSS outlives the markup it styled unless it is
     * deleted with it".
     *
     * The classes in this directory are ours, so every one of them can be
     * looked for. Filament's own (.fi-*) are not checked: they are upstream
     * markup, and a selector for a component this panel does not currently
     * show is not dead.
     *
     * ONE INDIRECTION IS UNDERSTOOD, AND ONLY ONE. Task 34c made the three
     * locale switchers a single component with a `block` prop, so
     * .studio-locale__option is composed at render time from block="studio-
     * locale" and the literal `__option` in the component — a literal search
     * reported all four as dead on the day they started being used. A
     * composed name counts as live when BOTH halves are present: the block
     * declared on a component somewhere, and the element suffix written out
     * in a view. Both halves still have to exist, so deleting either one
     * fails this test.
     * ═══════════════════════════════════════════════════════════════════════
     */
    public function test_no_rule_styles_markup_that_no_longer_exists(): void
    {
        $sources = $this->sourceText();
        $orphans = [];

        foreach (self::SHEETS as $sheet) {
            foreach ($this->rules(base_path($sheet)) as [$selectors]) {
                foreach ($selectors as $selector) {
                    preg_match_all('/\.(studio-[a-z0-9_-]+)/', $selector, $matches);

                    foreach ($matches[1] as $class) {
                        if (str_contains($sources, $class)) {
                            continue;
                        }

                        // A BEM name a component composes: block + __element.
                        if (str_contains($class, '__')) {
                            [$blockName, $element] = explode('__', $class, 2);

                            if (
                                str_contains($sources, 'block="'.$blockName.'"')
                                && str_contains($sources, '__'.$element)
                            ) {
                                continue;
                            }
                        }

                        $orphans[$class] = basename($sheet).': .'.$class;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            array_values($orphans),
            "These classes are styled here and emitted nowhere:\n  "
            .implode("\n  ", $orphans)
            ."\nDelete the rule, or the markup that should have been using it.",
        );
    }

    /** Everything that can emit a class: blade, and the PHP that builds it. */
    private function sourceText(): string
    {
        $text = '';

        foreach ([base_path('resources/views'), base_path('app')] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'js'], true)) {
                    $text .= (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $text;
    }

    /**
     * Every rule in a stylesheet, as [selectors, body]. At-rules are flattened
     * rather than skipped: the responsive block at the foot of theme.css is a
     * @media, and a tracked label inside one still tracks.
     *
     * @return list<array{0: list<string>, 1: string}>
     */
    private function rules(string $path): array
    {
        $css = (string) file_get_contents($path);

        // Comments first — they contain example selectors and whole rules.
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';

        $rules = [];

        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        foreach ($matches as [, $selectorList, $body]) {
            $selectorList = trim($selectorList);

            // An at-rule's own line (@media …) carries no declarations.
            if ($selectorList === '' || str_starts_with($selectorList, '@')) {
                continue;
            }

            // A selector list that opens inside a @media keeps its last line.
            $selectorList = trim((string) preg_replace('/^.*\}\s*/s', '', $selectorList));

            $selectors = array_values(array_filter(array_map(
                fn (string $s): string => trim(preg_replace('/\s+/', ' ', $s) ?? ''),
                explode(',', $selectorList),
            )));

            $rules[] = [$selectors, $body];
        }

        return $rules;
    }
}
