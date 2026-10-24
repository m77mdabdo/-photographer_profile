<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Field;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * People: the Owner's screen, and the two silent failures it had.
 *
 * `role` is not in User::$fillable and the column defaults to 'editor', so
 * mass assignment dropped it without a word: the form said Owner and the row
 * said Editor. A privilege change that looks like it happened is worse than
 * one that fails, which is why this file exists.
 */
class UsersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'name' => 'The Owner',
            'role' => UserRole::Owner,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($this->owner);
    }

    /** The condition that makes all of this necessary. */
    public function test_role_is_not_fillable_and_the_column_has_a_default(): void
    {
        $this->assertNotContains('role', (new User)->getFillable());

        [$column] = array_values(array_filter(
            DB::select('SHOW COLUMNS FROM users'),
            fn (object $c): bool => $c->Field === 'role',
        ));

        $this->assertSame('NO', $column->Null);
        $this->assertSame('editor', $column->Default, 'the silent default is what a dropped role becomes');
    }

    /** Creating an Owner creates an Owner. */
    public function test_creating_a_user_sets_the_chosen_role(): void
    {
        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('create')->table(), [
                'name' => 'Rania Khoury',
                'email' => 'rania@example.com',
                'role' => UserRole::Owner->value,
            ])
            ->assertHasNoActionErrors();

        $user = User::query()->where('email', 'rania@example.com')->firstOrFail();

        $this->assertSame(UserRole::Owner, $user->role, 'the chosen role was dropped by mass assignment');
    }

    /**
     * And they get a password they never learn, rather than no password.
     *
     * The column is NOT NULL, so this used to be a SQL error — the rule was
     * written on the ManageRecords page as mutateFormDataBeforeCreate(), which
     * a CreateAction does not call.
     */
    public function test_a_new_user_gets_an_unknowable_password(): void
    {
        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('create')->table(), [
                'name' => 'Rania Khoury',
                'email' => 'rania@example.com',
                'role' => UserRole::Editor->value,
            ]);

        $user = User::query()->where('email', 'rania@example.com')->firstOrFail();

        $this->assertNotEmpty($user->password);
        $this->assertFalse(Hash::check('', $user->password));
        $this->assertFalse(Hash::check('password', $user->password));

        // And no second factor, so EnsureTwoFactorEnabled stops them until
        // they enrol one.
        $this->assertNull($user->two_factor_confirmed_at);
    }

    /** Promoting somebody promotes them. */
    public function test_editing_a_user_changes_the_role(): void
    {
        $editor = User::factory()->create(['role' => UserRole::Editor]);

        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('edit')->table($editor), [
                'name' => $editor->name,
                'email' => $editor->email,
                'role' => UserRole::Owner->value,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(UserRole::Owner, $editor->refresh()->role, 'the promotion was silently dropped');
    }

    /** The two-factor state is shown and never editable. */
    public function test_the_two_factor_state_cannot_be_cleared_from_the_panel(): void
    {
        $this->assertContains('two_factor_confirmed_at', Schema::getColumnListing('users'));

        /*
         * An admin who could clear somebody's second factor could walk past
         * EnsureTwoFactorEnabled on their behalf. It is a column in the table
         * and not a field in the form, and this says so.
         */
        $paths = [];

        foreach (UserResource::form(
            \Filament\Schemas\Schema::make(Livewire::test(ManageUsers::class)->instance())
        )->getComponents() as $component) {
            if ($component instanceof Field) {
                $paths[] = $component->getName();
            }
        }

        $this->assertSame(['name', 'email', 'role'], $paths);
    }

    /** You cannot delete yourself, and the refusal says why. */
    public function test_you_cannot_delete_your_own_account(): void
    {
        $response = Gate::inspect('delete', $this->owner);

        $this->assertTrue($response->denied());
        $this->assertStringContainsString('your own account', (string) $response->message());

        // A second Owner may delete the first.
        $other = User::factory()->create(['role' => UserRole::Owner]);

        $this->assertTrue(
            Gate::forUser($other)->inspect('delete', $this->owner)->allowed()
        );
    }

    /**
     * THE LAST OWNER CANNOT DEMOTE THEMSELVES.
     *
     * The reachable form of locking the studio out of its own panel: there is
     * one Owner, they set their role to Editor, and granting the role back
     * requires an Owner. Deletion cannot reach this state — only an Owner may
     * delete an Owner, so the only Owner deleting the only Owner is the self
     * rule above — but the role select can, and this is where it is stopped.
     */
    public function test_the_last_owner_cannot_demote_themselves(): void
    {
        $this->assertSame(1, User::query()->where('role', UserRole::Owner)->count());

        Livewire::test(ManageUsers::class)
            ->mountAction(TestAction::make('edit')->table($this->owner))
            ->fillForm(['role' => UserRole::Editor->value])
            ->call('callMountedAction')
            ->assertHasFormErrors(['role']);

        $this->assertSame(UserRole::Owner, $this->owner->refresh()->role, 'the studio demoted its last Owner');
    }

    /** With a second Owner in place, a demotion is allowed. */
    public function test_an_owner_can_be_demoted_when_another_remains(): void
    {
        User::factory()->create(['role' => UserRole::Owner]);

        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('edit')->table($this->owner), [
                'name' => $this->owner->name,
                'email' => $this->owner->email,
                'role' => UserRole::Editor->value,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(UserRole::Editor, $this->owner->refresh()->role);
    }
}
