<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Which directed set a demo image was fetched for.
     *
     * is_demo says a row is disposable; this says what it was fetched to be —
     * architecture, portrait, still-life. Without it the seeder cannot build
     * a body of work that hangs together, because nothing on the row records
     * that this frame was searched for as architecture rather than as a
     * landscape that happens to contain a building.
     *
     * Null for anything a real client uploads. It is demo bookkeeping and
     * nothing on the public site reads it.
     */
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->string('demo_set', 32)->nullable()->after('is_demo');
            $table->index(['is_demo', 'demo_set']);
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropIndex(['is_demo', 'demo_set']);
            $table->dropColumn('demo_set');
        });
    }
};
