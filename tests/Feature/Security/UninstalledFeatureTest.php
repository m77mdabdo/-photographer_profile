<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 36 A5 — THE ROUTES filament/actions REGISTERS WHETHER YOU USE THEM
 * OR NOT.
 *
 * The audit found two routes answering an anonymous GET with a 500:
 *
 *   GET /filament/exports/{export}/download
 *   GET /filament/imports/{import}/failed-rows/download
 *
 * Nothing authorises them because nothing gets that far — route-model
 * binding queries `exports` and `imports`, tables whose migrations this
 * application never published, and the QueryException becomes a 500 with a
 * line in the log. Unauthenticated, unthrottled, once per request.
 *
 * App\Http\Middleware\RejectUninstalledFeature now answers 404 there, and
 * it is FIRST in the group rather than appended, because SubstituteBindings
 * lives inside 'web' and an appended middleware runs after the query.
 *
 * WHY THE GREP IS PART OF THIS TEST. A 404 on a route is only correct while
 * the claim "this application has no exports and no imports" is true. The day
 * somebody adds an ExportAction the download will be a 404 and the cause will
 * be three files away, so the claim is asserted rather than trusted.
 * ───────────────────────────────────────────────────────────────────────────
 */
class UninstalledFeatureTest extends TestCase
{
    use RefreshDatabase;

    /** Both addresses, and the id is irrelevant — nothing reads it. */
    public static function routes(): array
    {
        return [
            'export download' => ['/filament/exports/1/download'],
            'export download, with a format' => ['/filament/exports/1/download?format=csv'],
            'export download, non-numeric id' => ['/filament/exports/abc/download'],
            'import failure csv' => ['/filament/imports/1/failed-rows/download'],
        ];
    }

    #[DataProvider('routes')]
    public function test_a_guest_gets_a_404_and_not_a_database_error(string $path): void
    {
        $this->get($path)->assertNotFound();
    }

    /*
     * THE OWNER TOO. This is not an authorization fix — the feature is absent
     * for everyone, and a 404 that turned into a 500 once somebody signed in
     * would be a worse defect than the one being fixed.
     */
    #[DataProvider('routes')]
    public function test_a_signed_in_owner_gets_the_same_404(string $path): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        $this->actingAs($owner)->get($path)->assertNotFound();
    }

    /**
     * The tables really are absent, which is WHY the 500 happened. If a later
     * task publishes the migrations, this fails and the fix above needs
     * revisiting rather than silently 404ing a working feature.
     */
    public function test_the_tables_those_routes_bind_to_do_not_exist(): void
    {
        foreach (['exports', 'imports', 'failed_import_rows'] as $table) {
            $this->assertFalse(
                Schema::hasTable($table),
                "The `{$table}` table now exists, so the export/import feature may be in use. "
                .'Reconsider App\Http\Middleware\RejectUninstalledFeature, which 404s its routes.'
            );
        }
    }

    /**
     * And the claim the 404 rests on: no code in this application exports or
     * imports anything.
     */
    public function test_nothing_in_this_application_exports_or_imports(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            /*
             * COMMENTS STRIPPED, OR THIS TEST FAILS ON ITS OWN FIX: the
             * docblock of RejectUninstalledFeature says the word
             * "ExportAction" while explaining why there is no ExportAction.
             * php_strip_whitespace leaves the code and drops the prose.
             */
            $contents = php_strip_whitespace($file->getPathname());

            foreach (['ExportAction', 'ImportAction', 'ExportBulkAction', 'Filament\Actions\Exports', 'Filament\Actions\Imports'] as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = $file->getRelativePathname().' uses '.$needle;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'Something now uses Filament exports or imports, and their download routes answer 404.',
            'Publish the filament/actions migrations and remove RejectUninstalledFeature from the',
            "'filament.actions' middleware group in AppServiceProvider::boot().",
            ...$offenders,
        ]));
    }

    // ── THE SAME DEFECT, IN LIVEWIRE'S COMPONENT ASSET ROUTES ─────────────

    /**
     * Livewire publishes a per-component asset route whether or not any
     * component has assets, and threw ComponentNotFoundException — a 500 and
     * a log line — for a name that does not exist. Found by walking every GET
     * route as a guest.
     */
    public static function componentAssetRoutes(): array
    {
        return [
            'a component script' => ['/js/nope.js'],
            'a component stylesheet' => ['/css/nope.css'],
            'a component global stylesheet' => ['/css/nope.global.css'],
            'a name with a slash in it' => ['/js/../../etc/passwd.js'],
        ];
    }

    #[DataProvider('componentAssetRoutes')]
    public function test_an_unknown_livewire_component_asset_is_a_404(string $suffix): void
    {
        $prefix = EndpointResolver::prefix();

        $this->get($prefix.$suffix)->assertNotFound();
    }

    /**
     * AND THE REWRITE IS SCOPED. The same exception from a view that names a
     * misspelled component must stay a loud failure, not become a missing
     * page — otherwise this fix hides typos.
     */
    public function test_a_misspelled_component_in_a_view_is_not_turned_into_a_404(): void
    {
        $this->assertThrows(
            fn () => Blade::render('<livewire:no-such-component />'),
            ComponentNotFoundException::class,
        );
    }

    /**
     * The prefix is derived from APP_KEY, so it is read from Livewire rather
     * than written down anywhere — including in the fix.
     */
    public function test_the_asset_prefix_is_installation_specific(): void
    {
        $prefix = EndpointResolver::prefix();

        $this->assertMatchesRegularExpression('#^/livewire-[0-9a-f]{8}$#', $prefix);

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('z', 32)));

        $this->assertNotSame(
            $prefix,
            EndpointResolver::prefix(),
            'the Livewire endpoint prefix does not move with APP_KEY, so a literal would do'
        );
    }
}
