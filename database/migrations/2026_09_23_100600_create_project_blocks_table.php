<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Schema only — the Story Builder is a separate task. Settling the shape
     * now means the builder does not also have to be a migration.
     *
     * `data` holds per-type payload (text, embed url, spacer height). Image
     * references do NOT live here: they go through media_usages, keyed on the
     * block, so that deleting media stays protected by MediaPolicy.
     */
    public function up(): void
    {
        Schema::create('project_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_blocks');
    }
};
