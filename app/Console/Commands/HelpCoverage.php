<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\Support\HelpHint;
use App\Support\Help\HelpText;
use App\Support\Help\InterfaceLabels;
use App\Support\Help\KnowledgeBase;
use Illuminate\Console\Command;

/**
 * The Help Center's coverage report (Help Center directive, Part 19),
 * measured rather than claimed.
 *
 * Reads the live dashboard inventory written by
 * tests/Support/dashboard-inventory.mjs (every screen, both languages: its
 * fields, sections and actions) and the knowledge base, and for each module
 * counts the meaningful controls and how many the Help names — in the same
 * language — plus the tutorials, the ones performed end to end by a browser
 * check, the search words, the contextual help buttons and the phone
 * checks. Writes docs/HELP_CENTER_COVERAGE.md.
 *
 * "Meaningful" excludes the table's own furniture (column manager, page
 * numbers, select-all) and anything that is not an interface string at all
 * — a project's title in a list row is data, not a control.
 */
class HelpCoverage extends Command
{
    protected $signature = 'help:coverage {--inventory=storage/app/help-inventory.json} {--out=docs/HELP_CENTER_COVERAGE.md}';

    protected $description = 'Measure how much of the dashboard the Help Center documents';

    /** Table and form furniture: present on every list, explained once in general terms. */
    private const FURNITURE = [
        'en' => ['Updated', 'Added', 'Received', 'All', 'Select an option', 'Clear selection', 'Reset', 'Apply filters', 'Column manager', 'Apply columns', 'Deselect all', 'English', 'العربية', 'Close', 'Cancel', 'Collapse section', 'Expand section', 'Next', 'Previous', 'What is this?', 'Collapse all', 'Expand all', 'Show password', 'Hide password', 'Create & create another'],
        'ar' => ['عُدِّل', 'أُضيف', 'وصل', 'الكل', 'اختر', 'مسح التحديد', 'إعادة ضبط التصفيات', 'تطبيق التصفيات', 'تبديل الأعمدة', 'إعادة تعيين', 'تطبيق الأعمدة', 'إلغاء تحديد الكل', 'English', 'العربية', 'إغلاق', 'إلغاء', 'طي القسم', 'توسيع القسم', 'التالي', 'السابق', 'ما هذا؟', 'طيّ الكل', 'توسيع الكل', 'اطوِ الكل', 'افتح الكل', 'عرض كلمة المرور', 'إخفاء كلمة المرور', 'إضافة وبدء إضافة المزيد'],
    ];

    /** Module => the screens that make it up (inventory keys, by prefix) and its dashboard address. */
    private const MODULES = [
        'Dashboard' => ['dashboard', '/admin'],
        'Projects' => ['projects', '/admin/projects'],
        'Media' => ['media', '/admin/media'],
        'Categories' => ['categories', '/admin/categories'],
        'Cinematic sections' => ['cinematic', '/admin/cinematic-sections'],
        'Journal' => ['articles', '/admin/articles'],
        'Journal categories' => ['article-categories', '/admin/article-categories'],
        'Tags' => ['tags', '/admin/tags'],
        'Pages' => ['pages', '/admin/pages'],
        'Services' => ['services', '/admin/services'],
        'Clients' => ['clients', '/admin/clients'],
        'Testimonials' => ['testimonials', '/admin/testimonials'],
        'Navigation' => ['menus', '/admin/menus'],
        'Enquiries' => ['enquiries', '/admin/enquiries'],
        'Users' => ['users', '/admin/users'],
        'Activity' => ['activity', '/admin/activity'],
        'Redirects' => ['redirects', '/admin/redirects'],
        'Site settings' => ['settings', '/admin/settings'],
        'Account security' => ['account-security', '/admin/account-security'],
        'Profile' => ['profile', '/admin/profile'],
    ];

    /** Screens whose phone layout `responsive-check.mjs --panel` checks at 320, 390 and 768. */
    private const PHONE_CHECKED = ['dashboard', 'projects', 'projects.create', 'media', 'cinematic', 'cinematic.create', 'services', 'enquiries', 'settings', 'account-security', 'help', 'profile'];

