<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flat editorial pages: privacy, terms, and whatever a studio adds next.
     *
     * WHY A TABLE RATHER THAN BLADE FILES
     *
     * A privacy notice changes when the law changes or when the studio starts
     * using a new processor, and neither of those is a deploy. Keeping the
     * text in a template means the only person who can correct it is whoever
     * has the repository, which is exactly the arrangement that leaves a wrong
     * privacy notice up for two years.
     *
     * `is_locked` protects the two pages other parts of the site link to by
     * slug. An editor may rewrite every word of the privacy notice; they may
     * not delete it or move it to /privacy-policy and leave the footer
     * pointing at a 404.
     */
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();

            $table->string('title');
            $table->text('standfirst')->nullable();

            // Markdown in, sanitised HTML out. See App\Support\RichText.
            $table->longText('body')->nullable();
            $table->longText('body_html')->nullable();

            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();

            /*
             * Some pages should exist and be reachable without being offered
             * to a search engine — a thank-you page, an internal notice. This
             * is the page's own answer, and the SEO resolver honours it.
             */
            $table->boolean('is_indexable')->default(true);

            $table->boolean('is_locked')->default(false);

            // --- SEO ---------------------------------------------------
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->string('canonical_url')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
