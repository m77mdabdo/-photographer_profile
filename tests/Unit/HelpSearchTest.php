<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Help\HelpSearch;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Help Center search (Help Center directive, Parts 4 and 17): the query a
 * person types finds the task they mean, in English, Arabic or Egyptian
 * Arabic, misspelt or half typed.
 */
class HelpSearchTest extends TestCase
{
    private function top(string $query, string $locale = 'en', int $within = 1): array
    {
        return array_column(array_slice(HelpSearch::search($query, $locale)['results'], 0, $within), 'id');
    }

    public function test_arabic_letter_forms_and_diacritics_are_one_word(): void
    {
        $this->assertSame(HelpSearch::normalize('صورة'), HelpSearch::normalize('صوره'));
        $this->assertSame(HelpSearch::normalize('إضافة'), HelpSearch::normalize('اضافه'));
        $this->assertSame(HelpSearch::normalize('الإضَاءَة'), HelpSearch::normalize('الاضاءه'));
        $this->assertSame(HelpSearch::normalize('مستشفى'), HelpSearch::normalize('مستشفي'));
        $this->assertSame('2026', HelpSearch::normalize('٢٠٢٦'));
        $this->assertSame('change the home photo', HelpSearch::normalize('Change the HOME-photo!'));
    }

    public function test_request_filler_is_dropped_and_words_are_stemmed(): void
    {
        $this->assertSame(['اغير', 'كلمه'], HelpSearch::tokens('عاوز ازاي اغير كلمة'));
        $this->assertNotContains('ازاي', HelpSearch::tokens('ازاي اغير الصورة'));
        $this->assertNotContains('how', HelpSearch::tokens('how do I change the photo'));
        $this->assertSame(HelpSearch::stem('photos'), HelpSearch::stem('photo'));
        $this->assertSame(HelpSearch::stem('publishing'), HelpSearch::stem('publish'));
        $this->assertSame(HelpSearch::stem('الصور'), HelpSearch::stem('صور'));
        $this->assertSame(HelpSearch::stem('بالصورة'), 'صورة');
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3?: int}> */
    public static function queries(): array
    {
        return [
            'one word, English' => ['change a word', 'en', 'change-a-word'],
            'one word, Egyptian' => ['عاوز اغير كلمة', 'ar', 'change-a-word'],
            'home photo' => ['replace homepage photo', 'en', 'replace-home-photo'],
            'home photo, Egyptian' => ['ازاي اغير صورة الرئيسية', 'ar', 'replace-home-photo'],
            'project cover' => ['change project cover', 'en', 'change-project-cover'],
            'project cover, Arabic' => ['اغير صورة الغلاف بتاعت المشروع', 'ar', 'change-project-cover'],
            'video' => ['upload a video', 'en', 'upload-video'],
            'video, Arabic' => ['ارفع فيديو', 'ar', 'upload-video'],
            'publish' => ['publish project', 'en', 'publish'],
            'publish, Egyptian' => ['خلي المشروع يظهر', 'ar', 'publish'],
            'restore' => ['restore deleted project', 'en', 'restore-item'],
            'restore, Egyptian' => ['رجع المشروع اللي اتمسح', 'ar', 'restore-item'],
            'seo' => ['edit seo', 'en', 'edit-seo'],
            'seo, Arabic' => ['سيو', 'ar', 'edit-seo'],
            'contact' => ['update contact information', 'en', 'update-contact-info'],
            'contact, Egyptian' => ['اغير الايميل اللي بتوصل عليه الطلبات', 'ar', 'update-contact-info'],
            'cannot sign in, Egyptian' => ['مش قادر ادخل', 'ar', 'cannot-sign-in'],
            'upload refused, Egyptian' => ['الصوره مش راضيه تترفع', 'ar', 'processing-stuck', 3],
            'lighting' => ['spotlight darkness', 'en', 'adjust-gallery-lighting'],
            'recovery codes' => ['recovery codes', 'en', 'recovery-codes'],
            'English in the Arabic panel' => ['change project cover', 'ar', 'change-project-cover'],
            'Arabic in the English panel' => ['ارفع فيديو', 'en', 'upload-video'],
            'typos' => ['chnage projcet cover', 'en', 'change-project-cover', 2],
            'partial words' => ['gall', 'en', 'adjust-gallery-lighting', 3],
            'a field name' => ['Exclusive spotlight', 'en', 'adjust-gallery-lighting', 2],
            'a dashboard section' => ['Cinematic sections', 'en', 'create-cinematic-gallery', 5],
        ];
    }

    #[DataProvider('queries')]
    public function test_a_query_finds_its_task(string $query, string $locale, string $expected, int $within = 1): void
    {
        $this->assertContains($expected, $this->top($query, $locale, $within), "“{$query}” should find {$expected}");
    }

    public function test_specific_tasks_rank_above_the_long_guide(): void
    {
        $results = HelpSearch::search('photographs', 'en')['results'];
        $first = $results[0];

        $this->assertNotSame('reference', $first['kind']);
    }

    public function test_remove_is_answered_with_a_choice_not_a_guess(): void
    {
        foreach ([['remove', 'en'], ['delete', 'en'], ['شيل', 'ar'], ['عايز اشيل المشروع', 'ar'], ['احذف', 'ar']] as [$q, $l]) {
            $choices = HelpSearch::search($q, $l)['choices'];
            $this->assertSame(['unpublish', 'delete-item', 'delete-permanently', 'restore-item'], $choices, $q);
        }

        $this->assertContains('delete-photo', HelpSearch::search('delete a photo', 'en')['choices']);
        $this->assertSame([], HelpSearch::search('hide the project', 'en')['choices'], 'hiding is already specific');
        $this->assertSame([], HelpSearch::search('restore deleted project', 'en')['choices']);
    }

    public function test_hide_finds_hiding_not_deleting(): void
    {
        $this->assertSame('unpublish', $this->top('hide project from site')[0]);
        $this->assertSame('unpublish', $this->top('اخفي المشروع من الموقع', 'ar')[0]);
    }

    public function test_empty_and_meaningless_queries_find_nothing(): void
    {
        $this->assertSame([], HelpSearch::search('', 'en')['results']);
        $this->assertSame([], HelpSearch::search('   ', 'en')['results']);
        $this->assertSame([], HelpSearch::search('how do I', 'en')['results']);
        $this->assertSame([], HelpSearch::search('zqxwvy', 'en')['results']);
        $this->assertSame([], HelpSearch::search('عايز ازاي', 'ar')['results']);
    }
}
