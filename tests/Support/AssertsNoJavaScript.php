<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The public site's zero-JavaScript rule, expressed once.
 *
 * WHY THIS IS NOT "assertStringNotContainsString('<script')"
 *
 * It was, on eleven pages, until structured data landed. JSON-LD has to be
 * carried in a <script type="application/ld+json"> — that is how the format is
 * embedded and there is no alternative — and it is a DATA BLOCK: the HTML spec
 * classes it as such, browsers never execute it, and a Content-Security-Policy
 * with no script-src does not change its behaviour.
 *
 * So the rule the site actually holds itself to is the one that matters:
 * NOTHING EXECUTES AND NOTHING IS FETCHED. A literal ban on the five
 * characters "<script" would have been satisfied by dropping the structured
 * data, which is a worse site, and would not have caught an inline event
 * handler, which is real JavaScript.
 */
trait AssertsNoJavaScript
{
    protected function assertNoExecutableJavaScript(string $html, string $context = ''): void
    {
        $where = $context === '' ? '' : " on {$context}";

        preg_match_all('/<script\b([^>]*)>/i', $html, $matches);

        foreach ($matches[1] as $attributes) {
            $this->assertStringContainsString(
                'type="application/ld+json"',
                $attributes,
                "an executable <script{$attributes}>{$where}"
            );
        }

        // Nothing is fetched, by any route.
        $this->assertStringNotContainsString('<script src', $html, "a script file is loaded{$where}");

        // And no inline handler, which is JavaScript by another name.
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', $html, "an inline handler{$where}");
    }
}
