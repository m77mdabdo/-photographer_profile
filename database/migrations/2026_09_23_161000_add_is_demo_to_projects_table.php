<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Marks a project as seeded demonstration content.
     *
     * Media already carries this flag and projects did not, which meant the
     * only thing distinguishing a demo story from real work was that someone
     * remembered. A client logging in for the first time meets a shelf of
     * plausible-looking series with plausible-looking clients attached, and
     * needs to be able to tell at a glance which of them are ours.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false)->after('is_featured');
            $table->index('is_demo');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });
    }
};
