<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 34 D2 — WHAT CLIENTS SAID.
 *
 * Quote, who said it, what they do, an optional portrait, a published state
 * and an order.
 *
 * ALL THREE TEXT FIELDS ARE TRANSLATABLE, including the attribution. A name
 * is not translated but it IS transliterated: a studio writing for Arabic
 * readers spells "Imogen Hartley" in Arabic script, and a site that cannot
 * do that renders one Latin name in the middle of an Arabic sentence.
 *
 * NO is_demo COLUMN AND NO DEMO SEEDER, deliberately. Every other content
 * table has one so the site has something to show before the real work
 * arrives; a fabricated client quote is a different thing entirely. The
 * section hides itself when empty, which is the honest state of a studio
 * that has not collected any yet.
 * ═══════════════════════════════════════════════════════════════════════════
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('testimonials', function (Blueprint $table): void {
            $table->id();

            $table->json('quote');
            $table->json('attribution');
            $table->json('role')->nullable();

            /*
             * The portrait is optional and nullOnDelete: deleting a
             * photograph must not take a client's words with it.
             */
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();

            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testimonials');
    }
};
