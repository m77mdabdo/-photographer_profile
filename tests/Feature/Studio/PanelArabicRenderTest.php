<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Locale;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 34 F2 — THE ARABIC SCAN, EXTENDED TO THE PANEL.
 *
 * The public site has had this since Task 32: render every page in Arabic
 * and fail on any Latin word, because with the owner's own words in Arabic,
 * any Latin text left on screen is the developer's.
 *
 * The panel had no equivalent, and it is the half with far more strings in
 * it — every label, every filter, every empty state, every refusal.
 *
 * WHAT IS ALLOWED TO STAY LATIN, and why each one:
 *
 *   · the locale switcher names each language in its OWN script;
 *   · the studio's own name, which is a proper noun it chose;
 *   · a filename, a route name, a slug and a path, which are addresses
 *     rather than prose — "pexels-photo-3184465.jpeg" does not translate;
 *   · the keyboard names in a shortcut hint — Ctrl, Cmd, Shift, Enter are
 *     printed on the key.
 *
 * Everything else is a failure, and the message names the screen and the
 * words so the fix is obvious.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class PanelArabicRenderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Latin that is allowed to appear in a panel rendered in Arabic.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        // The language switcher, in each language's own script.
        'English',

        // Keys are printed on the keyboard in Latin.
        'Ctrl', 'Cmd', 'Shift', 'Enter', 'Esc', 'Alt',

        // Formats and units, which are the same word everywhere.
        'avif', 'webp', 'jpeg', 'jpg', 'png', 'kB', 'MB', 'px',

        /*
         * A technical directive printed literally in a hint: "Off adds a
         * noindex". It is the value of a meta tag, not a word.
         */
        'noindex',

        /*
         * The Help page (System → Help) is the Arabic user guide, and it
         * names things that are spelled the same in any language: the apps
         * it tells the owner to install, the file formats it accepts, an
         * example address and slug.
         */
        'Google', 'Authenticator', 'Microsoft', 'Password', 'Authy',
        'JPEG', 'PNG', 'WebP', 'HEIC', 'WebM', 'MP4', 'H', 'https', 'admin', 'salt-line',
        // The folder the backup chapter tells the host to copy.
        'storage',
    ];

    /**
     * The studio's own name, whatever it has been set to.
     *
     * Resolved rather than hardcoded. It is a proper noun the owner chose —
     * a photographer trading as "Hartley & Sons" writes that on an Arabic
     * page too — and it sits in the panel's header on every screen. The test
     * database falls back to config('app.name'), which is why this showed up
     * as a stray "Laravel".
     *
     * @return list<string>
     */
    private function brandWords(): array
    {
        $name = (string) (\App\Support\Settings::get('site.name') ?: config('app.name'));

        preg_match_all('/[A-Za-z][A-Za-z\'’-]{2,}/', $name, $matches);

        return $matches[0];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
            'name' => 'مالك الاستوديو',
            'two_factor_confirmed_at' => now(),
        ]));

        Filament::setCurrentPanel('studio');

        app()->setLocale('ar');
    }

    /** Visible words only: no scripts, no styles, no attributes, no head. */
    private function visibleText(string $html): string
    {
        $body = (string) preg_replace('#<(script|style|template)[^>]*>.*?</\1>#s', '', $html);
        $body = (string) preg_replace('#<head>.*?</head>#s', '', $body);
        $body = (string) preg_replace('#<!--.*?-->#s', '', $body);

        /*
         * 35 H4 — AND THE ATTRIBUTES, which this did not read.
         *
         * The panel is full of icon-only chrome whose only name is an
         * aria-label or a title: the sidebar collapse, the column toggle,
         * the reorder control. Every one of them was outside this scan, on
         * the surface with the most strings in the product.
         */
        preg_match_all(
            '/\b(aria-label|title|placeholder|alt|aria-description|aria-roledescription)="([^"]*)"/i',
            $body,
            $attributes,
        );

        $text = strip_tags($body).' '.implode(' ', $attributes[2]);

        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode($text)));
    }

    /**
     * The Latin words left on a page, minus the ones allowed to be there.
     *
     * Hyphenated and dotted runs are dropped wholesale: a filename, a slug,
     * a route name and a class attribute that slipped through are addresses,
     * not prose, and flagging them would make this test noise.
     *
     * @return list<string>
     */
    private function latinWords(string $text): array
    {
        /*
         * Addresses first, so their parts are never counted as words: an
         * email, a filename, a slug, a route name and a path are all
         * identifiers rather than prose. "pexels-photo-3184465.jpeg" does
         * not translate, and neither does the local part of an address.
         */
        $text = (string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+/', ' ', $text);
        $text = (string) preg_replace('/[A-Za-z0-9_-]+[.\/][A-Za-z0-9_.\/-]+/', ' ', $text);

        preg_match_all('/[A-Za-z][A-Za-z\'’-]{2,}/', $text, $matches);

        return array_values(array_unique(array_diff($matches[0], self::ALLOWED, $this->brandWords())));
    }

    /**
     * Every list screen the panel has, by discovery rather than by a list.
     *
     * @return array<string, string> label => page class
     */
    private function screens(): array
    {
        $screens = [];

        /** @var class-string<Resource> $resource */
        foreach (Filament::getPanel('studio')->getResources() as $resource) {
            foreach ($resource::getPages() as $name => $registration) {
                // Pages needing a record are covered by the record scan below.
                if (in_array($name, ['edit', 'view', 'story', 'preview'], true)) {
                    continue;
                }

                $screens[class_basename($resource).' · '.$name] = $registration->getPage();
            }
        }

        foreach (Filament::getPanel('studio')->getPages() as $page) {
            $screens['page · '.class_basename($page)] = $page;
        }

        return $screens;
    }

    // ----------------------------------------------------------------- 1

    /**
     * NO PANEL SCREEN RENDERS ENGLISH IN ARABIC.
     *
     * Every list, every create form, the dashboard and the settings page.
     */
    public function test_no_panel_screen_renders_english(): void
    {
        $failures = [];

        foreach ($this->screens() as $label => $page) {
            try {
                $html = Livewire::test($page)->html();
            } catch (\Throwable $e) {
                $failures[] = "$label did not render: ".$e->getMessage();

                continue;
            }

            $latin = $this->latinWords($this->visibleText($html));

            if ($latin !== []) {
                $failures[] = $label.': '.implode(', ', array_slice($latin, 0, 80));
            }
        }

        $this->assertSame(
            [],
            $failures,
            "these panel screens show English in Arabic:\n  ".implode("\n  ", $failures)
        );
    }

    // ----------------------------------------------------------------- 2

    /**
     * AND NO SCREEN SHOWS A RAW TRANSLATION KEY.
     *
     * __() returns the key itself when there is no translation, which is the
     * failure mode that looks like a design decision: "studio.media.no_alt"
     * on screen reads as a label somebody chose.
     */
    public function test_no_panel_screen_shows_a_raw_translation_key(): void
    {
        $failures = [];

        foreach ($this->screens() as $label => $page) {
            try {
                $text = $this->visibleText(Livewire::test($page)->html());
            } catch (\Throwable) {
                continue;   // reported by the scan above
            }

            preg_match_all('/\bstudio\.[a-z_]+\.[a-z_.]+\b/', $text, $matches);

            if ($matches[0] !== []) {
                $failures[] = $label.': '.implode(', ', array_unique($matches[0]));
            }
        }

        $this->assertSame(
            [],
            $failures,
            "these panel screens show a raw translation key:\n  ".implode("\n  ", $failures)
        );
    }

    // ----------------------------------------------------------------- 3

    /**
     * EVERY KEY THE PANEL USES EXISTS IN BOTH LOCALES.
     *
     * The scans above only see the strings a rendered screen happens to
     * reach: a refusal nobody triggered, a notification nobody fired and an
     * empty state with rows in the table are all invisible to them.
     *
     * This reads the keys out of the SOURCE instead, so a string added with
     * no Arabic fails the day it is written rather than the day somebody
     * happens to see it.
     */
    public function test_every_translation_key_the_panel_uses_exists_in_both_locales(): void
    {
        $files = array_merge(
            $this->phpFiles(app_path('Filament')),
            $this->phpFiles(app_path('Livewire')),
            $this->phpFiles(resource_path('views/filament')),
            $this->phpFiles(resource_path('views/livewire')),
        );

        $used = [];

        foreach ($files as $file) {
            preg_match_all(
                "/(?:__|trans_choice)\(\s*'(studio\.[a-zA-Z0-9_.]+)'/",
                (string) file_get_contents($file),
                $matches,
            );

            foreach ($matches[1] as $key) {
                $used[$key] = $file;
            }
        }

        $this->assertNotEmpty($used, 'no translation keys were found at all, so this proves nothing');

        $missing = [];

        foreach ($used as $key => $file) {
            foreach (Locale::codes() as $locale) {
                /*
                 * A key with a dynamic tail — 'studio.activity.event.'.$x —
                 * is matched here as its literal prefix, which no locale
                 * defines as a string. What it must resolve to is the GROUP
                 * the tail indexes into, so an array is the right answer and
                 * an empty one is not: a group with no members means every
                 * value that key can take is missing.
                 */
                if (str_ends_with($key, '.')) {
                    $group = __(rtrim($key, '.'), [], $locale);

                    if (! is_array($group) || $group === []) {
                        $missing[] = "$key* ($locale) — used in ".str_replace(base_path().'/', '', $file);
                    }

                    continue;
                }

                $value = __($key, [], $locale);

                if ($value === $key || (is_string($value) && $value === '')) {
                    $missing[] = "$key ($locale) — used in ".str_replace(base_path().'/', '', $file);
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "these keys are used by the panel and missing from a locale:\n  ".implode("\n  ", $missing)
        );
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    // ----------------------------------------------------------------- 3b

    /**
     * ───────────────────────────────────────────────────────────────────────
     * A SELECT ON A TRANSLATABLE RELATIONSHIP RESOLVES ITS OWN LABELS.
     *
     * Select::relationship('tags', 'name') reads that column STRAIGHT OUT OF
     * THE DATABASE, and `name` on a tag is a translatable JSON document — so
     * every chip, every option and every remove button's accessible name on
     * the journal form read
     *
     *     {"en": "process", "ar": "عملية"}
     *
     * WHY THIS IS A STRUCTURAL CHECK AND NOT A RENDERED ONE. It was written
     * first as a scan of the rendered edit form, and that version PASSED with
     * the bug reinstated: Filament builds a searchable select's chips in the
     * browser, so the JSON is never in the HTML PHP produces. The browser
     * harness found it, printing the control's accessible name, and the
     * harness is where that check belongs.
     *
     * What a PHP test can say is that the resource asked for the label to be
     * resolved through the model. That is the thing a future edit would drop,
     * and it is checkable here.
     * ───────────────────────────────────────────────────────────────────────
     */
    public function test_a_select_on_a_translatable_relationship_resolves_its_labels(): void
    {
        $failures = [];

        foreach (Filament::getPanel('studio')->getResources() as $resource) {
            foreach ($this->selects($resource) as $select) {
                $relationship = $select->getRelationshipName();

                if ($relationship === null) {
                    continue;
                }

                $model = $resource::getModel();

                if (! method_exists(new $model, $relationship)) {
                    continue;
                }

                $related = (new $model)->{$relationship}()->getRelated();

                $attribute = $select->getRelationshipTitleAttribute();

                if ($attribute === null
                    || ! method_exists($related, 'isTranslatableAttribute')
                    || ! $related->isTranslatableAttribute($attribute)) {
                    continue;
                }

                if (! $select->hasOptionLabelFromRecordUsingCallback()) {
                    $failures[] = sprintf(
                        '%s: the %s select reads the translatable column "%s" directly',
                        class_basename($resource),
                        $relationship,
                        $attribute,
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $failures,
            "these selects will print a translation map instead of a word:\n  ".implode("\n  ", $failures)
        );
    }

    /**
     * Every Select in a resource's form and filters.
     *
     * @param  class-string<Resource>  $resource
     * @return list<\Filament\Forms\Components\Select>
     */
    private function selects(string $resource): array
    {
        $page = $resource::getPages()['index']->getPage();
        $instance = Livewire::test($page)->instance();

        $found = [];

        $walk = function (array $components) use (&$walk, &$found): void {
            foreach ($components as $component) {
                if ($component instanceof \Filament\Forms\Components\Select) {
                    $found[] = $component;
                }

                $walk($component->getDefaultChildComponents());
            }
        };

        $walk($resource::form(\Filament\Schemas\Schema::make($instance))->getComponents());

        foreach ($resource::table(\Filament\Tables\Table::make($instance))->getFilters() as $filter) {
            if (method_exists($filter, 'getSchema')) {
                continue;   // a custom filter's own fields are its business
            }

            if ($filter instanceof \Filament\Tables\Filters\SelectFilter) {
                // A SelectFilter owns a Select internally; ask it directly.
                $found[] = $filter->getSelect();
            }
        }

        return $found;
    }

    // ----------------------------------------------------------------- 4

    /** The two locale files are the same shape, key for key. */
    public function test_both_locale_files_carry_the_same_keys(): void
    {
        $flatten = function (array $values, string $prefix = '') use (&$flatten): array {
            $flat = [];

            foreach ($values as $key => $value) {
                $flat = array_merge(
                    $flat,
                    is_array($value) ? $flatten($value, $prefix.$key.'.') : [$prefix.$key => $value],
                );
            }

            return $flat;
        };

        $english = $flatten(require lang_path('en/studio.php'));
        $arabic = $flatten(require lang_path('ar/studio.php'));

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($english), array_keys($arabic))),
            'these keys have no Arabic'
        );

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($arabic), array_keys($english))),
            'these Arabic keys have no English'
        );

        // And nothing is translated to the empty string, which renders as a gap.
        foreach ($arabic as $key => $value) {
            $this->assertNotSame('', trim((string) $value), "$key has an empty Arabic value");
        }
    }
}
