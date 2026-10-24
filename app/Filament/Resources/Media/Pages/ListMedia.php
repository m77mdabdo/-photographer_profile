<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Pages;

use App\Actions\Media\StoreUploadedMedia;
use App\Filament\Resources\Media\MediaResource;
use App\Filament\Support\NamesPlaces;
use App\Models\Media;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListMedia extends ListRecords
{
    use NamesPlaces;

    protected static string $resource = MediaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->uploadAction(),
        ];
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * EVERY UPLOADED BYTE GOES THROUGH StoreUploadedMedia. FILAMENT NEVER
     * WRITES A FILE.
     *
     * Filament's FileUpload stores to a disk by itself by default, which would
     * bypass all four guarantees in one line of configuration:
     *
     *   1. content-based type detection — a .jpg full of PHP never reaches disk
     *   2. the sha256 dedupe — identical bytes are stored once
     *   3. the megapixel ceiling — the decompression-bomb guard
     *   4. the serial queued processing that generates every rendition
     *
     * `saveUploadedFileUsing` replaces Filament's storage step entirely. The
     * closure receives Livewire's TemporaryUploadedFile, which extends
     * UploadedFile, so the existing action takes it unchanged — no adapter, no
     * second code path, and nothing about the pipeline re-implemented here.
     *
     * It returns null, which tells Filament the field stored nothing: the
     * record is created by the action, not by the form, so there is no path
     * where Filament owns a file at all.
     *
     * An upload the action refuses surfaces its own message — "The image is
     * 142.3 megapixels, which exceeds the 80 megapixel limit" — rather than a
     * generic failure, because the reason is the only part an editor can act
     * on.
     * ───────────────────────────────────────────────────────────────────────
     */
    private function uploadAction(): Action
    {
        return Action::make('upload')
            ->label(__('studio.media.upload'))
            ->icon('heroicon-o-arrow-up-tray')
            ->modalSubmitActionLabel(__('studio.media.upload'))
            ->authorize(fn (): bool => auth()->user()?->can('create', Media::class) ?? false)
            ->schema([
                FileUpload::make('files')
                    ->label(__('studio.media.choose'))
                    ->multiple()
                    ->disk('local')
                    ->directory('livewire-tmp')
                    /*
                     * The client-side accept list is a convenience, never the
                     * check. The real one is getimagesize() inside the action.
                     */
                    ->acceptedFileTypes(config('media.accepted_mimes'))
                    /*
                     * TASK 36 B1 — THE KEY WAS WRONG AND THE DEFAULT HID IT.
                     *
                     * This read config('media.max_upload_kilobytes', 51200).
                     * There is no such key. config/media.php calls it
                     * max_upload_kb and sets it to 61440, so the field has
                     * been enforcing the 50 MB default all along while the
                     * configuration said 60 — and the default meant nothing
                     * ever failed loudly enough to notice. No default here:
                     * a missing ceiling should break the screen, not quietly
                     * invent one.
                     */
                    ->maxSize((int) config('media.max_upload_kb'))
                    ->helperText(__('studio.media.upload_hint'))
                    ->saveUploadedFileUsing(function (TemporaryUploadedFile $file) {
                        $this->store($file);

                        // Filament stores nothing; the action owns the record.
                        return null;
                    }),
            ])
            ->action(function (): void {
                // saveUploadedFileUsing has already run for every file.
                $this->resetTable();
            });
    }

    /** Hand one file to the pipeline, and report what it says. */
    private function store(UploadedFile $file): void
    {
        try {
            $before = Media::query()->count();

            $media = app(StoreUploadedMedia::class)($file, auth()->id());

            $deduped = Media::query()->count() === $before;

            Notification::make()
                ->title($deduped
                    ? __('studio.media.deduped', ['name' => $media->original_filename])
                    : __('studio.media.queued', ['name' => $media->original_filename]))
                ->success()
                ->send();
        } catch (InvalidArgumentException $e) {
            /*
             * The action's own sentence, not a generic one: "Images of type
             * image/gif are not supported" tells an editor what to do next.
             */
            Notification::make()
                ->title(__('studio.media.refused', ['name' => $file->getClientOriginalName()]))
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }
}
