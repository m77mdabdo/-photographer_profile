<?php

declare(strict_types=1);

namespace App\Support\Help;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Renders Help article text: a small Markdown subset (bold, italics, code,
 * lists, paragraphs) with raw HTML stripped and unsafe links refused, then
 * [[Label]] turned into a marked span that shows the person the exact word
 * to look for on screen.
 *
 * The label replacement runs on Markdown's OUTPUT, where the label is
 * already HTML-escaped — so a label can never inject markup.
 */
final class HelpText
{
    public static function block(string $text): string
    {
        return self::labels(Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]));
    }

    public static function inline(string $text): string
    {
        return self::labels(Str::inlineMarkdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]));
    }

    /** The same text with the label marks removed, for places that take plain text. */
    public static function plain(string $text): string
    {
        return (string) preg_replace('/\[\[(.+?)\]\]/u', '$1', $text);
    }

    private static function labels(string $html): string
    {
        return (string) preg_replace('/\[\[(.+?)\]\]/u', '<span class="studio-help__ui">$1</span>', $html);
    }

    public static function toHtml(string $text): HtmlString
    {
        return new HtmlString(self::block($text));
    }
}
