<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;

/**
 * The story builder, inside the panel.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE REAL COMPONENT, MOUNTED. NOT A REWRITE.
 *
 * This page renders App\Livewire\Studio\StoryBuilder unchanged — the same PHP
 * class, the same Blade view, the same 993 lines of JavaScript the custom
 * admin has been using since Task 17. Filament 5 runs Livewire 4, so there is
 * nothing to port.
 *
 * WHAT A FILAMENT BUILDER-FIELD REWRITE WOULD HAVE COST, item by item:
 *
 *   undo and redo          a history stack over the whole story, Cmd+Z /
 *                          Cmd+Shift+Z. A Builder field has no undo.
 *   crash recovery         the draft is mirrored into localStorage on every
 *                          change and offered back after a browser crash or
 *                          an accidental close. Nothing in Filament does this.
 *   local-state drag       blocks reorder in the DOM with no round trip, and
 *                          the whole story is written in ONE save. A Builder
 *                          field's reorder is a Livewire request per move.
 *   cross-block image drag an image dragged from one gallery block into
 *                          another. There is no Filament equivalent.
 *   keyboard parity        every drag has an arrow-key equivalent, and the
 *                          outline is a keyboard jump list.
 *   the integrity guard    StoryBuilder::saveStory() is the single write, and
 *                          it refuses a payload with media ids in `data`.
 *
 * Six things, each of which a photographer uses on the screen they use most.
 * Rewriting the editor to make the panel internally consistent would have
 * been a worse product defended by a tidier architecture.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * THE ONE COST, AND HOW IT IS HANDLED — see the view.
 *
 * This page carries the builder's own save button, which is not the form save
 * button every other resource page has. Two save buttons on one screen reads
 * as a bug, so this page deliberately has NO form and NO save action of its
 * own: there is exactly one save control on it, it belongs to the story, and
 * the page says in a sentence what it does and does not save.
 */
class ProjectStory extends Page
{
    /*
     * InteractsWithRecord draws its own breadcrumbs, and they are the ones
     * NamesPlaces replaces — the record's title is already in them, and so is
     * the screen type after it. The trait wins; it calls the page's own
     * parent for the trail and adds the record itself.
     */
    use InteractsWithRecord, \App\Filament\Support\NamesPlaces {
        \App\Filament\Support\NamesPlaces::getBreadcrumbs insteadof InteractsWithRecord;
    }

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.project-story';

    /*
     * The route name and path are declared by ProjectResource::getPages(),
     * which is where every other page of this resource is declared. Filament
     * derives the rest.
     *
     * Records are addressed by id, as everywhere else in this panel — a slug
     * is editable and per-locale — and AddressesRecordsById makes getUrl()
     * agree with the route. This page inherits both from the resource.
     */

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        /*
         * The same ability the old admin's route checked. StoryBuilder::mount()
         * authorizes it again on the component — belt and braces, because the
         * component is also reachable at /admin until step 10 and must not
         * depend on this page for its own guard.
         */
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(
            auth()->user()?->can('update', $this->getRecord()) ?? false,
            403,
        );
    }

    public function getTitle(): string
    {
        return __('studio.story.title');
    }

    public function getHeading(): string
    {
        /** @var Project $project */
        $project = $this->getRecord();

        return (string) ($project->title ?: __('studio.story.title'));
    }

    public function getSubheading(): ?string
    {
        return __('studio.story.subheading');
    }

    /**
     * NO HEADER ACTIONS, and that is the two-save-buttons decision.
     *
     * A "Save" here would be a second control that saved something else —
     * the project's own columns — on a screen whose entire purpose is the
     * story. The link back to the project's details is in the view, as a
     * link, because that is what it is.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
