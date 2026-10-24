<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\Help\HelpSearch;
use App\Support\Help\HelpText;
use App\Support\Help\KnowledgeBase;
use App\Support\Locale;
use BackedEnum;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * The Help Center (Help Center directive, Parts 3–5, 11).
 *
 * One page, four views, each with its own address so a link to it can be
 * shared or bookmarked:
 *
 *   /admin/help                    "What would you like to do?" — search,
 *                                   popular tasks, topics, the learning path
 *   /admin/help?q=…                 search results (HelpSearch)
 *   /admin/help?topic=projects      one topic's articles
 *   /admin/help?article=…           one task, step by step
 *   /admin/help?guide=1             the long-form guide, section by section
 *
 * Articles come from resources/help (KnowledgeBase); every text is escaped
 * and rendered through HelpText, which allows a small Markdown subset and
 * nothing else. The page never changes content: "Open this page" links go
 * to the dashboard screen, and the person does the task themselves.
 */
class Help extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.pages.help';

    #[Url(except: '')]
    public string $q = '';

    #[Url(except: null)]
    public ?string $article = null;

    #[Url(except: null)]
    public ?string $topic = null;

    #[Url(except: false)]
    public bool $guide = false;

    public static function getNavigationGroup(): ?string
    {
        return __('studio.nav.group_system');
    }

    public static function getNavigationLabel(): string
    {
        return __('studio.help.nav');
    }

    public function getTitle(): string
    {
        $article = $this->current();

        return $article !== null
            ? KnowledgeBase::text($article, $this->locale())['title']
            : __('studio.help.nav');
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    /** Typing a search leaves whatever was open. */
    public function updatedQ(): void
    {
        $this->article = null;
        $this->topic = null;
        $this->guide = false;
    }

    public function locale(): string
    {
        return Locale::current() === 'ar' ? 'ar' : 'en';
    }

    /** @return array<string, mixed>|null */
    public function current(): ?array
    {
        return $this->article !== null ? KnowledgeBase::find($this->article) : null;
    }

    /** @return array{results: list<array<string, mixed>>, choices: list<array<string, mixed>>} */
    public function results(): array
    {
        $found = HelpSearch::search($this->q, $this->locale());

        return [
            'results' => array_values(array_filter(array_map(fn (array $hit): ?array => $this->card($hit['id']), $found['results']))),
            'choices' => array_values(array_filter(array_map(fn (string $id): ?array => $this->card($id), $found['choices']))),
        ];
    }

    /**
     * What a list shows of an article: title, summary, topic, time.
     *
     * @return array<string, mixed>|null
     */
    public function card(string $id): ?array
    {
        $article = KnowledgeBase::find($id);

        if ($article === null) {
            return null;
        }

        $text = KnowledgeBase::text($article, $this->locale());
        $category = KnowledgeBase::categories()[$article['category']] ?? null;

        return [
            'id' => $id,
            'title' => $text['title'],
            'summary' => $text['summary'] ?? '',
            'kind' => $article['kind'],
            'minutes' => $article['minutes'] ?? null,
            'roles' => $article['roles'] ?? 'all',
            'category' => $category !== null ? $category[$this->locale()]['title'] : __('studio.help.kind.reference'),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function categories(): array
    {
        $out = [];

        foreach (KnowledgeBase::categories() as $key => $category) {
            $out[$key] = [
                'key' => $key,
                'icon' => $category['icon'],
                'title' => $category[$this->locale()]['title'],
                'about' => $category[$this->locale()]['about'],
                'count' => count(KnowledgeBase::inCategory($key)),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function cards(array $ids): array
    {
        return array_values(array_filter(array_map(fn (string $id): ?array => $this->card($id), $ids)));
    }

    /** @return list<array<string, mixed>> */
    public function topicCards(string $topic): array
    {
        return $this->cards(array_column(KnowledgeBase::inCategory($topic), 'id'));
    }

    /** @return list<array<string, mixed>> */
    public function guideSections(): array
    {
        return KnowledgeBase::guide($this->locale());
    }

    public function html(string $text): string
    {
        return HelpText::block($text);
    }

    public function inline(string $text): string
    {
        return HelpText::inline($text);
    }

    public function open(?string $article = null, ?string $topic = null, bool $guide = false): void
    {
        $this->q = '';
        $this->article = $article;
        $this->topic = $topic;
        $this->guide = $guide;
    }

    public function home(): void
    {
        $this->open();
    }
}
