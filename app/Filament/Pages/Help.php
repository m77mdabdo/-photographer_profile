<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\Locale;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Str;

/**
 * Help — the dashboard user guide, inside the dashboard (master directive,
 * Part 12). Reads docs/DASHBOARD_USER_GUIDE_EN.md or _AR.md by the panel's
 * language, so there is one guide and it cannot drift from a second copy.
 * Split into its numbered sections, with an index and a filter box, because
 * on a phone a single long page is hard to find anything in.
 *
 * Rendered with raw HTML stripped and unsafe links refused: the guide is a
 * file in the repository, but nothing on this page should depend on that.
 */
class Help extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.pages.help';

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
        return __('studio.help.nav');
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    /** @return list<array{id: string, title: string, html: string}> */
    public function sections(): array
    {
        $file = base_path('docs/DASHBOARD_USER_GUIDE_'.(Locale::current() === 'ar' ? 'AR' : 'EN').'.md');

        if (! is_file($file)) {
            return [];
        }

        $source = (string) file_get_contents($file);
        $parts = preg_split('/^## /m', $source) ?: [];
        array_shift($parts);   // the title and the introduction above section 1

        $sections = [];

        foreach ($parts as $i => $part) {
            [$title, $body] = array_pad(explode("\n", $part, 2), 2, '');
            $body = (string) preg_replace('#</?div[^>]*>|^---\s*$#m', '', $body);

            $sections[] = [
                'id' => 'help-'.($i + 1),
                'title' => trim($title),
                'html' => Str::markdown($body, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
            ];
        }

        return $sections;
    }
}
