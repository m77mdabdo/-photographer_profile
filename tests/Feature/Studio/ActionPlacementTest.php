<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 33 C4 — ACTION PLACEMENT, ASSERTED RATHER THAN AGREED.
 *
 * Twelve resources wrote their own row of record actions and drifted apart,
 * as twelve hand-written lists do. The measurements that started this:
 *
 *   Media      Edit, RESTORE, Delete, ForceDelete  — destructive in slot 3
 *   Enquiries  View, Delete, Restore, ForceDelete  — refusals hidden
 *   Clients    Edit, Delete                        — refusals hidden
 *   Redirects  Edit, Delete                        — refusals hidden
 *   Tags       Edit, Delete, Restore, ForceDelete  — on a model with no trash
 *
 * Media is the one that mattered: a row of icon buttons gets hit by position
 * as much as by reading, and the one screen in this panel with hundreds of
 * rows had Delete sitting exactly where every other screen had Restore.
 *
 * ResourceDefaults::rowActionsFor() is now the single definition, and these
 * tests are what stop the thirteenth resource from being special.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class ActionPlacementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_confirmed_at' => now(),
        ]));

        Filament::setCurrentPanel('studio');
    }

    /** @return array<string, array{0: string, 1: class-string}> */
    private function tables(): array
    {
        $out = [];

        foreach (Filament::getPanel('studio')->getResources() as $resource) {
            $page = $resource::getPages()['index']->getPage();
            $out[class_basename($resource)] = [
                Livewire::test($page)->instance()->getTable(),
                $resource::getModel(),
            ];
        }

        return $out;
    }

    /**
     * Can a freshly created record of this kind be deleted at all?
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private function canDeleteARecord(string $model): bool
    {
        $record = $model::factory()->create();

        $allowed = auth()->user()?->can('delete', $record) ?? false;

        // Force, so a soft-deleting model does not leave a row behind for the
        // next resource in the loop to trip over.
        $record->forceDelete();

        return $allowed;
    }

    /**
     * Destructive last, always, on every resource.
     *
     * Stated as "no destructive action comes before a non-destructive one"
     * rather than as an exact list, so a resource may add a lead action of its
     * own — the story builder, View on an enquiry — without this test needing
     * to know about it.
     */
    public function test_no_destructive_action_sits_before_a_safe_one(): void
    {
        $destructive = [DeleteAction::class, RestoreAction::class, ForceDeleteAction::class];

        foreach ($this->tables() as $name => [$table, $model]) {
            $seenDestructive = null;

            foreach ($table->getRecordActions() as $action) {
                $isDestructive = in_array($action::class, $destructive, true);

                if ($isDestructive) {
                    $seenDestructive ??= $action->getName();

                    continue;
                }

                $this->assertNull(
                    $seenDestructive,
                    sprintf(
                        '%s: "%s" comes after the destructive "%s". A row is hit by position.',
                        $name,
                        $action->getName(),
                        (string) $seenDestructive,
                    ),
                );
            }
        }
    }

    /** Delete, then Restore, then ForceDelete — never Restore before Delete. */
    public function test_the_destructive_actions_are_always_in_the_same_order(): void
    {
        foreach ($this->tables() as $name => [$table, $model]) {
            $order = [];

            foreach ($table->getRecordActions() as $action) {
                $slot = match ($action::class) {
                    DeleteAction::class => 'delete',
                    RestoreAction::class => 'restore',
                    ForceDeleteAction::class => 'force',
                    default => null,
                };

                if ($slot !== null) {
                    $order[] = $slot;
                }
            }

            /*
             * A resource that cannot delete anything offers none of these,
             * and that is the right row rather than a gap in it. Navigation's
             * two menus are places in the design: there is nothing to
             * delete, and a button whose only behaviour is to refuse is
             * worse than no button at all.
             *
             * Asked of the policy with a REAL RECORD, which took three
             * attempts to get right:
             *
             *   deleteAny  is the BULK ability, and Media and the category
             *              tables refuse it for their own reasons while
             *              deleting one row happily.
             *   create     is refused by Enquiries, which arrive from the
             *              contact form and are certainly deletable.
             *
             * "Can a freshly made row of this kind be deleted" is the
             * question the row of buttons is answering, so it is the one to
             * ask. Fresh, because Media refuses while an image is in use and
             * a locked Page refuses always — both correct, neither about
             * whether the RESOURCE offers the action.
             */
            $expected = match (true) {
                ! $this->canDeleteARecord($model) => [],
                in_array(SoftDeletes::class, class_uses_recursive($model), true) => ['delete', 'restore', 'force'],
                default => ['delete'],
            };

            $this->assertSame($expected, $order, "$name has its destructive actions in the wrong order");
        }
    }

    /**
     * A model with no trash is offered no trash.
     *
     * Tags carried Restore and ForceDelete against a table with no deleted_at
     * column. Filament hides them, so nothing visibly broke — which is why it
     * survived: an action for a state that cannot exist is invisible until
     * somebody adds SoftDeletes and gets two buttons they never wrote.
     */
    public function test_only_soft_deleting_resources_offer_restore(): void
    {
        foreach ($this->tables() as $name => [$table, $model]) {
            $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true)
                && $this->canDeleteARecord($model);

            $offersRestore = collect($table->getRecordActions())
                ->contains(fn ($action): bool => $action instanceof RestoreAction);

            $this->assertSame(
                $softDeletes,
                $offersRestore,
                $softDeletes
                    ? "$name soft-deletes but offers no Restore"
                    : "$name offers Restore for a trash it does not have",
            );
        }
    }

    /**
     * Every destructive action explains a refusal instead of vanishing.
     *
     * Filament's default for a denied action is to remove the button, so a
     * conditional policy becomes a missing control and no reason — and the
     * policies here compute careful sentences ("used on 3 pages", "the last
     * Owner", "linked from the footer") that were being thrown away.
     */
    public function test_every_destructive_action_shows_the_reason_it_is_refused(): void
    {
        foreach ($this->tables() as $name => [$table, $model]) {
            foreach ($table->getRecordActions() as $action) {
                if (! $action instanceof DeleteAction
                    && ! $action instanceof RestoreAction
                    && ! $action instanceof ForceDeleteAction) {
                    continue;
                }

                $this->assertTrue(
                    $action->hasAuthorizationTooltip(),
                    sprintf('%s: "%s" hides itself when refused instead of saying why.', $name, $action->getName()),
                );

                $this->assertTrue(
                    $action->hasAuthorizationNotification(),
                    sprintf('%s: "%s" refuses a click in silence.', $name, $action->getName()),
                );
            }
        }
    }

    /**
     * No resource has two actions with the same name.
     *
     * THIS IS NOT HYPOTHETICAL. rowActionsFor() took a $lead array before it
     * took an $edit argument, and UserResource passed in an EditAction
     * carrying ->using() — which this method then appended the canonical Edit
     * after. Two actions named "edit", the second winning, and promoting an
     * editor to Owner silently did nothing. UsersTest caught the symptom; this
     * catches the cause, anywhere in the panel.
     */
    public function test_no_resource_declares_the_same_action_twice(): void
    {
        foreach ($this->tables() as $name => [$table, $model]) {
            foreach (['record' => $table->getRecordActions(), 'header' => $table->getHeaderActions()] as $where => $actions) {
                $names = array_map(fn ($action): string => $action->getName(), $actions);

                $this->assertSame(
                    array_values(array_unique($names)),
                    $names,
                    sprintf('%s declares a duplicate %s action: %s', $name, $where, implode(', ', $names)),
                );
            }
        }
    }

    /**
     * Creating is always top-right, never in the row.
     *
     * Seven resources reach a separate Create PAGE from the list header and
     * five open a modal from it; both put the button in the same place, which
     * is what consistency means here. What would not be consistent is a
     * create action living among the per-row actions on one of them.
     */
    public function test_creating_is_never_a_row_action(): void
    {
        foreach ($this->tables() as $name => [$table, $model]) {
            foreach ($table->getRecordActions() as $action) {
                $this->assertNotInstanceOf(
                    \Filament\Actions\CreateAction::class,
                    $action,
                    "$name has a create action in its rows",
                );
            }
        }
    }
}
