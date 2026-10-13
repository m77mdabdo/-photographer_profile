<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Pages;

use App\Actions\Media\ReprocessMedia;
use App\Enums\MediaStatus;
use App\Filament\Resources\Media\MediaResource;
use App\Models\Media;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 34 A2–A5 — THE ONE IMAGE, IN THE PANEL.
 *
 * Everything the custom admin's /legacy/media/{uuid} could do, on a screen
 * the panel owns, so that admin can be deleted without losing a capability:
 *
 *   A2  the focal point, dragged on the picture itself
 *   A3  what the camera recorded
 *   A4  every rendition, with its format, width and weight
 *   A5  reprocess, for when one of them did not survive
 *
 * WHY A PAGE AND NOT AN INFOLIST. Three of the four are read-only and would
 * suit an infolist; the focal point is not. It is a point on a photograph,
 * and the only honest way to set it is to put it on the photograph — which
 * means an image, an overlay, a handle and 127 lines of pointer-event
 * JavaScript that already exist and already work. An infolist entry would
 * have been a custom view anyway, with the rest of the screen built around
 * its constraints instead of the picture's.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class ViewMedia extends Page
{
    use InteractsWithRecord;

    protected static string $resource = MediaResource::class;

    protected string $view = 'filament.resources.media.pages.view-media';

    /**
     * Not lazy. The whole page is one image and four lists; a skeleton that
     * resolves into the thing you navigated to is a flicker, not a saving.
     */
    protected static bool $isLazy = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(auth()->user()?->can('view', $this->getRecord()) ?? false, 403);
    }

    public function getTitle(): string
    {
        /** @var Media $media */
        $media = $this->getRecord();

        // What is in the picture names the page; the filename is a fallback.
        return $media->getTranslation('alt', null, fallback: false)
            ?: $media->original_filename;
    }

    public function getBreadcrumb(): string
    {
        return __('studio.media.detail');
    }

    protected function getHeaderActions(): array
    {
        return [
            /*
             * A5 — REPROCESS.
             *
             * Without this a failed rendition is unrecoverable from the
             * panel: the row sits in the library for ever, the public page
             * that wants it shows nothing, and the only fix is a queue
             * command on a server. The action is the same one the dashboard's
             * attention widget calls, so there is one definition of what
             * reprocessing means.
             */
            Action::make('reprocess')
                ->label(__('studio.media.reprocess'))
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalDescription(__('studio.media.reprocess_confirm'))
                ->authorize(fn (): bool => auth()->user()?->can('update', $this->getRecord()) ?? false)
                ->action(function (): void {
                    app(ReprocessMedia::class)($this->getRecord());

                    Notification::make()
                        ->title(__('studio.media.reprocess_queued'))
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * A2 — the focal point, saved.
     *
     * Two numbers between 0 and 1, which is what every crop in the system
     * multiplies by. Clamped here as well as in the browser: the inputs are
     * reachable with JavaScript off, and a focal point of 4 would push the
     * subject out of every rendition silently.
     */
    public function saveFocalPoint(): void
    {
        /** @var Media $media */
        $media = $this->getRecord();

        abort_unless(auth()->user()?->can('update', $media) ?? false, 403);

        $data = request()->all();

        $media->update([
            'focal_x' => $this->clamp($data['focal_x'] ?? $media->focal_x),
            'focal_y' => $this->clamp($data['focal_y'] ?? $media->focal_y),
        ]);

        Notification::make()->title(__('studio.media.focal_saved'))->success()->send();
    }

    private function clamp(mixed $value): float
    {
        return max(0.0, min(1.0, (float) $value));
    }

    /** A4 — the renditions, newest format first, largest first within it. */
    public function variants(): \Illuminate\Support\Collection
    {
        return $this->getRecord()
            ->variants
            ->sortBy([['format', 'asc'], ['width', 'asc']])
            ->values();
    }

    /**
     * A4 — is anything missing?
     *
     * A partial processing failure is the quiet one: the image says Ready
     * because SOMETHING was written, the page that wants a 1600px webp finds
     * none, and nothing anywhere says so. Comparing what exists against what
     * the configuration asks for is the only way to see it.
     *
     * @return list<string>
     */
    public function missingRenditions(): array
    {
        /** @var Media $media */
        $media = $this->getRecord();

        if ($media->status !== MediaStatus::Ready) {
            return [];
        }

        $have = $media->variants
            ->map(fn ($variant): string => $variant->format.'@'.$variant->width)
            ->all();

        $missing = [];

        foreach (array_keys(config('media.formats', [])) as $format) {
            foreach ((array) config('media.widths', []) as $width) {
                // A rendition is never made larger than the original.
                if ($width > (int) $media->width) {
                    continue;
                }

                if (! in_array($format.'@'.$width, $have, true)) {
                    $missing[] = $format.' '.$width.'px';
                }
            }
        }

        return $missing;
    }
}
