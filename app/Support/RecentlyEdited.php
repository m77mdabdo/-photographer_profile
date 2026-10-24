<?php

declare(strict_types=1);

namespace App\Support;

use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Article;
use App\Models\Page;
use App\Models\Project;
use App\Models\Service;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Support\Facades\DB;

/**
 * What was edited most recently, across every kind of content.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ONE UNION, FOUR MODELS — the same shape as UntranslatedContent.
 *
 * The previous version ran a query per model, concatenated the results in
 * PHP, sorted the collection and took six. That cannot paginate and cannot
 * sort by a column the database did not already order by, which is most of
 * them — and it is four queries where one will do.
 *
 * WHO CHANGED IT, HONESTLY. Only two of the four tables record a person:
 * projects have created_by and articles have author_id. Services and pages
 * record nobody, so this reports null for them rather than inventing an
 * attribution — a dash in a column is a fact, and a wrong name is worse than
 * no name when the question being asked is "who changed this".
 *
 * It is created_by, not updated_by, and the column name is the honest one:
 * nothing in this application records who last SAVED a row. Reported as it
 * is rather than relabelled, and named in the report as a known weakness.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class RecentlyEdited
{
    /**
     * model => [label column, the column naming a person or null]
     *
     * @var array<class-string, array{string, ?string}>
     */
    private const MODELS = [
        Project::class => ['title', 'created_by'],
        Article::class => ['title', 'author_id'],
        Service::class => ['name', null],
        Page::class => ['title', null],
    ];

    public static function query(): BuilderContract
    {
        $union = null;

        foreach (self::MODELS as $model => [$labelColumn, $personColumn]) {
            $table = (new $model)->getTable();

            $editor = $personColumn === null
                ? 'NULL'
                : sprintf('(SELECT `name` FROM `users` WHERE `users`.`id` = `%s`.`%s`)', $table, $personColumn);

            $select = DB::table($table)
                ->whereNull('deleted_at')
                ->selectRaw(sprintf(
                    "CONCAT(?, '-', `%s`.`id`) as `key`, ? as `type`, `%s`.`id` as `record_id`, "
                    ."JSON_UNQUOTE(JSON_EXTRACT(`%s`, '$.%s')) as `label`, "
                    .'`%s`.`status` as `status`, %s as `editor`, `%s`.`updated_at` as `updated_at`',
                    $table, $table, $labelColumn, Locale::DEFAULT, $table, $editor, $table,
                ), [$model, $model]);

            $union = $union === null ? $select : $union->unionAll($select);
        }

        return DB::query()->fromSub($union, 'recently_edited');
    }

    /** Where a row of this union is edited, resolved through its resource. */
    public static function editUrl(string $type, int $id): ?string
    {
        $resource = match ($type) {
            Project::class => ProjectResource::class,
            Article::class => ArticleResource::class,
            Service::class => ServiceResource::class,
            Page::class => PageResource::class,
            default => null,
        };

        if ($resource === null || ! $resource::canViewAny()) {
            return null;
        }

        return $resource::getUrl('edit', ['record' => $id]);
    }
}
