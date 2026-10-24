<?php

declare(strict_types=1);

namespace App\Support\Help;

/**
 * Task search for the Help Center, in English, Arabic and Egyptian Arabic
 * (Help Center directive, Part 4).
 *
 * A query is normalised (case, Arabic letter forms, diacritics, digits),
 * stripped of the words that only carry the shape of a request ("how do I",
 * "ازاي", "عايز"), lightly stemmed, and then matched two ways:
 *
 *   - by MEANING: each word is looked up in resources/help/synonyms.php, and
 *     an article whose `intents` answer the same concepts scores highest —
 *     most of all when the query names both what to do and what to do it to
 *     ("change" + "cover");
 *   - by WORDS: against the article's title, search keywords, summary and
 *     body, in both languages, tolerating partial words and small typos.
 *
 * Specific tasks rank above the long-form guide's sections. A query that
 * says only "remove" or "delete" is answered with a choice between hiding,
 * deleting and deleting permanently — three different acts — rather than a
 * guess (choices()).
 */
final class HelpSearch
{
    /** The acts a bare "remove" could mean, in the order they are offered. */
    public const REMOVE_CHOICES = ['unpublish', 'delete-item', 'delete-permanently', 'restore-item'];

    /** @var array<string, mixed>|null */
    private static ?array $index = null;

    /** @var array{terms: array<string, list<string>>, phrases: list<array{0: string, 1: string}>}|null */
    private static ?array $concepts = null;

    /** @var array<string, true>|null */
    private static ?array $stop = null;

    /**
     * @return array{
     *   results: list<array{id: string, score: float, kind: string}>,
     *   choices: list<string>,
     *   concepts: list<string>,
     * }
     */
    public static function search(string $query, string $locale = 'en', int $limit = 12): array
    {
        $tokens = self::tokens($query);
        $concepts = self::conceptsIn($query, $tokens);

        if ($tokens === [] && $concepts === []) {
            return ['results' => [], 'choices' => [], 'concepts' => []];
        }

        $scores = [];

        foreach (self::index() as $id => $doc) {
            $score = 0.0;
            $matched = 0;

            foreach ($tokens as $token) {
                $best = 0.0;

                foreach ($doc['fields'] as $field) {
                    $weight = $field['locale'] === $locale ? $field['weight'] : $field['weight'] * 0.8;
                    $hit = self::bestMatch($token, $field['tokens']);

                    if ($hit > 0) {
                        $best = max($best, $hit * $weight);
                    }
                }

                if ($best > 0) {
                    $matched++;
                    $score += $best;
                }
            }

            $shared = array_values(array_intersect($concepts, $doc['intents']));

            if ($shared !== []) {
                $score += 5 * count($shared);

                if (count($shared) >= 2) {
                    $score += 4;   // both the act and its object: the task itself
                }
            }

            if ($score <= 0) {
                continue;
            }

            // A document that answers more of the query's words ranks above one that answers one word loudly.
            if ($tokens !== []) {
                $score *= sqrt(max($matched, count($shared) > 0 ? 1 : 0) / count($tokens)) ?: 0.5;
            }

            $score *= match ($doc['kind']) {
                'trouble' => in_array('problem', $concepts, true) ? 1.4 : 0.85,
                'reference' => 0.45,
                default => 1.0,
            };

            if ($score >= 2.0) {
                $scores[$id] = ['id' => $id, 'score' => round($score, 2), 'kind' => $doc['kind']];
            }
        }

        usort($scores, fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['id'], $b['id']));

