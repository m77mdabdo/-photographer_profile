<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cinematic sections — the home page's lighting galleries and featured media
 * (cinematic lighting brief, 22 Oct 2026). One table for all three kinds, so
 * the owner orders them as one sequence; the gallery's photographs in their
 * own table, in the order somebody arranged them.
 *
 * Lighting is stored as named presets, not numbers: an owner chooses
 * "Strong" or "Deep", never 0.62. App\Models\CinematicSection maps each name
 * to the values the page uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cinematic_sections', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 20);                       // gallery | image | video
            $table->json('title')->nullable();                // {"en": "...", "ar": "..."}
            $table->json('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_demo')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            // Lighting, as presets.
            $table->string('light_direction', 3)->default('ltr');  // ltr | rtl, on screen
            $table->string('intensity', 10)->default('balanced');  // soft | balanced | strong
            $table->string('darkness', 10)->default('deep');       // subtle | deep | night
            $table->string('pace', 10)->default('natural');        // slow | natural | quick
            $table->string('overlay', 10)->default('none');        // none | ink | warm | cool
            $table->string('mobile_layout', 10)->default('grid');  // grid | stack
            $table->boolean('animate')->default(true);

            // Featured media.
            $table->foreignId('featured_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('video_path')->nullable();
            $table->foreignId('poster_media_id')->nullable()->constrained('media')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('cinematic_section_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cinematic_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['cinematic_section_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cinematic_section_items');
        Schema::dropIfExists('cinematic_sections');
    }
};
