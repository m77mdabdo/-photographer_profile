<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Filament\Support\HelpHint;
use App\Support\Help\InterfaceLabels;
use App\Support\Help\KnowledgeBase;
use Tests\TestCase;

/**
 * The Help articles are data, and data can rot: a button is renamed, an
 * article is removed, a translation is left half done. These hold the
 * knowledge base to its own rules (Help Center directive, Parts 2, 16, 18).
 */
class HelpKnowledgeBaseTest extends TestCase
{
    private const TEXT_KEYS = ['title', 'summary', 'outcome', 'before', 'path', 'steps', 'fields', 'verify', 'trouble', 'warnings', 'keywords'];

    public function test_every_article_has_the_full_shape_in_both_languages(): void
    {
        $articles = KnowledgeBase::all();
        $this->assertGreaterThanOrEqual(50, count($articles));

        foreach ($articles as $id => $a) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $id);
            $this->assertArrayHasKey($a['category'], KnowledgeBase::categories(), "$id: unknown category");
            $this->assertContains($a['kind'], KnowledgeBase::KINDS, "$id: kind");
            $this->assertContains($a['level'], KnowledgeBase::LEVELS, "$id: level");
            $this->assertContains($a['roles'], ['all', 'owner'], "$id: roles");
            $this->assertIsInt($a['minutes']);
            $this->assertStringStartsWith('/', $a['open'], "$id: open must be a path");
            $this->assertContains($a['verified']['how'], ['workflow', 'labels'], "$id: verified");
            $this->assertNotEmpty($a['intents'], "$id: no intents — search cannot find it by meaning");

            if ($a['verified']['how'] === 'workflow') {
                $this->assertFileExists(base_path($a['verified']['by']), "$id: the check that verifies it is missing");
            }

            foreach (['en', 'ar'] as $locale) {
                $t = $a[$locale];

                foreach (self::TEXT_KEYS as $key) {
                    $this->assertArrayHasKey($key, $t, "$id.$locale.$key missing");
                }

                $this->assertNotSame('', trim($t['title']), "$id.$locale: title");
                $this->assertNotSame('', trim($t['summary']), "$id.$locale: summary");
                $this->assertNotEmpty($t['steps'], "$id.$locale: no steps");
                $this->assertSame(count($a['en']['steps']), count($t['steps']), "$id: the two languages have different numbers of steps");
                $this->assertSame(count($a['en']['trouble']), count($t['trouble']), "$id: different numbers of problems");

                foreach ($t['steps'] as $n => $step) {
                    $this->assertNotSame('', trim($step['title']), "$id.$locale step $n: title");
                    $this->assertGreaterThan(15, mb_strlen($step['body']), "$id.$locale step $n is too thin to follow");
                }
            }

            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $a['ar']['title'].$a['ar']['summary'], "$id: Arabic block is not Arabic");
        }
    }

    public function test_every_label_an_article_quotes_exists_on_screen_in_that_language(): void
    {
        $missing = [];

        foreach (KnowledgeBase::all() as $id => $a) {
            foreach (['en', 'ar'] as $locale) {
                $text = json_encode($a[$locale], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                foreach (InterfaceLabels::cited(stripslashes((string) $text)) as $label) {
                    if (! InterfaceLabels::has($label, $locale)) {
                        $missing[] = "$locale $id: [[$label]]";
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'Help quotes words the dashboard does not show');
    }

    public function test_the_label_check_is_not_a_rubber_stamp(): void
    {
        $this->assertTrue(InterfaceLabels::has('Save changes', 'en'));
        $this->assertTrue(InterfaceLabels::has('حفظ التغييرات', 'ar'));
        $this->assertFalse(InterfaceLabels::has('Save changes', 'ar'), 'English quoted in an Arabic article');
        $this->assertFalse(InterfaceLabels::has('Save setting', 'en'));
        $this->assertFalse(InterfaceLabels::has('حفظ الاعدادات', 'ar'), 'a missing hamza is a different word');
    }

    public function test_references_resolve(): void
    {
        $ids = array_keys(KnowledgeBase::all());

        foreach (KnowledgeBase::all() as $id => $a) {
            foreach ($a['related'] as $related) {
                $this->assertContains($related, $ids, "$id relates to a missing article");
                $this->assertNotSame($id, $related);
            }
        }

        foreach ([...KnowledgeBase::popular(), ...KnowledgeBase::path()] as $listed) {
            $this->assertContains($listed, $ids, "$listed is listed but does not exist");
        }

        $this->assertCount(14, KnowledgeBase::path(), 'the learning path is fourteen lessons');

        foreach (KnowledgeBase::categories() as $key => $category) {
            $this->assertNotEmpty(KnowledgeBase::inCategory($key), "topic $key is empty");
            $this->assertNotSame('', $category['ar']['title']);
        }
    }

    public function test_every_contextual_help_button_links_to_an_article_and_has_both_texts(): void
    {
        foreach (HelpHint::ARTICLES as $topic => $article) {
            $this->assertNotNull(KnowledgeBase::find($article), "hint $topic links to missing article $article");

            foreach (['en', 'ar'] as $locale) {
                app()->setLocale($locale);
                $this->assertNotSame('studio.help_hint.'.$topic.'.title', __('studio.help_hint.'.$topic.'.title'), "$locale hint $topic has no title");
                $this->assertNotSame('studio.help_hint.'.$topic.'.body', __('studio.help_hint.'.$topic.'.body'), "$locale hint $topic has no body");
            }
        }
    }

    public function test_the_tasks_people_ask_for_most_are_covered(): void
    {
        foreach ([
            'change-a-word', 'replace-home-photo', 'change-project-cover', 'upload-video', 'publish', 'unpublish',
            'delete-item', 'restore-item', 'edit-seo', 'update-contact-info', 'edit-paragraph', 'upload-from-phone',
        ] as $id) {
            $this->assertNotNull(KnowledgeBase::find($id), "no article for $id");
            $this->assertGreaterThanOrEqual(3, count(KnowledgeBase::find($id)['en']['steps']), "$id is too short");
        }
    }
}
