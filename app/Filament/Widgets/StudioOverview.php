<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InquiryStatus;
use App\Enums\PublishStatus;
use App\Models\Article;
use App\Models\Inquiry;
use App\Models\Project;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The four numbers that change what the studio does next.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * EVERY STAT HERE IS A PROMPT TO ACT. Anything that was only interesting was
 * left out, and the ones left out are worth naming so nobody adds them back:
 *
 *   total media count     — a number that only ever goes up. Nobody acts on it.
 *   page views            — we have no analytics, and inventing a number is
 *                           worse than not showing one.
 *   total projects        — the published and draft split below is the same
 *                           information with a decision attached.
 *   "last login"          — the person reading it is the person who logged in.
 * ───────────────────────────────────────────────────────────────────────────
 */
class StudioOverview extends StatsOverviewWidget
{
    /*
     * NOT LAZY, deliberately.
     *
     * Filament defers widgets by default: the page ships a placeholder and
     * fetches the contents in a second Livewire round trip. That is the right
     * default for a widget sitting below a heavy table — and the wrong one
     * here, because on this dashboard the widgets ARE the page. Deferred, the
     * studio opens the panel to four empty rectangles that fill in a moment
     * later, every single time.
     *
     * The cost of loading eagerly is four indexed counts and two six-row
     * queries. That is cheaper than the flash.
     */
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    protected function getStats(): array
    {
        $published = Project::query()->where('status', PublishStatus::Published)->count();
        $drafts = Project::query()->where('status', PublishStatus::Draft)->count();
        $unread = Inquiry::query()->where('status', InquiryStatus::New)->count();
        $articleDrafts = Article::query()->where('status', PublishStatus::Draft)->count();

        return [
            /*
             * JUSTIFICATION: published work is the site's entire purpose, and
             * the number tells an owner at a glance whether a deploy did what
             * they expected.
             */
            Stat::make(__('studio.stats.published'), (string) $published)
                ->description(__('studio.stats.published_hint'))
                ->color('primary'),

            /*
             * JUSTIFICATION: a draft is unfinished work the owner started and
             * forgot. This is the single most common thing a studio loses
             * track of, and the count is the reminder.
             */
            Stat::make(__('studio.stats.drafts'), (string) $drafts)
                ->description(__('studio.stats.drafts_hint'))
                ->color($drafts > 0 ? 'warning' : 'gray'),

            /*
             * JUSTIFICATION: an unanswered enquiry is money waiting. It is the
             * only number here with a deadline attached.
             */
            Stat::make(__('studio.stats.enquiries'), (string) $unread)
                ->description(__('studio.stats.enquiries_hint'))
                ->color($unread > 0 ? 'danger' : 'gray'),

            /*
             * JUSTIFICATION: the journal is the part of a portfolio that goes
             * stale first, and an unpublished draft is the usual reason.
             */
            Stat::make(__('studio.stats.article_drafts'), (string) $articleDrafts)
                ->description(__('studio.stats.article_drafts_hint'))
                ->color($articleDrafts > 0 ? 'warning' : 'gray'),
        ];
    }
}
