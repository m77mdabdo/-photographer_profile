<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MediaStatus;
use App\Models\Client;
use App\Models\Media;
use App\Support\SeoData;
use App\Support\Settings;
use Illuminate\Contracts\View\View;

class AboutController extends Controller
{
    public function __invoke(): View
    {
        $portrait = $this->portrait();

        return view('about.show', [
            'portrait' => $portrait,
            'clients' => Client::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'url']),
            'seo' => SeoData::make(
                title: Settings::get('about.heading', 'About'),
                description: Settings::get('about.biography'),
                ogImage: $portrait,
            ),
        ]);
    }

    /**
     * The studio portrait, chosen in settings from the media library.
     *
     * Null is a perfectly good answer: the page composes without a picture
     * rather than rendering a broken frame, because a studio that has not
     * chosen one yet should still have an about page.
     */
    private function portrait(): ?Media
    {
        $id = Settings::get('about.portrait_media_id');

        if ($id === null || ! ctype_digit($id)) {
            return null;
        }

        return Media::query()
            ->where('status', MediaStatus::Ready)
            ->with('variants')
            ->find((int) $id);
    }
}