        return [
            'results' => array_slice($scores, 0, $limit),
            'choices' => self::choices($concepts),
            'concepts' => $concepts,
        ];
    }

    /**
     * When the query names removal without saying which kind, offer the
     * kinds — hiding, deleting, deleting for good, and bringing back.
     *
     * @param  list<string>  $concepts
     * @return list<string>
     */
    public static function choices(array $concepts): array
    {
        if (array_intersect($concepts, ['remove', 'delete']) === []) {
            return [];
        }

        if (in_array('restore', $concepts, true)) {
            return [];
        }

        $choices = self::REMOVE_CHOICES;

        if (in_array('photo', $concepts, true) && ! in_array('project', $concepts, true)) {
            array_splice($choices, 1, 0, ['delete-photo']);
        }

        return $choices;
    }

    /**
     * Lower case, Arabic letter forms unified, diacritics and tatweel gone,
     * Arabic-Indic digits as Western, punctuation as space.
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '’' => '', "'" => '',
        ]);
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @return list<string> the query's meaningful words, normalised and stemmed */
    public static function tokens(string $text): array
    {
        $stop = self::stopWords();
        $out = [];

        foreach (explode(' ', self::normalize($text)) as $word) {
            if ($word === '' || isset($stop[$word])) {
                continue;
            }

            $stem = self::stem($word);

            if ($stem !== '' && ! isset($stop[$stem])) {
                $out[] = $stem;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Light stemming: enough that "photos", "photograph's", "publishing" and
     * "الصور", "بالصورة" meet their base form. Applied to both sides alike.
     */
    public static function stem(string $word): string
    {
        if (preg_match('/\p{Arabic}/u', $word)) {
            foreach (['وال', 'بال', 'فال', 'كال'] as $prefix) {
                if (str_starts_with($word, $prefix) && mb_strlen($word) - 3 >= 2) {
                    return mb_substr($word, 3);
                }
            }

            if (str_starts_with($word, 'لل') && mb_strlen($word) > 4) {
                return mb_substr($word, 2);
            }

            if (str_starts_with($word, 'ال') && mb_strlen($word) > 3) {
                return mb_substr($word, 2);
            }

            return $word;
        }

        if (strlen($word) > 3 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss')) {
            $word = substr($word, 0, -1);
        }

        if (strlen($word) > 5 && str_ends_with($word, 'ing')) {
            $word = substr($word, 0, -3);
        } elseif (strlen($word) > 4 && str_ends_with($word, 'ed')) {
            $word = substr($word, 0, -2);
        }

        if (strlen($word) > 4 && str_ends_with($word, 'e')) {
            $word = substr($word, 0, -1);
        }

        return $word;
    }

    /**
     * The concepts a query names: single words through the synonym table
     * (exactly, or by a near-miss of five letters or more), phrases by
     * appearing in the normalised query.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    public static function conceptsIn(string $query, ?array $tokens = null): array
    {
        $tokens ??= self::tokens($query);
        $table = self::conceptTable();
        $found = [];

        foreach ($tokens as $token) {
            if (isset($table['terms'][$token])) {
                array_push($found, ...$table['terms'][$token]);

                continue;
            }

            if (mb_strlen($token) >= 5) {
                foreach ($table['terms'] as $term => $concepts) {
                    if (mb_strlen($term) >= 5 && self::distance($token, $term) <= 1) {
                        array_push($found, ...$concepts);
                    }
                }
            }
        }

        $haystack = ' '.implode(' ', array_map(self::stem(...), explode(' ', self::normalize($query)))).' ';

        foreach ($table['phrases'] as [$phrase, $concept]) {
            if (str_contains($haystack, ' '.$phrase.' ')) {
                $found[] = $concept;
            }
        }

        return array_values(array_unique($found));
    }

    /** 1 for the same word, less for a partial word or a typo, 0 for no match. */
    private static function bestMatch(string $token, array $candidates): float
    {
        if (isset($candidates[$token])) {
            return 1.0;
        }

        $best = 0.0;
        $length = mb_strlen($token);

        foreach ($candidates as $candidate => $_) {
            $candidate = (string) $candidate;
            $other = mb_strlen($candidate);

            if ($length >= 3 && str_starts_with($candidate, $token)) {
                $best = max($best, 0.75);
            } elseif ($other >= 4 && $length > $other && str_starts_with($token, $candidate)) {
                $best = max($best, 0.6);
            } elseif ($length >= 4 && $other >= 4 && abs($length - $other) <= 2) {
                $allowed = min($length, $other) >= 7 ? 2 : 1;

                if (self::distance($token, $candidate) <= $allowed) {
                    $best = max($best, 0.6);
                }
            }
        }

        return $best;
    }

    /** Levenshtein distance by character, not by byte (Arabic letters are two bytes). */
    private static function distance(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }

        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $previous = range(0, count($y));

        foreach ($x as $i => $cx) {
            $current = [$i + 1];

            foreach ($y as $j => $cy) {
                $current[] = min($previous[$j + 1] + 1, $current[$j] + 1, $previous[$j] + ($cx === $cy ? 0 : 1));
            }

            $previous = $current;
        }

        return $previous[count($y)];
    }

    /** @return array<string, mixed> every article and guide section, tokenised by field */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = [];

        foreach (KnowledgeBase::all() as $id => $article) {
            $fields = [];

            foreach (['en', 'ar'] as $locale) {
                $t = $article[$locale];
                $body = [];

                foreach ($t['steps'] as $step) {
                    $body[] = $step['title'].' '.$step['body'];
                }
                foreach ($t['fields'] as $field) {
                    $body[] = $field['name'].' '.$field['about'];
                }
                foreach ($t['trouble'] as $problem) {
                    $body[] = $problem['if'].' '.$problem['then'];
                }

                $fields[] = ['locale' => $locale, 'weight' => 6.0, 'tokens' => self::bag($t['title'])];
                $fields[] = ['locale' => $locale, 'weight' => 4.0, 'tokens' => self::bag($t['keywords'])];
                $fields[] = ['locale' => $locale, 'weight' => 2.0, 'tokens' => self::bag($t['summary'].' '.$t['outcome'])];
                $fields[] = ['locale' => $locale, 'weight' => 1.0, 'tokens' => self::bag(implode(' ', [...$body, ...$t['warnings']]))];
            }

            $index[$id] = ['kind' => $article['kind'], 'intents' => $article['intents'], 'fields' => $fields];
        }

        foreach (['en', 'ar'] as $locale) {
            foreach (KnowledgeBase::guide($locale) as $section) {
                $index[$section['id']] ??= ['kind' => 'reference', 'intents' => [], 'fields' => []];
                $index[$section['id']]['fields'][] = ['locale' => $locale, 'weight' => 4.0, 'tokens' => self::bag($section['title'])];
                $index[$section['id']]['fields'][] = ['locale' => $locale, 'weight' => 0.8, 'tokens' => self::bag($section['plain'])];
            }
        }

        return self::$index = $index;
    }

    /** @return array<string, true> the distinct stemmed words of a text */
    private static function bag(string $text): array
    {
        $text = (string) preg_replace('/\[\[(.+?)\]\]/u', '$1', $text);

        return array_fill_keys(self::tokens($text), true);
    }

    /** @return array{terms: array<string, list<string>>, phrases: list<array{0: string, 1: string}>} */
    private static function conceptTable(): array
    {
        if (self::$concepts !== null) {
            return self::$concepts;
        }

        $terms = [];
        $phrases = [];

        foreach (self::synonyms()['concepts'] as $concept => $words) {
            foreach ($words as $word) {
                $parts = array_map(self::stem(...), explode(' ', self::normalize($word)));

                if (count($parts) === 1) {
                    $terms[$parts[0]][] = $concept;
                } else {
                    $phrases[] = [implode(' ', $parts), $concept];
                }
            }
        }

        foreach ($terms as $term => $concepts) {
            $terms[$term] = array_values(array_unique($concepts));
        }

        return self::$concepts = ['terms' => $terms, 'phrases' => $phrases];
    }

    /** @return array<string, true> */
    private static function stopWords(): array
    {
        if (self::$stop !== null) {
            return self::$stop;
        }

        $stop = [];

        foreach (self::synonyms()['stop'] as $words) {
            foreach ($words as $word) {
                $stop[self::normalize($word)] = true;
            }
        }

        return self::$stop = $stop;
    }

    /** @return array<string, mixed> */
    private static function synonyms(): array
    {
        static $synonyms = null;

        return $synonyms ??= (array) require resource_path('help/synonyms.php');
    }

    public static function forget(): void
    {
        self::$index = null;
        self::$concepts = null;
        self::$stop = null;
    }
}
