<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Enums\UserRole;
use App\Filament\Resources\Redirects\Pages\ManageRedirects;
use App\Models\Redirect;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * THE REDIRECTS FORM HAD NO RULES, AND ONE OF THEM MATTERS A LOT.
 *
 * RedirectManager has validated four things since it was written: a path and
 * not a URL, never the admin, no duplicate from_path, and never to itself.
 * The Filament form shipped with `required` and `maxLength(255)`.
 *
 * Three of the four produce rows that quietly never fire — an editor creates
 * one, watches nothing happen, and concludes the feature is broken. The
 * fourth, a redirect from /admin or /studio, would lock the studio out of its
 * own website with no screen left to undo it from.
 * ───────────────────────────────────────────────────────────────────────────
 */
class RedirectsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]));
    }

    /** @param array<string, mixed> $data */
    private function create(array $data): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(ManageRedirects::class)
            ->mountAction(TestAction::make('create')->table())
            ->fillForm($data + ['status_code' => 301, 'is_active' => true])
            ->call('callMountedAction');
    }

    /** A plain rename is accepted. */
    public function test_a_valid_redirect_is_created(): void
    {
        $this->create(['from_path' => '/old-name', 'to_path' => '/work/new-name'])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('redirects', [
            'from_path' => '/old-name',
            'to_path' => '/work/new-name',
            'status_code' => 301,
            'source' => 'manual',
        ]);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function refusals(): array
    {
        return [
            'a URL, not a path' => [['from_path' => 'https://example.com/old', 'to_path' => '/new'], 'from_path'],
            'a query string' => [['from_path' => '/old?utm=1', 'to_path' => '/new'], 'from_path'],
            'no leading slash' => [['from_path' => 'old-name', 'to_path' => '/new'], 'from_path'],
            'away from the old admin' => [['from_path' => '/admin', 'to_path' => '/new'], 'from_path'],
            'away from the old admin, deeper' => [['from_path' => '/admin/projects', 'to_path' => '/new'], 'from_path'],
            'away from the panel' => [['from_path' => '/studio', 'to_path' => '/new'], 'from_path'],
            'away from the panel, deeper' => [['from_path' => '/studio/media', 'to_path' => '/new'], 'from_path'],
            'away from livewire' => [['from_path' => '/livewire/update', 'to_path' => '/new'], 'from_path'],
            'into the old admin' => [['from_path' => '/old', 'to_path' => '/admin'], 'to_path'],
            'into the panel' => [['from_path' => '/old', 'to_path' => '/studio/users'], 'to_path'],
            'to itself' => [['from_path' => '/same', 'to_path' => '/same'], 'to_path'],
        ];
    }

    /**
     * @param  array<string, string>  $data
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_the_form_refuses(array $data, string $field): void
    {
        $this->create($data)->assertHasFormErrors([$field]);

        $this->assertSame(0, Redirect::query()->count(), 'the row was created anyway');
    }

    /** The same from_path twice is refused, not duplicated. */
    public function test_a_duplicate_from_path_is_refused(): void
    {
        $this->create(['from_path' => '/old-name', 'to_path' => '/first'])->assertHasNoFormErrors();

        $this->create(['from_path' => '/old-name', 'to_path' => '/second'])
            ->assertHasFormErrors(['from_path']);

        $this->assertSame(1, Redirect::query()->count());
    }

    /**
     * THE MAX LENGTH MATCHES THE COLUMN.
     *
     * The form said 255 and the column is 512, so a long but perfectly valid
     * path was refused with a message about a limit that does not exist.
     */
    public function test_a_long_path_is_accepted_up_to_the_column_width(): void
    {
        $long = '/'.str_repeat('a', 400);

        $this->create(['from_path' => $long, 'to_path' => '/short'])->assertHasNoFormErrors();

        $this->assertDatabaseHas('redirects', ['from_path' => $long]);
    }
}
