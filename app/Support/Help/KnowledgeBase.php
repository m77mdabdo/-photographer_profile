<?php

declare(strict_types=1);

namespace App\Support\Help;

use App\Support\SettingGroups;
use App\Support\SiteCopy;
use Illuminate\Support\Str;

/**
 * The Help Center's articles, read from resources/help.
 *
 * Structured data rather than one long document (Help Center directive,
 * Part 2): each article is a task with an id, a category, steps, field
 * explanations, checks, troubleshooting and search words, in English and in
 * Arabic, side by side in one array so the two cannot drift apart unnoticed.
 *
 * The long-form guide (docs/DASHBOARD_USER_GUIDE_EN|AR.md) is still served,
 * as the "Full guide", and its sections are indexed for search as reference
 * entries — so there is one source for each, and no third copy.
 */
final class KnowledgeBase
{
    public const KINDS = ['task', 'lesson', 'trouble'];

    public const LEVELS = ['beginner', 'everyday', 'advanced'];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $articles = null;

    /** @var array<string, mixed>|null */
    private static ?array $shelves = null;

    /** @return array<string, array<string, mixed>> every article, by id */
    public static function all(): array
    {
        if (self::$articles !== null) {
            return self::$articles;
        }

        $articles = [];

        foreach (glob(resource_path('help/articles/*.php')) ?: [] as $file) {
            foreach ((array) require $file as $article) {
                $articles[$article['id']] = $article;
            }
        }

        $articles['settings-reference'] = self::settingsReference();

        return self::$articles = $articles;
    }