    /** Modules a phone workflow is performed in, end to end (mobile-publish-check.mjs). */
    private const PHONE_WORKFLOW = ['Projects', 'Media'];

    /** Which module each contextual help topic sits in. */
    private const HINT_MODULES = [
        'status' => ['Projects', 'Services', 'Journal', 'Pages', 'Testimonials'],
        'published_at' => ['Projects', 'Services', 'Journal', 'Pages', 'Testimonials'],
        'seo_title' => ['Services', 'Journal', 'Pages', 'Journal categories'],
        'seo_description' => ['Services', 'Journal', 'Pages', 'Journal categories'],
        'alt' => ['Media'],
        'direction' => ['Cinematic sections'], 'spotlight' => ['Cinematic sections'], 'darkness' => ['Cinematic sections'],
        'softness' => ['Cinematic sections'], 'pace' => ['Cinematic sections'], 'mobile' => ['Cinematic sections'], 'video' => ['Cinematic sections'],
        'featured' => ['Projects'],
        'slug' => ['Projects', 'Services', 'Journal', 'Pages', 'Categories', 'Journal categories', 'Tags'],
        'price' => ['Services'],
    ];

    public function handle(): int
    {
        $path = base_path((string) $this->option('inventory'));

        if (! is_file($path)) {
            $this->error('No inventory. Run `node tests/Support/dashboard-inventory.mjs` first (signed in with admin:disposable).');

            return self::FAILURE;
        }

        $inventory = json_decode((string) file_get_contents($path), true);
        $articles = KnowledgeBase::all();

        // All article text per language, label marks removed, for "is this control named anywhere?".
        $corpus = [];
        foreach (['en', 'ar'] as $locale) {
            $corpus[$locale] = HelpText::plain(implode("\n", array_map(
                fn (array $a): string => (string) json_encode($a[$locale], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $articles,
            )));
            $corpus[$locale] = stripslashes($corpus[$locale]);
        }

        $rows = [];
        $gaps = [];
        $totals = ['controls' => 0, 'documented' => 0];

        foreach (self::MODULES as $module => [$prefix, $address]) {
            $controls = ['en' => [], 'ar' => []];

            foreach (['en', 'ar'] as $locale) {
                foreach ($inventory['locales'][$locale]['screens'] as $key => $screen) {
                    if ($key !== $prefix && ! str_starts_with($key, $prefix.'.')) {
                        continue;
                    }

                    foreach ($this->controlsOf($screen, $locale) as $control) {
                        $controls[$locale][$control] = true;
                    }

                    foreach ($screen['dialogs'] ?? [] as $dialog) {
                        if (is_array($dialog)) {
                            foreach ($this->controlsOf($dialog, $locale) as $control) {
                                $controls[$locale][$control] = true;
                            }
                        }
                    }
                }
            }

            $named = ['en' => [], 'ar' => []];
            foreach (['en', 'ar'] as $locale) {
                foreach (array_keys($controls[$locale]) as $control) {
                    if (str_contains($corpus[$locale], $control)) {
                        $named[$locale][] = $control;
                    } else {
                        $gaps[$module][$locale][] = $control;
                    }
                }
            }

            $tutorials = array_filter($articles, fn (array $a): bool => str_starts_with($a['open'], $address) && ($address !== '/admin' || $a['open'] === '/admin'));
            $workflow = array_filter($tutorials, fn (array $a): bool => $a['verified']['how'] === 'workflow');
            $words = [];
            foreach ($tutorials as $a) {
                foreach (['en', 'ar'] as $l) {
                    foreach (preg_split('/\s+/u', $a[$l]['keywords']) ?: [] as $w) {
                        $words[$w] = true;
                    }
                }
            }
            $hints = count(array_filter(self::HINT_MODULES, fn (array $modules): bool => in_array($module, $modules, true)));

            $phoneScreens = array_filter(self::PHONE_CHECKED, fn (string $k): bool => $k === $prefix || str_starts_with($k, $prefix.'.'));
            $phone = match (true) {
                in_array($module, self::PHONE_WORKFLOW, true) => 'workflow on a phone + layout',
                $phoneScreens !== [] => 'layout at 320/390/768',
                default => 'not checked',
            };

            $count = count($controls['en']) + count($controls['ar']);
            $done = count($named['en']) + count($named['ar']);
            $totals['controls'] += $count;
            $totals['documented'] += $done;

            $rows[] = [
                $module,
                count($controls['en']).' / '.count($controls['ar']),
                count($named['en']).' / '.count($named['ar']),
                $count > 0 ? round($done / $count * 100).'%' : '—',
                count($tutorials),
                count($workflow),
                count($words),
                $hints,
                $phone,
            ];
        }

        $this->write($rows, $gaps, $totals, $inventory, $articles);
        $this->table(['Module', 'Controls en/ar', 'Named en/ar', '%', 'Tutorials', 'Performed', 'Search words', 'Hints', 'Phone'], $rows);
        $this->info(sprintf('Overall: %d of %d controls named (%d%%). Written %s', $totals['documented'], $totals['controls'], round($totals['documented'] / max(1, $totals['controls']) * 100), $this->option('out')));

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function controlsOf(array $screen, string $locale): array
    {
        $out = [];

        foreach (['fields', 'sections'] as $kind) {
            foreach ($screen[$kind] ?? [] as $label) {
                $out[] = trim((string) $label);
            }
        }

        foreach ($screen['buttons'] ?? [] as $label) {
            $label = trim((string) $label);

            // An action is a button whose words are an interface string; anything else is a row's data.
            // Counts ("4 uses", "Select all 9") and pattern matches ("Remove Landscape") carry data, not a control's name.
            if ($label !== '' && ! in_array($label, self::FURNITURE[$locale], true) && ! preg_match('/\d/u', $label) && InterfaceLabels::isExact($label, $locale)) {
                $out[] = $label;
            }
        }

        return array_values(array_unique(array_filter($out, fn (string $l): bool => $l !== '' && ! in_array($l, self::FURNITURE[$locale], true))));
    }

    private function write(array $rows, array $gaps, array $totals, array $inventory, array $articles): void
    {
        $workflow = array_filter($articles, fn (array $a): bool => $a['verified']['how'] === 'workflow');
        $byScript = [];
        foreach ($workflow as $id => $a) {
            $byScript[basename($a['verified']['by'])][] = $id;
        }

        $percent = round($totals['documented'] / max(1, $totals['controls']) * 100);

        $md = "# Help Center coverage\n\n";
        $md .= "Measured, not estimated: `php artisan help:coverage`, from the live dashboard inventory taken by `tests/Support/dashboard-inventory.mjs` (commit {$inventory['commit']}, {$inventory['generated']}). Regenerate both after any dashboard change.\n\n";
        $md .= '**'.count($articles).' articles** in English and Arabic; **'.count($workflow)." performed end to end** in the real dashboard by a browser check; the rest have every on-screen word they quote checked against the dashboard's own strings (`HelpKnowledgeBaseTest`).\n\n";
        $md .= "**Controls named in Help: {$totals['documented']} of {$totals['controls']} ({$percent}%)**, counting English and Arabic separately. This is **not 100%**: see *What is not covered* below.\n\n";
        $lessons = count(KnowledgeBase::path());
        $topics = count(KnowledgeBase::categories());
        $hints = count(HelpHint::ARTICLES);
        $kinds = array_count_values(array_column($articles, 'kind'));
        $md .= "## What the Help Center is\n\n";
        $md .= "- **Where:** System → Help (`/admin/help`), in the dashboard's language. Every view has its own address: `?q=` search, `?topic=`, `?article=`, `?guide=1`.\n";
        $md .= '- **Home:** “What would you like to do?” / «تحب تعمل إيه في موقعك؟», a large search box, '.count(KnowledgeBase::popular())." popular tasks, {$topics} topics, a {$lessons}-lesson path *Learn to manage your website from zero*, the troubleshooting list, and the full guide.\n";
        $md .= '- **Articles:** '.count($articles).' — '.($kinds['task'] ?? 0).' tasks, '.($kinds['lesson'] ?? 0).' lessons, '.($kinds['trouble'] ?? 0)." problems. Each has, in English and Arabic: what you will accomplish, before you begin, where, numbered steps (with a keyboard-accessible *Guide me step by step* mode), what the fields mean, a check-that-it-worked list, problems and fixes, warnings, related tasks, time, level and who may do it. Words on screen are marked and must exist in the dashboard in that language.\n";
        $md .= "- **Search:** English, Arabic and Egyptian Arabic; Arabic letter forms and diacritics normalised; request filler dropped (“how do I”, «ازاي», «عايز»); light stemming; partial words and typos; meanings through a synonym table; specific tasks rank above the long guide; *remove* / «شيل» is answered with a choice between hiding, deleting, deleting for good and restoring. Tested by `tests/Unit/HelpSearchTest.php` (26 real queries) and in the browser by `tests/Support/help-check.mjs`.\n";
        $md .= "- **Contextual help:** {$hints} **?** buttons beside the fields most often misread (status, publish date, slug, SEO title and description, alt text, featured, price, and the seven lighting controls); each explains the field and opens its article.\n";
        $md .= "- **Generated, so it cannot drift:** *Every sentence in Site settings, section by section* is built from the same registry and labels as the Settings page.\n\n";
        $md .= "## By module\n\n";
        $md .= "*Controls*: the fields, sections and actions on the module's screens and dialogs (table furniture and row data excluded). *Named*: how many of them an article names, in that language. *Tutorials*: articles that start on the module. *Performed*: of those, done end to end by a browser check. *Hints*: contextual **?** buttons in the module. *Phone*: what was checked at phone width.\n\n";
        $md .= "| Module | Controls en/ar | Named en/ar | % | Tutorials | Performed | Search words | Hints | Phone |\n| --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";
        foreach ($rows as $row) {
            $md .= '| '.implode(' | ', $row)." |\n";
        }

        $md .= "\n## Tutorials performed in the dashboard\n\n";
        foreach ($byScript as $script => $ids) {
            $md .= "- `tests/Support/{$script}`: ".implode(', ', array_map(fn ($i) => "`{$i}`", $ids))."\n";
        }

        $md .= "\n## What is not covered\n\n";
        $md .= "Controls on these screens that no article names, in each language. Most are self-explanatory (a list's own column of the same name) or are explained as a class rather than one by one — Site settings has 171 text fields, and “Change a word, a letter or a sentence anywhere on the site” teaches how to find any of them by its label rather than listing each.\n\n";
        foreach ($gaps as $module => $byLocale) {
            $en = $byLocale['en'] ?? [];
            $ar = $byLocale['ar'] ?? [];
            if ($en === [] && $ar === []) {
                continue;
            }
            $show = fn (array $list): string => $list === [] ? '—' : implode(' · ', array_slice($list, 0, 14)).(count($list) > 14 ? ' · … ('.(count($list) - 14).' more)' : '');
            $md .= "- **{$module}** — en: ".$show($en).'; ar: '.$show($ar)."\n";
        }

        $md .= "\n## Other limits\n\n";
        $md .= "- The inventory opens each screen and its main dialogs; controls that appear only after an interaction (inside a collapsed menu link, the story builder's block editors, the picker) are documented from the code and the story builder's own strings, not crawled.\n";
        $md .= "- Phone verification is browser emulation at 320, 390 and 768 px, not real devices.\n";
        $md .= "- No screenshots are embedded in the articles; they name the exact words on screen instead, which a test keeps true.\n";

        file_put_contents(base_path((string) $this->option('out')), $md);
    }
}
