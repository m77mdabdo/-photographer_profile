<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A column, not a naming convention.
     *
     * "Anything whose credit mentions Pexels" is the kind of rule that works
     * until somebody legitimately licenses a Pexels image, or renames a file,
     * or writes the purge query slightly differently. A boolean the purge
     * command can trust is the only thing that makes deletion safe.
     */
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('status');
            $table->index('is_demo');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });
    }
};
