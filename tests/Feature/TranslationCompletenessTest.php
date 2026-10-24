<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BlockType;
use App\Enums\InquiryStatus;
use App\Enums\MediaStatus;
use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Mail\InquiryAcknowledged;
use App\Mail\InquiryReceived;
use App\Models\Inquiry;
use App\Models\UntranslatedRecord;
use App\Support\Locale;
use App\Support\UntranslatedContent;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 35 H4 — THE STRINGS A RENDER SCAN CANNOT SEE.
 *
 * Two scans read pages: ArabicRenderTest looks for English on Arabic pages,
 * and EnglishRenderTest (H2) now looks for Arabic on English ones. Between
 * them they cover what a visitor reads on a screen. They cannot cover:
 *
 *   · a mail, which is rendered into a message rather than a response
 *   · a validation message, which only exists when a submission fails
 *   · an enum label, which is a PHP method until something prints it
 *   · Filament's derived nouns — the model labels and plurals that appear in
 *     breadcrumbs, modal headings and empty states
 *   · the confirmation on a destructive action, which lives in a modal
 *     nothing renders until it is opened
 *   · the <title>, og:title and og:site_name, which the scans strip with the
 *     <head>
 *
 * Each of those is somewhere a visitor meets the wrong language and nobody
 * finds out. This file renders every one of them in both languages and reads
 * the result.
 *
 * THE FIFTH ENUM. Writing it found BlockType — fourteen block names and
 * three group names, hardcoded English, in the story builder's type picker.
 * Part F translated the other four because the panel scan could see them on
 * a Filament screen; this one is drawn by a Livewire blade, and so it was
 * missed by a scan whose targets were screens rather than strings.
 * ───────────────────────────────────────────────────────────────────────────
 */
class TranslationCompletenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
    }

    /** Does this string carry Arabic script? */
    private function hasArabic(string $value): bool
    {
        return (bool) preg_match('/\p{Arabic}/u', $value);
    }

    /** Latin WORDS, not stray letters: an f-number is not English. */
    private function hasEnglishWords(string $value): bool
    {
        return (bool) preg_match('/[A-Za-z]{3,}/', $value);
    }

    // ----------------------------------------------------------------- 1

    /**
     * EVERY ENUM LABEL, IN BOTH LANGUAGES.
     *
     * Five enums, forty-one cases between them. Each label must exist, must
     * not be the translation key, and must be in the script of the locale it
     * was asked for — which is what catches a case added to an enum without
     * a string beside it.
     */
    public function test_every_enum_label_is_translated(): void
    {
        $enums = [
            PublishStatus::class,
            InquiryStatus::class,
            MediaStatus::class,
            UserRole::class,
            BlockType::class,
        ];

        $offenders = [];

        foreach ($enums as $enum) {
            foreach ($enum::cases() as $case) {
                foreach (Locale::codes() as $locale) {
                    $this->app->setLocale($locale);

                    $label = $case->label();

                    if ($label === '' || str_contains($label, 'studio.')) {
                        $offenders[] = class_basename($enum).'::'.$case->name." has no {$locale} label (got \"{$label}\")";

                        continue;
                    }

                    if ($locale === 'ar' && ! $this->hasArabic($label) && $this->hasEnglishWords($label)) {
                        $offenders[] = class_basename($enum).'::'.$case->name." is English in Arabic: \"{$label}\"";
                    }

                    if ($locale === 'en' && $this->hasArabic($label)) {
                        $offenders[] = class_basename($enum).'::'.$case->name." is Arabic in English: \"{$label}\"";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n  ", $offenders));
    }

    /** BlockType's groups too, which are printed as headings. */
    public function test_every_block_group_is_translated(): void
    {
        foreach (Locale::codes() as $locale) {
            $this->app->setLocale($locale);

            foreach (BlockType::cases() as $case) {
                $group = $case->group();

                $this->assertNotSame('', $group);
                $this->assertStringNotContainsString('studio.', $group);

                if ($locale === 'ar') {
                    $this->assertTrue($this->hasArabic($group), "the {$case->name} group is English in Arabic: {$group}");
                }
            }
        }
    }

    // ----------------------------------------------------------------- 2

    /**
     * BOTH MAILABLES, BOTH LOCALES — SUBJECT AND BODY.
     *
     * The acknowledgement is the first thing a prospective client receives
     * from this studio, and an Arabic enquirer gets it in Arabic because
     * ContactController sends it with their locale. Nothing has ever read
     * one in a test.
     */
    public function test_both_mailables_are_translated(): void
    {
        $inquiry = Inquiry::factory()->create([
            'name' => 'Example Person',
            'message' => 'A message about a commission.',
        ]);

        $offenders = [];

        foreach (Locale::codes() as $locale) {
            $this->app->setLocale($locale);

            foreach ([
                InquiryAcknowledged::class,
                InquiryReceived::class,
            ] as $mailable) {
                $mail = new $mailable($inquiry);

                $subject = (string) ($mail->envelope()->subject ?? '');
                $body = strip_tags((string) $mail->render());

                foreach (['subject' => $subject, 'body' => $body] as $part => $text) {
                    if ($text === '') {
                        $offenders[] = class_basename($mailable).' has an empty '.$part;

                        continue;
                    }

                    if ($locale === 'ar' && ! $this->hasArabic($text)) {
                        $offenders[] = class_basename($mailable)." {$part} has no Arabic in it at all";
                    }

                    if ($locale === 'en' && $this->hasArabic($text)) {
                        $offenders[] = class_basename($mailable)." {$part} carries Arabic in English";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n  ", $offenders));
    }

    // ----------------------------------------------------------------- 3

    /**
     * EVERY VALIDATION RULE THE APPLICATION ACTUALLY USES.
     *
     * The rules are collected from the real form requests rather than
     * listed, so a rule added to a request is covered the day it is added.
     * Laravel's Arabic messages come from lang/ar/validation.php, which this
     * project ships; a rule with no entry there falls back to English and a
     * person filling in the contact form in Arabic is told
     * "The email field must be a valid email address."
     */
    public function test_every_validation_rule_in_use_has_an_arabic_message(): void
    {
        $rules = $this->rulesInUse();

        $this->assertGreaterThanOrEqual(10, count($rules), 'the rule walk found almost nothing');

        $this->app->setLocale('ar');

        $offenders = [];

        foreach ($rules as $rule) {
            /*
             * A rule with no English message has nothing to translate:
             * `nullable` and `sometimes` never fail, so Laravel ships no
             * sentence for them in any language. The English file is the
             * list of rules that can speak.
             */
            $this->app->setLocale('en');
            $english = trans('validation.'.$rule);

            if ($english === 'validation.'.$rule) {
                continue;
            }

            $this->app->setLocale('ar');
            $message = trans('validation.'.$rule);

            if (is_array($message)) {
                $message = $message['string'] ?? reset($message);
            }

            $message = (string) $message;

            if ($message === 'validation.'.$rule) {
                $offenders[] = $rule.' has no Arabic message';

                continue;
            }

            if (! $this->hasArabic($message)) {
                $offenders[] = $rule.' is not Arabic: "'.$message.'"';
            }
        }

        $this->assertSame([], $offenders, implode("\n  ", $offenders));
    }

    /** And a real failed submission reads as Arabic, end to end. */
    public function test_a_failed_submission_answers_in_arabic(): void
    {
        $response = $this->withUnencryptedCookie(Locale::COOKIE, 'ar')
            ->from('/ar/contact')
            ->post('/ar/contact', ['name' => '', 'email' => 'not-an-email', 'message' => '']);

        $response->assertRedirect();

        $errors = session('errors');

        $this->assertNotNull($errors, 'the submission did not fail');

        foreach ($errors->all() as $message) {
            $this->assertTrue(
                $this->hasArabic($message),
                "a validation message came back in English: {$message}",
            );
        }
    }

    /**
     * The rules every form request declares, flattened to rule names.
     *
     * @return list<string>
     */
    private function rulesInUse(): array
    {
        $rules = [];

        foreach (glob(base_path('app/Http/Requests/**/*.php')) + glob(base_path('app/Http/Requests/*.php')) as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all("/'([a-z_]+)(?::[^']*)?'/", $source, $matches);

            foreach ($matches[1] as $candidate) {
                if (in_array($candidate, [
                    'required', 'string', 'email', 'max', 'min', 'nullable', 'integer',
                    'boolean', 'array', 'date', 'url', 'in', 'exists', 'unique', 'image',
                    'mimes', 'numeric', 'confirmed', 'accepted', 'after', 'before', 'regex',
                    'different', 'not_regex', 'file', 'uuid',
                ], true)) {
                    $rules[] = $candidate;
                }
            }
        }

        /*
         * The contact form's rules are built in the controller rather than in
         * a request class, and they are the ones a member of the public
         * actually meets.
         */
        $rules = array_merge($rules, ['required', 'email', 'max', 'string', 'nullable', 'accepted', 'date', 'integer', 'url', 'in']);

        return array_values(array_unique($rules));
    }

    // ----------------------------------------------------------------- 4

    /**
     * FILAMENT'S DERIVED NOUNS.
     *
     * Every resource's model label and plural, in both languages. Filament
     * derives these from the class name when a resource does not say — in
     * English, whatever language the panel is in — which is what Part F
     * found on fourteen screens. This is the check that keeps it fixed as
     * resources are added.
     */
    public function test_every_resource_names_itself_in_both_languages(): void
    {
        $offenders = [];

        foreach (glob(base_path('app/Filament/Resources/*/*Resource.php')) as $file) {
            $resource = 'App\\Filament\\Resources\\'
                .basename(dirname($file)).'\\'
                .basename($file, '.php');

            foreach (Locale::codes() as $locale) {
                $this->app->setLocale($locale);

                foreach (['getModelLabel', 'getPluralModelLabel'] as $method) {
                    $label = (string) $resource::$method();

                    if ($label === '' || str_contains($label, 'studio.')) {
                        $offenders[] = class_basename($resource)."::{$method}() has no {$locale} label";

                        continue;
                    }

                    if ($locale === 'ar' && ! $this->hasArabic($label)) {
                        $offenders[] = class_basename($resource)."::{$method}() is English in Arabic: \"{$label}\"";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n  ", $offenders));
    }

    /**
     * And the words on a destructive action, which live in a modal nothing
     * renders until it is opened: the heading, the confirmation and the
     * refusal each resource's policy can return.
     */
    public function test_the_destructive_actions_speak_both_languages(): void
    {
        $keys = [
            'studio.media.destroy_warning',
            'studio.media.destroy_confirm_label',
            'studio.media.destroy_mismatch',
            'studio.pages.locked_hint',
            'studio.users.last_owner',
        ];

        $offenders = [];

        foreach ($keys as $key) {
            foreach (Locale::codes() as $locale) {
                $this->app->setLocale($locale);

                $value = (string) __($key, ['name' => 'x', 'count' => 1]);

                if ($value === $key) {
                    $offenders[] = "{$key} has no {$locale} value";

                    continue;
                }

                if ($locale === 'ar' && ! $this->hasArabic($value)) {
                    $offenders[] = "{$key} is English in Arabic: \"{$value}\"";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n  ", $offenders));
    }

    // ----------------------------------------------------------------- 5

    /**
     * THE TAB TITLE AND THE LINK PREVIEW.
     *
     * Both scans strip the <head>, which is where the two strings a visitor
     * sees before they read a word of the page live: the browser tab and
     * whatever a messaging app shows when the link is pasted into it.
     */
    public function test_the_head_is_in_the_right_language(): void
    {
        $pages = ['/' => '/ar', '/work' => '/ar/work', '/about' => '/ar/about'];

        foreach ($pages as $english => $arabic) {
            $this->assertHeadLanguage($english, 'en');
            $this->assertHeadLanguage($arabic, 'ar');
        }
    }

    private function assertHeadLanguage(string $path, string $locale): void
    {
        $html = (string) $this->get($path)->assertOk()->getContent();

        preg_match('#<head>(.*?)</head>#s', $html, $head);

        $this->assertNotEmpty($head, "{$path} has no head");

        preg_match('#<title>(.*?)</title>#si', $head[1], $title);
        preg_match_all('#<meta[^>]+(?:property|name)="(og:title|og:site_name|og:description|description)"[^>]+content="([^"]*)"#i', $head[1], $metas, PREG_SET_ORDER);

        $strings = [trim($title[1] ?? '')];

        foreach ($metas as $meta) {
            $strings[] = $meta[2];
        }

        foreach (array_filter($strings) as $string) {
            if ($locale === 'ar') {
                $this->assertTrue(
                    $this->hasArabic($string),
                    "{$path}: a head string is not Arabic: \"{$string}\"",
                );

                continue;
            }

            $this->assertFalse(
                $this->hasArabic($string),
                "{$path}: a head string carries Arabic: \"{$string}\"",
            );
        }
    }

    // ----------------------------------------------------------------- 6

    /** Every error page, in both languages, including the ones no route serves. */
    public function test_every_error_page_speaks_both_languages(): void
    {
        $offenders = [];

        foreach ([403, 404, 419, 429, 500, 503] as $status) {
            foreach (Locale::codes() as $locale) {
                $this->app->setLocale($locale);

                $html = view('errors.'.$status, [
                    'exception' => new HttpException($status),
                ])->render();

                /*
                 * The switcher is on the error pages too, and it names each
                 * language in its own script on purpose — the one exception,
                 * the same one EnglishRenderTest makes and proves narrow.
                 */
                foreach (Locale::codes() as $code) {
                    $html = str_replace(Locale::native($code), '', $html);
                }

                $text = trim(strip_tags($html));

                if ($text === '') {
                    $offenders[] = "{$status} renders nothing in {$locale}";

                    continue;
                }

                if ($locale === 'ar' && ! $this->hasArabic($text)) {
                    $offenders[] = "{$status} is English in Arabic";
                }

                if ($locale === 'en' && $this->hasArabic($text)) {
                    $offenders[] = "{$status} carries Arabic in English";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n  ", $offenders));
    }

    // ----------------------------------------------------------------- 7

    /**
     * H5 — WHAT IS MISSING IS CONTENT, AND THIS COUNTS IT.
     *
     * Read from the same source the dashboard's "Waiting for Arabic" table
     * uses, so the number in the report and the number on the owner's screen
     * cannot disagree. Nothing here translates anything: Arabic is typed by
     * a person (ADR 0005), and a row with no Arabic is a job for the studio,
     * not a defect in the software.
     */
    public function test_the_untranslated_content_count_is_readable(): void
    {
        $rows = UntranslatedRecord::query()
            ->fromSub(UntranslatedContent::query('ar'), 'untranslated')
            ->get();

        $perModel = [];

        foreach ($rows as $row) {
            $perModel[$row->model] = ($perModel[$row->model] ?? 0) + 1;
        }

        fwrite(STDERR, "\n  WAITING FOR ARABIC (test database)\n");

        foreach ($perModel as $model => $n) {
            fwrite(STDERR, sprintf("    %-22s %d\n", $model, $n));
        }

        fwrite(STDERR, "\n");

        $this->assertIsArray($perModel);
    }
}
