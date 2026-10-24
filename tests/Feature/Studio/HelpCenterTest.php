<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Filament\Pages\Help;
use App\Models\User;
use App\Support\Help\KnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Help Center page: every view renders in both panel languages, links
 * are real addresses, text is escaped, and only signed-in people see it.
 */
class HelpCenterTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $locale = 'en'): User
    {
        $user = User::factory()->create(['role' => UserRole::Editor]);
        $this->actingAs($user);
        app()->setLocale($locale);
        session(['locale' => $locale]);

        return $user;
    }

    public function test_the_home_asks_what_you_want_to_do_in_both_languages(): void
    {
        $this->signIn();
        Livewire::test(Help::class)
            ->assertSee('What would you like to do?')
            ->assertSee('Popular tasks')
            ->assertSee('Learn to manage your website from zero')
            ->assertSee('Change a word, a letter or a sentence anywhere on the site');

        $this->signIn('ar');
        Livewire::test(Help::class)
            ->assertSee('تحب تعمل إيه في موقعك؟')
            ->assertSee('اكتب أي حاجة عايز تعملها، وهنشرح لك الخطوات واحدة واحدة.')
            ->assertSee('غيّر كلمة أو حرفًا أو جملة في أي مكان من الموقع');
    }

    public function test_a_search_lists_matching_tasks_and_offers_a_choice_for_remove(): void
    {
        $this->signIn();
        Livewire::test(Help::class)
            ->set('q', 'replace homepage photo')
            ->assertSee('Replace a photograph on the home page')
            ->set('q', 'remove')
            ->assertSee('These do different things — which do you mean?')
            ->assertSee('Hide something from the site without deleting it')
            ->assertSee('Delete something permanently (owners only)');
    }

    public function test_a_search_with_no_match_says_so(): void
    {
        $this->signIn();
        Livewire::test(Help::class)
            ->set('q', 'zqxwvy')
            ->assertSee('No task matches “zqxwvy”');
    }

    public function test_an_article_renders_its_steps_fields_checks_and_problems(): void
    {
        $this->signIn();
        Livewire::withQueryParams(['article' => 'change-project-cover'])
            ->test(Help::class)
            ->assertSee('Change a project’s cover or main photograph')
            ->assertSee('What you will accomplish')
            ->assertSee('Guide me step by step')
            ->assertSee('Check that it worked')
            ->assertSee('If something goes wrong')
            ->assertSeeHtml('<span class="studio-help__ui">Replace</span>')
            ->assertSeeHtml('href="'.url('/admin/projects').'"');

        $this->signIn('ar');
        Livewire::withQueryParams(['article' => 'change-project-cover'])
            ->test(Help::class)
            ->assertSee('غيّر صورة غلاف مشروع أو صورته الرئيسية')
            ->assertSeeHtml('<span class="studio-help__ui">استبدال</span>')
            ->assertSee('أرشدني خطوة بخطوة');
    }

    public function test_every_article_renders_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $this->signIn($locale);

            foreach (array_keys(KnowledgeBase::all()) as $id) {
                Livewire::withQueryParams(['article' => $id])->test(Help::class)->assertOk()->assertSee(__('studio.help.steps'));
            }
        }
    }

    public function test_topics_the_guide_and_an_unknown_article(): void
    {
        $this->signIn();
        Livewire::withQueryParams(['topic' => 'publishing'])->test(Help::class)
            ->assertSee('Publishing, hiding and deleting')
            ->assertSee('Bring back something you deleted');
        Livewire::withQueryParams(['guide' => '1'])->test(Help::class)->assertSee('Read the full guide')->assertSee('Signing in');
        Livewire::withQueryParams(['article' => 'guide-7'])->test(Help::class)->assertSee('Cinematic sections');
        Livewire::withQueryParams(['article' => 'no-such-thing'])->test(Help::class)->assertSee('That help article does not exist.');
    }

    public function test_a_search_cannot_inject_markup(): void
    {
        $this->signIn();
        Livewire::test(Help::class)
            ->set('q', '<script>alert(1)</script>')
            ->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertSeeHtml('&lt;script&gt;');
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(Help::getUrl())->assertRedirect();
        $this->get(Help::getUrl(['article' => 'publish']))->assertRedirect();
    }
}
