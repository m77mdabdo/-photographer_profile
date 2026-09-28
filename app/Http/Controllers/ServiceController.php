<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Service;
use App\Support\SeoData;
use App\Support\Settings;
use App\Support\StructuredData;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ServiceController extends Controller
{
    public function index(): View
    {
        $services = Service::query()
            ->published()
            ->with('cover.variants')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('services.index', [
            'services' => $services,
            'structuredData' => StructuredData::breadcrumbs([
                ['name' => Settings::get('site.name', 'Home'), 'url' => route('home')],
                ['name' => Settings::get('services.heading', 'Services'), 'url' => route('services.index')],
            ]),
            'seo' => SeoData::make(
                title: Settings::get('services.heading', 'Services'),
                description: Settings::get('services.standfirst'),
            ),
        ]);
    }

    public function show(Service $service): View
    {
        // A draft is a 404, not a 403: its existence is not public information.
        if (! $service->isPubliclyVisible()) {
            throw new NotFoundHttpException();
        }

        $service->load(['cover.variants', 'gallery.variants']);

        return view('services.show', [
            'service' => $service,

            /*
             * Two blocks: the Service itself, and the breadcrumb trail that
             * genuinely exists here — home, services, this one. Both are built
             * from the record this page renders, so neither can drift from
             * what a reader sees.
             */
            'structuredData' => [
                StructuredData::service($service),
                StructuredData::breadcrumbs([
                    ['name' => Settings::get('site.name', 'Home'), 'url' => route('home')],
                    ['name' => Settings::get('services.heading', 'Services'), 'url' => route('services.index')],
                    ['name' => $service->name, 'url' => route('services.show', $service)],
                ]),
            ],

            'seo' => SeoData::make(
                title: $service->seo_title ?: $service->name,
                description: $service->seo_description ?: $service->summaryText(),
                canonical: $service->canonical_url ?: null,
                ogImage: $service->cover,
            ),
        ]);
    }
}
