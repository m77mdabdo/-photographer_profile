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
 * explanation in plain words, with a link to the Help Center article that
 * walks through the whole task step by step. The explanation is a
 * translated string (studio.help_hint.*), so it follows the panel's
 * language; HelpKnowledgeBaseTest checks every article named here exists.
 */
final class HelpHint
{
    /** Every hint in the dashboard, topic => the article it links to. */
    public const ARTICLES = [
        'status' => 'publish',
        'published_at' => 'publish',
        'seo_title' => 'edit-seo',
        'seo_description' => 'edit-seo',
        'alt' => 'write-alt-text',
        'direction' => 'adjust-gallery-lighting',
        'spotlight' => 'adjust-gallery-lighting',
        'darkness' => 'adjust-gallery-lighting',
        'softness' => 'adjust-gallery-lighting',
        'pace' => 'adjust-gallery-lighting',
        'mobile' => 'adjust-gallery-lighting',
        'video' => 'upload-video',
        'featured' => 'replace-home-photo',
        'slug' => 'change-slug',
        'price' => 'edit-service',
    ];

    /** @param  string  $topic  key under studio.help_hint and in ARTICLES */
    public static function action(string $topic): Action
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
                Action::make('open_help_'.str_replace('.', '_', $topic))
                    ->label(__('studio.help.read_more'))
                    ->url(Help::getUrl(['article' => self::ARTICLES[$topic]]))
                    ->link(),
            ]);
    }
}
