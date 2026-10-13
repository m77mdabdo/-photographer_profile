<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InquiryStatus;
use App\Enums\MediaStatus;
use App\Enums\PublishStatus;
use App\Models\Article;
use App\Models\Inquiry;
use App\Models\Media;
use App\Models\Project;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * The band of figures at the top of the dashboard.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ONE ROW OF FACTS, NOT EIGHT CARDS.
 *
 * This replaces a StatsOverviewWidget of four stats. Filament draws those as
 * separate bordered cards, which is fine for four and unreadable for eight: at
 * 1440 they either wrap into two ragged rows or squeeze until a label like
 * "Journal drafts" breaks across three lines.
 *
 * So the figures are one bordered band, grouped into four clusters an owner
 * already thinks in — the work, the writing, the inbox, the pictures — with a
 * hairline between clusters rather than a box around each figure. The eye
 * reads a row of facts and the groups give it somewhere to pause.
 *
 * EVERY FIGURE IS A DOOR. A count that cannot be clicked is a poster. Each one
 * opens its own list with the filter already applied, so "3 unread" is one
 * click from the three enquiries rather than from an enquiries page the owner
 * then has to filter by hand.
 *
 * ONE QUERY PER TABLE, NOT ONE PER FIGURE. Eight figures over four tables
 * would be eight round trips; the three publish states of a model come back
 * from a single grouped count, and so do the four media states.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class StudioFigures extends Widget
{
    protected string $view = 'filament.widgets.studio-figures';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 1;

    /**
     * NOT LAZY, unlike every other widget on this page.
     *
     * Filament defers a widget's content to a second request by default,
     * which is right for anything expensive. This is four grouped COUNT
     * queries — the whole band is cheaper than the round trip that would
     * fetch it — and it is the first thing on the screen, so deferring it
     * means the dashboard opens with a row of empty placeholders where the
     * figures should be.
     *
     * The tables below stay lazy: they are paginated queries with joins, and
     * a skeleton row while they arrive is honest.
     */
    protected static bool $isLazy = false;

    /**
     * @return array<int, array{
     *     group: string,
     *     figures: list<array{label: string, value: int, url: ?string, tone: ?string}>
     * }>
     */
    public function getClusters(): array
    {
        $projects = $this->countsByStatus(Project::query()->getModel()->getTable());
        $articles = $this->countsByStatus(Article::query()->getModel()->getTable());
        $media = $this->mediaCounts();

        $unread = Inquiry::query()->where('status', InquiryStatus::New)->count();

        return [
            [
                'group' => __('studio.figures.inbox'),
                'figures' => [
                    [
                        'label' => __('studio.stats.enquiries'),
                        'value' => $unread,
                        'url' => $this->url('enquiries', ['status' => InquiryStatus::New->value]),
                        // The only figure with a deadline attached.
                        'tone' => $unread > 0 ? 'urgent' : null,
                    ],
                ],
            ],
            [
                'group' => __('studio.figures.work'),
                'figures' => [
                    [
                        'label' => __('studio.stats.published'),
                        'value' => $projects[PublishStatus::Published->value] ?? 0,
                        'url' => $this->url('projects', ['status' => PublishStatus::Published->value]),
                        'tone' => null,
                    ],
                    [
                        'label' => __('studio.stats.drafts'),
                        'value' => $projects[PublishStatus::Draft->value] ?? 0,
                        'url' => $this->url('projects', ['status' => PublishStatus::Draft->value]),
                        'tone' => null,
                    ],
                ],
            ],
            [
                'group' => __('studio.figures.writing'),
                'figures' => [
                    [
                        'label' => __('studio.stats.article_drafts'),
                        'value' => $articles[PublishStatus::Draft->value] ?? 0,
                        'url' => $this->url('articles', ['status' => PublishStatus::Draft->value]),
                        'tone' => null,
                    ],
                ],
            ],
            [
                'group' => __('studio.figures.pictures'),
                'figures' => [
                    [
                        'label' => __('studio.figures.ready'),
                        'value' => $media[MediaStatus::Ready->value] ?? 0,
                        'url' => $this->url('media', ['status' => MediaStatus::Ready->value]),
                        'tone' => null,
                    ],
                    [
                        'label' => __('studio.figures.processing'),
                        'value' => ($media[MediaStatus::Pending->value] ?? 0) + ($media[MediaStatus::Processing->value] ?? 0),
                        'url' => $this->url('media', ['status' => MediaStatus::Processing->value]),
                        'tone' => null,
                    ],
                    [
                        'label' => __('studio.figures.failed'),
                        'value' => $media[MediaStatus::Failed->value] ?? 0,
                        'url' => $this->url('media', ['status' => MediaStatus::Failed->value]),
                        // A failed encode is a missing picture on a live page.
                        'tone' => ($media[MediaStatus::Failed->value] ?? 0) > 0 ? 'urgent' : null,
                    ],
                    [
                        'label' => __('studio.figures.total'),
                        'value' => array_sum($media),
                        'url' => $this->url('media'),
                        'tone' => null,
                    ],
                ],
            ],
        ];
    }

    /**
     * Every publish state of one table, in one query.
     *
     * @return array<string, int>
     */
    private function countsByStatus(string $table): array
    {
        return DB::table($table)
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /** @return array<string, int> */
    private function mediaCounts(): array
    {
        return DB::table('media')
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * A filtered list URL, or null if that resource is not registered.
     *
     * Resolved through the RESOURCE rather than by building a path, so moving
     * the panel from /studio to /admin — which happened in Task 33 — moved
     * every figure's destination with it and nothing here had to change.
     *
     * @param  array<string, string>  $filters
     */
    private function url(string $resource, array $filters = []): ?string
    {
        $class = match ($resource) {
            'enquiries' => \App\Filament\Resources\Enquiries\InquiryResource::class,
            'projects' => \App\Filament\Resources\Projects\ProjectResource::class,
            'articles' => \App\Filament\Resources\Articles\ArticleResource::class,
            'media' => \App\Filament\Resources\Media\MediaResource::class,
            default => null,
        };

        if ($class === null || ! $class::canViewAny()) {
            return null;
        }

        $parameters = [];

        foreach ($filters as $key => $value) {
            $parameters["tableFilters[{$key}][value]"] = $value;
        }

        return $class::getUrl('index', $parameters);
    }
}
