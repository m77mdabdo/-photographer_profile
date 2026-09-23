<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\SeoData;
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
            throw new NotFoundHttpException();
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