    /**
     * “Every sentence in Site settings, section by section” — generated, not
     * written: from the same registry (App\Support\SiteCopy) and labels
     * (lang/{locale}/setting_labels.php) the Settings page is built from, so
     * it names every field exactly as the screen does and cannot fall behind.
     *
     * @return array<string, mixed>
     */
    private static function settingsReference(): array
    {
        $groups = [];

        foreach (SiteCopy::defaults() as $row) {
            $groups[$row['group']][] = $row['key'];
        }

        $block = function (string $locale) use ($groups): array {
            $previous = app()->getLocale();
            app()->setLocale($locale);

            $steps = [[
                'title' => $locale === 'ar' ? 'كيف تستخدم هذه الخريطة' : 'How to use this map',
                'body' => $locale === 'ar'
                    ? 'ابحث أدناه عن الجملة التي تريدها، وافتح [[إعدادات الموقع]]، واضغط عنوان ذلك القسم لفتحه، وغيّر الحقل في تبويب اللغة الذي تحتاجه، ثم اضغط [[حفظ الإعدادات]].'
                    : 'Find the sentence you want below, open [[Site settings]], press that section’s title to open it, change the field in the language tab you need, and press [[Save settings]].',
            ]];

            foreach (SettingGroups::sorted(array_keys($groups)) as $group) {
                $labels = array_map(
                    fn (string $key): string => '- [['.__('setting_labels.'.str_replace('.', '_', $key)).']]',
                    $groups[$group],
                );
                $steps[] = ['title' => SettingGroups::title($group), 'body' => implode("\n", $labels)];
            }

            app()->setLocale($previous);

            return $locale === 'ar' ? [
                'title' => 'كل جمل إعدادات الموقع، قسمًا قسمًا',
                'summary' => 'خريطة لإعدادات الموقع: كل قسم وكل حقل فيه، باسمه كما يظهر على الشاشة.',
                'outcome' => 'تجد حقل أي جملة في الموقع خلال ثوانٍ.',
                'before' => [],
                'path' => 'النظام ← إعدادات الموقع',
                'steps' => $steps,
                'fields' => [],
                'verify' => ['الصفحة العامة تعرض تغييرك.'],
                'trouble' => [],
                'warnings' => ['الصور (صورة المشاركة، أيقونة التبويب، الصورة الشخصية) تُختار في تبويب English وتُستخدم للغتين.'],
                'keywords' => 'اعدادات الموقع كل الحقول خريطة الاعدادات فين الحقل نصوص الموقع صفحة الخطأ 404 صفحة غير موجودة الصيانة نص زر الارسال خانة الموافقة حقول النموذج',
            ] : [
                'title' => 'Every sentence in Site settings, section by section',
                'summary' => 'A map of Site settings: each section and every field in it, named exactly as on screen.',
                'outcome' => 'You can find the field for any sentence on the site in seconds.',
                'before' => [],
                'path' => 'System → Site settings',
                'steps' => $steps,
                'fields' => [],
                'verify' => ['The public page shows your change.'],
                'trouble' => [],
                'warnings' => ['Images (sharing image, browser tab icon, About portrait) are chosen on the English tab and used for both languages.'],
                'keywords' => 'site settings all fields settings map where is the field site text wording list error page 404 not found page 500 maintenance page consent checkbox form fields send button labels',
            ];
        };

        return [
            'id' => 'settings-reference',
            'category' => 'text',
            'kind' => 'lesson',
            'level' => 'everyday',
            'minutes' => 5,
            'roles' => 'all',
            'open' => '/admin/settings',
            'intents' => ['settings', 'text', 'edit'],
            'related' => ['change-a-word', 'edit-home-text', 'update-contact-info', 'edit-footer'],
            'verified' => ['how' => 'labels'],
            'en' => $block('en'),
            'ar' => $block('ar'),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $id): ?array
    {
        if (str_starts_with($id, 'guide-')) {
            return self::guideSection((int) substr($id, 6));
        }

        return self::all()[$id] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    public static function categories(): array
    {
        return self::shelves()['categories'];
    }

    /** @return list<string> */
    public static function popular(): array
    {
        return self::shelves()['popular'];
    }

    /** @return list<string> */
    public static function path(): array
    {
        return self::shelves()['path'];
    }

    /** @return list<array<string, mixed>> the articles on a shelf, tasks first */
    public static function inCategory(string $category): array
    {
        $order = array_flip(self::KINDS);

        $list = array_values(array_filter(self::all(), fn (array $a): bool => $a['category'] === $category));
        usort($list, fn (array $a, array $b): int => [$order[$a['kind']], $a['minutes']] <=> [$order[$b['kind']], $b['minutes']]);

        return $list;
    }

    /**
     * The words of an article in one language.
     *
     * @return array<string, mixed>
     */
    public static function text(array $article, string $locale): array
    {
        return $article[$locale] ?? $article['en'];
    }

    /**
     * The long-form guide's sections, as reference entries: the same shape
     * as an article's language block, with the section's HTML as its body.
     *
     * @return list<array<string, mixed>>
     */
    public static function guide(string $locale): array
    {
        $file = base_path('docs/DASHBOARD_USER_GUIDE_'.($locale === 'ar' ? 'AR' : 'EN').'.md');

        if (! is_file($file)) {
            return [];
        }

        $parts = preg_split('/^## /m', (string) file_get_contents($file)) ?: [];
        array_shift($parts);   // the title and the introduction above section 1

        $sections = [];

        foreach ($parts as $i => $part) {
            [$title, $body] = array_pad(explode("\n", $part, 2), 2, '');
            $body = (string) preg_replace('#</?div[^>]*>|^---\s*$#m', '', $body);

            $sections[] = [
                'id' => 'guide-'.($i + 1),
                'number' => $i + 1,
                'title' => trim($title),
                'html' => Str::markdown($body, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
                'plain' => trim(strip_tags(Str::markdown($body, ['html_input' => 'strip']))),
            ];
        }

        return $sections;
    }

    /** @return array<string, mixed>|null a guide section dressed as an article */
    private static function guideSection(int $number): ?array
    {
        $en = self::guide('en')[$number - 1] ?? null;
        $ar = self::guide('ar')[$number - 1] ?? null;

        if ($en === null) {
            return null;
        }

        return [
            'id' => 'guide-'.$number,
            'category' => 'guide',
            'kind' => 'reference',
            'en' => ['title' => $en['title'], 'html' => $en['html']],
            'ar' => ['title' => $ar['title'] ?? $en['title'], 'html' => $ar['html'] ?? $en['html']],
        ];
    }

    /** @return array<string, mixed> */
    private static function shelves(): array
    {
        return self::$shelves ??= (array) require resource_path('help/categories.php');
    }

    /** For tests that write articles to a temporary directory. */
    public static function forget(): void
    {
        self::$articles = null;
        self::$shelves = null;
    }
}
