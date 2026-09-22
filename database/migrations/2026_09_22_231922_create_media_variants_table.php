<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('media_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();

            $table->string('format', 8);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');

            $table->string('disk', 32)->default('media_public');
            $table->string('path');
            $table->unsignedBigInteger('size_bytes');

            $table->timestamps();

            $table->unique(['media_id', 'format', 'width']);
            $table->index(['media_id', 'format']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_variants');
    }
};
