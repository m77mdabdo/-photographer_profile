<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Media\StoreUploadedMedia;
use App\Enums\MediaStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMediaRequest;
use App\Http\Requests\UpdateMediaRequest;
use App\Jobs\ProcessMediaVariants;
use App\Models\Media;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The media library. Deliberately thin: storing is the action's job, encoding
 * is the queue's job, and authorisation is the policy's job.
 */
class MediaController extends Controller
{
    public function show(Media $medium): View
    {
        $this->authorize('view', $medium);

        $medium->load(['variants', 'usages', 'uploader']);

        return view('admin.media.show', ['medium' => $medium]);
    }

    /**
     * One file per request. The uploader sends them serially on purpose: a
     * worker peaks near 300 MB per image, so a parallel burst is how you
     * exhaust a box.
     */
    public function store(StoreMediaRequest $request, StoreUploadedMedia $store): JsonResponse
    {
        $created = [];

        foreach ($request->file('files', []) as $file) {
            try {
                $media = $store($file, $request->user()?->id);
            } catch (InvalidArgumentException $e) {
                // A file that is not really an image is a validation problem,
                // not a server fault: say so with a 422.
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => ['files' => [$e->getMessage()]],
                ], 422);
            }

            $created[] = [
                'id' => $media->id,
                'uuid' => $media->uuid,
                'name' => $media->original_filename,
                'status' => $media->status->value,
                'status_label' => $media->status->label(),
                'url' => route('legacy.media.show', $media),
            ];
        }

        return response()->json(['media' => $created], 201);
    }

    public function update(UpdateMediaRequest $request, Media $medium): RedirectResponse
    {
        $medium->update($request->validated());

        return back()->with('status', 'Details saved.');
    }

    /**
     * Soft delete. The policy refuses while anything still uses the image, and
     * its message explains where.
     */
    public function destroy(Request $request, Media $medium): RedirectResponse
    {
        $this->authorize('delete', $medium);

        $medium->delete();

        return redirect()
            ->route('legacy.media.index')
            ->with('status', 'Moved to trash. The files are still on disk.');
    }

    public function reprocess(Media $medium): RedirectResponse
    {
        $this->authorize('update', $medium);

        // The panel's dashboard does the same thing; see the action.
        app(\App\Actions\Media\ReprocessMedia::class)($medium);

        return back()->with('status', 'Queued for reprocessing.');
    }

}
