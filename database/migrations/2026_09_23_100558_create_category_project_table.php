<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_project', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            // One row per pair; attaching twice is a no-op, not a duplicate.
            $table->unique(['project_id', 'category_id']);

            // The project_id side is covered by the unique index's leading
            // column; this one serves "which projects are in this category".
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_project');
    }
};
