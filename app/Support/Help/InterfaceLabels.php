<?php

declare(strict_types=1);

namespace App\Support\Help;

use App\Support\Locale;

/**
 * Every word the dashboard can put on screen, per language — the
 * application's own translations and Filament's.
 *
 * Help articles mark the words a person must find on screen as [[Label]].
 * A label that is not in this set is a label the person will never see: a
 * typo, a button that was renamed, or an English word quoted in an Arabic
 * article. HelpKnowledgeBaseTest asserts every marked label against it, so
 * renaming a button in lang/ fails the build until the Help is updated.
 *
 * Strings with placeholders ("New :label", ":count selected") match as
 * patterns, the placeholder standing for any text.
 */
final class InterfaceLabels
{
    /** @var array<string, array{exact: array<string, true>, patterns: list<string>}> */
    private static array $cache = [];

    public static function has(string $label, string $locale): bool
    {
        $set = self::for($locale);
        $label = self::clean($label);

        if (isset($set['exact'][$label])) {
            return true;
        }

        foreach ($set['patterns'] as $pattern) {
            if (preg_match($pattern, $label) === 1) {
                return true;
            }
        }

        return false;
    }

    /** True only for a whole interface string — not one that matched a pattern with data in it ("Remove Landscape"). */
    public static function isExact(string $label, string $locale): bool
    {
        return isset(self::for($locale)['exact'][self::clean($label)]);
    }

    /** @return list<string> every [[Label]] in a piece of article text */
    public static function cited(string $text): array
    {
        preg_match_all('/\[\[(.+?)\]\]/u', $text, $m);

        return $m[1];
    }

    /** @return array{exact: array<string, true>, patterns: list<string>} */
    private static function for(string $locale): array
    {
        if (isset(self::$cache[$locale])) {
            return self::$cache[$locale];
        }

        $strings = [];

        foreach (glob(lang_path($locale.'/*.php')) ?: [] as $file) {
            self::collect((array) require $file, $strings);
        }

        foreach (glob(base_path('vendor/filament/*/resources/lang/'.$locale), GLOB_ONLYDIR) ?: [] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->getExtension() === 'php') {
                    self::collect((array) require $file->getPathname(), $strings);
                }
            }
        }

        // The language names on the content tabs, which come from the locale list, not from lang/.
        foreach (Locale::codes() as $code) {
            $strings[] = Locale::native($code);
        }

        $exact = [];
        $patterns = [];

        foreach ($strings as $string) {
            foreach (explode('|', $string) as $form) {
                $form = self::clean((string) preg_replace('/^\s*(\{\d+\}|\[[^\]]*\])\s*/u', '', $form));

                if ($form === '') {
                    continue;
                }

                if (preg_match('/:[a-z_]+/', $form)) {
                    $patterns[] = '/^'.preg_replace('/\\\\:[a-z_]+/', '.+', preg_quote($form, '/')).'$/u';
                } else {
                    $exact[$form] = true;
                }
            }
        }

        return self::$cache[$locale] = ['exact' => $exact, 'patterns' => array_values(array_unique($patterns))];
    }

    /** @param  array<mixed>  $tree */
    private static function collect(array $tree, array &$out): void
    {
        array_walk_recursive($tree, function ($value) use (&$out): void {
            if (is_string($value)) {
                $out[] = $value;
            }
        });
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
    }
}
