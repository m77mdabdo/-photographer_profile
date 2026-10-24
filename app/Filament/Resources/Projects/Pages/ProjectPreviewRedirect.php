<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\NamesPlaces;
use App\Models\Project;
use App\Support\ProjectPreview;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Http\RedirectResponse;

/**
 * Opens an unpublished project on the public site, briefly.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * A PAGE THAT NEVER RENDERS.
 *
 * The signature has to be generated when the link is FOLLOWED, not when the
 * page listing it is drawn — otherwise an hour starts counting from whenever
 * the owner last loaded the projects list, and a link clicked at the end of
 * an afternoon is already dead.
 *
 * Filament has no "redirect route" concept, so this is a resource page whose
 * mount() returns a redirect and whose view is never reached. The alternative
 * was a route registered outside the resource, which would not inherit the
 * panel's auth middleware, its record binding or its authorisation.
 *
 * This is the capability the panel was missing: the custom admin has had
 * /admin/projects/{p}/preview since the project was built, and without it an
 * owner could not look at an unpublished story the way a visitor would.
 * ───────────────────────────────────────────────────────────────────────────
 */
class ProjectPreviewRedirect extends Page
{
    /*
     * InteractsWithRecord draws its own breadcrumbs, and they are the ones
     * NamesPlaces replaces — the record's title is already in them, and so is
     * the screen type after it. The trait wins; it calls the page's own
     * parent for the trail and adds the record itself.
     */
    use \App\Filament\Support\NamesPlaces, InteractsWithRecord {
        NamesPlaces::getBreadcrumbs insteadof InteractsWithRecord;
    }

    protected static string $resource = ProjectResource::class;

    /** Never rendered — mount() always redirects. */
    protected string $view = 'filament.resources.projects.pages.project-preview';

    public function mount(int|string $record): RedirectResponse
    {
        $this->record = $this->resolveRecord($record);

        /** @var Project $project */
        $project = $this->getRecord();

        abort_unless(auth()->user()?->can('update', $project) ?? false, 403);

        return redirect()->away(ProjectPreview::url($project));
    }
}
