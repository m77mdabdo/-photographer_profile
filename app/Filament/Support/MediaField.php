<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Media;
use Filament\Forms\Components\Select;

/**
 * Choosing an image, in a panel that does not have the picker yet.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * THIS IS THE HONEST INTERIM, AND THE REPORT SAYS SO.
 *
 * The custom admin has MediaPicker: a modal of thumbnails, searchable, with
 * the usage state of each image visible. It is better than a select, it is a
 * custom Filament field nobody has written, and writing one is a task of its
 * own rather than a paragraph of this one.
 *
 * What a select must not be is a REGRESSION IN CORRECTNESS, which is a
 * different thing from a regression in comfort. It is searchable by filename
 * and alt text, it refuses images that are not ready, and it shows the
 * dimensions — enough to pick the right one deliberately rather than by
 * guessing from a filename.
 * ───────────────────────────────────────────────────────────────────────────
 */
final class MediaField
{
    public static function make(string $name, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            ->searchable()
            ->preload()
            /*
             * READY ONLY. A pending or failed image has no renditions, so
             * choosing one would publish a page with no picture on it — and
             * the queue is where that is decided, not the form.
             */
            ->options(fn (): array => Media::query()
                ->where('status', \App\Enums\MediaStatus::Ready)
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->mapWithKeys(fn (Media $m): array => [$m->id => static::describe($m)])
                ->all())
            ->getSearchResultsUsing(fn (string $search): array => Media::query()
                ->where('status', \App\Enums\MediaStatus::Ready)
                ->where(function ($query) use ($search): void {
                    $query->where('original_filename', 'like', "%{$search}%")
                        ->orWhere('alt', 'like', "%{$search}%");
                })
                ->limit(50)
                ->get()
                ->mapWithKeys(fn (Media $m): array => [$m->id => static::describe($m)])
                ->all())
            ->getOptionLabelUsing(fn ($value): ?string => ($m = Media::query()->find($value))
                ? static::describe($m)
                : null);
    }

    /** The filename, its alt text and its size — the three things that identify it. */
    private static function describe(Media $media): string
    {
        $alt = $media->getTranslation('alt', null, fallback: false);

        return trim(sprintf(
            '%s — %d×%d%s',
            $media->original_filename,
            (int) $media->width,
            (int) $media->height,
            $alt ? ' · '.\Illuminate\Support\Str::limit($alt, 40) : '',
        ));
    }
}
