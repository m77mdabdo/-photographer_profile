<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\AccentColor;
use App\Support\SeoData;
use App\Support\Settings;
use App\Support\StructuredData;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProjectController extends Controller
{
    /**
     * The public project page.
     *
     * Anything not publicly visible is a 404, not a 403: a draft's existence
     * is not something the public gets to learn.
     */
    public function show(Project $project): View
    {
        if (! $project->isPubliclyVisible()) {
            throw new NotFoundHttpException;
        }

        return view('projects.show', $this->payload($project));
    }

    /**
     * Signed preview of a draft. The signature is the whole authorisation:
     * the route carries `signed`, so an unsigned or expired URL never reaches
     * this method.
     */
    public function preview(Request $request, Project $project): View
    {
        return view('projects.show', $this->payload($project, preview: true));
    }

    /**
     * Everything the page needs, loaded in one place.
     *
     * @return array<string, mixed>
     */
    private function payload(Project $project, bool $preview = false): array
    {
        /*
         * The entire page in a handful of queries: blocks, each block's usage
         * rows, each usage's media, each media's variants, plus the cover and
         * the categories. Loading any of this inside the block partials would
         * turn a 12-block story into dozens of round trips.
         */
        $project->load([
            'blocks.blockMedia.media.variants',
            'cover.variants',
            'categories:id,name,slug',
            'credits',
        ]);

        return [
            'project' => $project,
            'preview' => $preview,

            // Task 38 F4: the page transition's rule draws in this.
            'accent' => AccentColor::pairForProject($project),

            /*
             * CreativeWork with every photograph the page shows as an
             * ImageObject carrying the credit and copyright held on its own
             * media record. On a preview it is omitted entirely: a draft is not
             * a published work, and describing one to a machine as though it
             * were is the kind of small untruth this file exists to avoid.
             */
            'structuredData' => $preview ? null : array_values(array_filter([
                StructuredData::project($project),
                StructuredData::breadcrumbs([
                    ['name' => Settings::get('site.name', 'Home'), 'url' => route('home')],
                    ['name' => Settings::get('nav.work', 'Work'), 'url' => route('work.index')],
                    ['name' => $project->title, 'url' => route('projects.show', $project)],
                ]),
            ])),
            'seo' => SeoData::make(
                title: $project->title,
                description: $project->excerpt ?: $project->subtitle,
                ogImage: $project->cover,
                ogType: 'article',
                robots: $preview ? 'noindex, nofollow' : null,
            ),
        ];
    }
}
