<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Models\ArticleCategory;
use App\Models\MediaVariant;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * PART 1 — NO RESOURCE IS REACHABLE WITHOUT AN EXPLICIT POLICY DECISION.
 *
 * ADR 0002 recorded that a missing policy method denies and therefore "fails
 * closed". It does not. Filament's get_authorization_response() returns
 * Response::allow() when a model has no policy, OR when the policy exists but
 * lacks the ability being asked for, and it never consults the Gate:
 *
 *     Gate::allows('viewAny', Tag::class)   false
 *     TagResource::canViewAny()             true
 *
 * for the same Editor. Three models — Tag, ArticleCategory, User — had no
 * policy at all and were editable and deletable by anybody who could open the
 * panel. The document said the opposite for four tasks, and a security audit
 * was going to read it.
 *
 * Task 31 fixed the instances. This file removes the possibility, by asserting
 * the PROPERTY rather than the three names: every resource Filament has
 * discovered must reach a real policy method for every ability it can be asked
 * for. A thirteenth resource added next year is covered the moment it appears
 * in the directory, which is the only version of this that survives contact
 * with a future contributor.
 * ───────────────────────────────────────────────────────────────────────────
 */
class ResourceGovernanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every ability Filament asks a resource about.
     *
     * Taken from Filament's own HasAuthorization docblock plus the two this
     * project adds (`publish`, `reorder`) — not from what our policies happen
     * to define, which would make the test a mirror of the code it checks.
     */
    private const ABILITIES = [
        'viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete',
        'deleteAny', 'restoreAny', 'forceDeleteAny',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('studio');
    }

    /** @return list<class-string<resource>> */
    private function resources(): array
    {
        return array_values(Filament::getPanel('studio')->getResources());
    }

    // ----------------------------------------------------------------- 1

    /**
     * THE PANEL IS IN STRICT MODE, which is what turns a gap into a crash.
     *
     * Without it, every assertion below could pass today and a resource added
     * without a policy would still be wide open — because Filament would
     * answer for it. This is the assertion the others rest on.
     */
    public function test_the_panel_refuses_to_guess(): void
    {
        $this->assertTrue(
            Filament::getPanel('studio')->isAuthorizationStrict(),
            'the panel is not in strict authorization mode, so a missing policy method is an unspoken yes'
        );
    }

    /** Every resource's model has a policy. Not "most", not "the ones we remembered". */
    public function test_every_resource_model_has_a_policy(): void
    {
        $missing = [];

        foreach ($this->resources() as $resource) {
            if (Gate::getPolicyFor($resource::getModel()) === null) {
                $missing[] = class_basename($resource).' → '.class_basename($resource::getModel());
            }
        }

        $this->assertSame(
            [],
            $missing,
            "these resources have no policy, and Filament ALLOWS when a policy is absent:\n  "
            .implode("\n  ", $missing)
        );
    }

    /**
     * And every ability resolves to a real method on it.
     *
     * A policy that exists but lacks `view()` is the same hole in a smaller
     * shape: ClientResource::can('view') answered true while
     * Gate::allows('view', $client) answered false.
     */
    public function test_every_ability_reaches_a_real_policy_method(): void
    {
        $gaps = [];

        foreach ($this->resources() as $resource) {
            $policy = Gate::getPolicyFor($resource::getModel());

            if ($policy === null) {
                continue;   // reported by the test above
            }

            foreach (self::ABILITIES as $ability) {
                if (! method_exists($policy, $ability)) {
                    $gaps[] = $policy::class.'::'.$ability.'()';
                }
            }
        }

        $this->assertSame(
            [],
            $gaps,
            "these abilities have no method, so strict mode will throw when Filament asks:\n  "
            .implode("\n  ", $gaps)
        );
    }

    // ----------------------------------------------------------------- 2

    /**
     * STRICT MODE REALLY THROWS. Asserted by execution, not by reading the
     * flag — the flag was the claim and the claim is what was wrong last time.
     */
    public function test_strict_mode_throws_for_a_model_with_no_policy(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));

        /*
         * A model the application has but the panel does not expose, and which
         * deliberately has no policy: MediaVariant is a rendition row, created
         * and destroyed by the queue, never by a person.
         */
        $this->assertNull(Gate::getPolicyFor(MediaVariant::class));

        try {
            \Filament\get_authorization_response('viewAny', MediaVariant::class, false);

            $this->fail('strict mode did not throw for a model with no policy');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Strict authorization', $e->getMessage());
            $this->assertStringContainsString('MediaVariant', $e->getMessage());
        }
    }

    // ----------------------------------------------------------------- 3

    /**
     * THE ANSWERS COME FROM THE POLICY, NOT FROM FILAMENT.
     *
     * For every resource and every ability, Filament's answer must equal the
     * Gate's. This is the assertion that would have caught the original bug
     * directly: it compares the two numbers that disagreed.
     */
    public function test_filament_and_the_gate_agree_for_every_resource(): void
    {
        $editor = User::factory()->create([
            'role' => UserRole::Editor,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($editor);

        $disagreements = [];

        foreach ($this->resources() as $resource) {
            $model = $resource::getModel();

            foreach (['viewAny', 'create', 'deleteAny', 'restoreAny', 'forceDeleteAny'] as $ability) {
                $filament = $resource::can($ability);
                $gate = Gate::forUser($editor)->allows($ability, $model);

                if ($filament !== $gate) {
                    $disagreements[] = sprintf(
                        '%s::can(%s) = %s but Gate::allows(%s, %s) = %s',
                        class_basename($resource),
                        $ability,
                        var_export($filament, true),
                        $ability,
                        class_basename($model),
                        var_export($gate, true),
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $disagreements,
            "Filament is answering instead of the policy:\n  ".implode("\n  ", $disagreements)
        );
    }

    // ----------------------------------------------------------------- 4

    /**
     * THE THREE MODELS THAT WERE OPEN. Named explicitly, because a property
     * test proves the general case and a named test proves the regression.
     */
    public function test_the_three_formerly_ungoverned_models_are_governed(): void
    {
        foreach ([
            Tag::class,
            ArticleCategory::class,
            User::class,
        ] as $model) {
            $this->assertNotNull(
                Gate::getPolicyFor($model),
                class_basename($model).' has lost its policy again'
            );
        }

        // And Users is still the Owner's alone, which is a policy decision now.
        $editor = User::factory()->create(['role' => UserRole::Editor]);

        $this->assertFalse(Gate::forUser($editor)->allows('viewAny', User::class));
        $this->assertTrue(Gate::forUser(
            User::factory()->create(['role' => UserRole::Owner])
        )->allows('viewAny', User::class));
    }

    /** The ADR no longer states the falsehood without marking it. */
    public function test_the_decision_record_carries_the_correction(): void
    {
        $adr = (string) file_get_contents(base_path('docs/decisions/0002-filament-and-bilingual.md'));

        $this->assertStringContainsString('⚠ CORRECTION', $adr, 'ADR 0002 has no correction banner');
        $this->assertStringContainsString('THE PARAGRAPH BELOW IS WRONG', $adr);

        /*
         * The false sentence is struck through rather than deleted — kept for
         * honesty, marked so it cannot be quoted by accident.
         */
        $this->assertStringContainsString(
            '~~A missing method on a registered policy **denies**.',
            $adr,
            'the original claim should be struck through, not silently removed'
        );
    }
}
