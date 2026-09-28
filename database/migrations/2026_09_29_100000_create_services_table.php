<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();

            $table->string('name');
            $table->text('summary')->nullable();

            /*
             * Two body columns, the same arrangement Article uses and for the
             * same reason: `description` is the Markdown an editor types,
             * `description_html` is what the page renders and is written ONLY
             * by App\Support\RichText on save. There is no path by which
             * unsanitised markup reaches the column that is printed unescaped.
             */
            $table->longText('description')->nullable();
            $table->longText('description_html')->nullable();

            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();

            /*
             * PRICE AS TWO COLUMNS, and neither of them is a float.
             *
             * `price_from_pence` is an integer because money in a float is a
             * rounding error waiting for a client to notice. `price_note` is
             * the sentence that goes with it — "per day, plus expenses" — so
             * the page never has to invent the unit.
             *
             * Both nullable: a studio that quotes on request has no starting
             * price, and inventing "from £0" would be worse than silence.
             */
            $table->unsignedInteger('price_from_pence')->nullable();
            $table->string('price_note')->nullable();

            $table->string('cta_label')->nullable();

            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            // --- SEO ---------------------------------------------------
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->string('canonical_url')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The public index's query shape, verbatim.
            $table->index(['status', 'published_at']);
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
