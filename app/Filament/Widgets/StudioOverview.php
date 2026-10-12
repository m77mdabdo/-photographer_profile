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
 * C1 — EVERY NUMBER IS A DOOR.
 *
 * A count that cannot be clicked is a poster. Each stat opens the list it
 * counts, with the filter already applied, so "3 unread enquiries" is one
 * click from the three enquiries rather than from an enquiries page the owner
 * then has to filter by hand.
 *
 * Until task 31 the destinations are the OLD panel's screens, which work
 * today and accept these filters as query parameters. The links are real, not
 * placeholders; it is the address that is temporary.
 *
 * C2 — ORDERED BY URGENCY, NOT BY TABLE.
 *
 *   1. Unread enquiries   — the only number with a deadline attached.
 *   2. Unfinished work    — started and forgotten; the thing a studio loses.
 *   3. Journal drafts     — the same, for the part that goes stale first.
 *   4. Published work     — reference. It is the reassurance, not the task,
 *                           so it is last rather than first.
 *
 * Filament's default would have put them in declaration order with equal
 * weight. Reading left to right, this one goes most-urgent to least.
 * ───────────────────────────────────────────────────────────────────────────
 *
 * WHAT WAS LEFT OUT, so nobody adds it back: total media count (only ever
 * goes up), page views (we have no analytics and inventing one is worse),
 * total projects (the published/draft split is the same fact with a decision
 * attached), last login (the person reading it is the person who logged in).
 */
class StudioOverview extends StatsOverviewWidget
{
    /*
     * NOT LAZY. Filament defers widgets by default, which is right below a
     * heavy table and wrong here: on this dashboard the widgets ARE the page,
     * so deferring means opening the panel to four empty rectangles that fill
     * in a moment later, every time. The cost of loading eagerly is four
     * indexed counts.
     */
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $unread = Inquiry::query()->where('status', InquiryStatus::New)->count();
        $drafts = Project::query()->where('status', PublishStatus::Draft)->count();
        $articleDrafts = Article::query()->where('status', PublishStatus::Draft)->count();
        $published = Project::query()->where('status', PublishStatus::Published)->count();

        return [
            Stat::make(__('studio.stats.enquiries'), (string) $unread)
                ->description(__('studio.stats.enquiries_hint'))
                ->color($unread > 0 ? 'danger' : 'gray')
                ->url($this->link('admin.inquiries.index', ['status' => InquiryStatus::New->value])),

            Stat::make(__('studio.stats.drafts'), (string) $drafts)
                ->description(__('studio.stats.drafts_hint'))
                ->color($drafts > 0 ? 'warning' : 'gray')
                ->url($this->link('admin.projects.index', ['status' => PublishStatus::Draft->value])),

            Stat::make(__('studio.stats.article_drafts'), (string) $articleDrafts)
                ->description(__('studio.stats.article_drafts_hint'))
                ->color($articleDrafts > 0 ? 'warning' : 'gray')
                ->url($this->link('admin.articles.index', ['status' => PublishStatus::Draft->value])),

            Stat::make(__('studio.stats.published'), (string) $published)
                ->description(__('studio.stats.published_hint'))
                ->color('primary')
                ->url($this->link('admin.projects.index', ['status' => PublishStatus::Published->value])),
        ];
    }

    /**
     * A filtered link to a list, or null when that list does not exist.
     *
     * Returning null rather than '#' matters: Filament renders a stat without
     * a url as plain text rather than as a link that goes nowhere, so a
     * dormant destination is honestly dead instead of quietly broken.
     *
     * @param  array<string, string>  $filters
     */
    private function link(string $route, array $filters = []): ?string
    {
        return \Illuminate\Support\Facades\Route::has($route)
            ? route($route, $filters)
            : null;
    }
}
