<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The journal's own categories, deliberately NOT the `categories` table.
     *
     * That table describes bodies of work — Editorial, Portrait, Landscape —
     * and its rows are the filters on /work. What a studio writes about is a
     * different vocabulary: process, equipment, a note from a commission, an
     * argument about printing. Sharing one table would mean either polluting
     * the work filters with essay topics or giving every article a category
     * that describes a genre of photograph rather than a genre of writing.
     *
     * Two small tables, each meaning one thing.
     */
    public function up(): void
    {
        Schema::create('article_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            /*
             * A category is a landing page with its own address, so it gets
             * the same SEO fields an article does. An empty one falls back to
             * the name and description at render time.
             */
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_categories');
    }
};
