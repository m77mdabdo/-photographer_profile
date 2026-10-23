<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Pages\Help;
use Filament\Actions\Action;

/**
 * Contextual help beside a complex control (master directive, Part 7).
 *
 * A question-mark BUTTON, not a hover tooltip: a tooltip needs a mouse, and
 * the dashboard is used mostly from a phone. Pressing it opens a short
 * explanation in plain words, with a link to the matching section of
 * System → Help for the full procedure. The explanation is a translated
 * string (studio.help_hint.*), so it follows the panel's language.
 */
final class HelpHint
{
    /**
     * @param  string  $topic    key under studio.help_hint
     * @param  int     $section  the Help page section number (#help-N)
     */
    public static function action(string $topic, int $section): Action
    {
        return Action::make('help_'.str_replace('.', '_', $topic))
            ->icon('heroicon-o-question-mark-circle')
            ->iconButton()
            ->color('gray')
            ->label(__('studio.help.what_is_this'))
            ->tooltip(__('studio.help.what_is_this'))
            ->modalHeading(__('studio.help_hint.'.$topic.'.title'))
            ->modalDescription(__('studio.help_hint.'.$topic.'.body'))
            ->modalWidth('md')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('studio.help.close'))
            ->extraModalFooterActions([
                Action::make('open_help_'.$section)
                    ->label(__('studio.help.read_more'))
                    ->url(Help::getUrl().'#help-'.$section)
                    ->link(),
            ]);
    }
}
