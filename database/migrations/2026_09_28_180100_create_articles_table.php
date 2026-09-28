<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();

            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->text('excerpt')->nullable();

            /*
             * TWO BODY COLUMNS, AND THE REASON MATTERS.
             *
             * `body` is what the author typed: Markdown, the thing they can
             * come back and edit. `body_html` is what the page renders, and
             * it is written ONLY by App\Support\RichText on save — never by a
             * form, never by an import, never by hand.
             *
             * Keeping them apart is what makes "never trust stored HTML" a
             * property of the schema rather than a habit. There is no path by
             * which unsanitised markup reaches body_html, because the only
             * writer is the sanitiser, and the public template renders
             * body_html and nothing else.
             */
            $table->longText('body')->nullable();
            $table->longText('body_html')->nullable();

            $table->foreignId('featured_media_id')->nullable()->constrained('media')->nullOnDelete();

            /*
             * The byline. nullOnDelete rather than cascade: an article
             * outlives the person who wrote it, and deleting a staff account
             * must not delete the writing. The template falls back to the
             * studio's own attribution when this is null.
             */
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('article_category_id')->nullable()
                ->constrained('article_categories')->nullOnDelete();

            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();

            $table->boolean('is_demo')->default(false);

            // --- SEO ---------------------------------------------------
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();

            /*
             * An explicit canonical, for the case the field exists to serve:
             * a piece published here that also ran in a magazine, where the
             * magazine's URL is the original. Empty means "this page is the
             * canonical", which is what the resolver already assumes.
             */
            $table->string('canonical_url')->nullable();

            $table->timestamps();
            $table->softDeletes();

            /*
             * The public index's query shape, verbatim:
             *   where status = 'published' and published_at <= now()
             *   order by published_at desc
             */
            $table->index(['status', 'published_at']);

            // The category landing page adds one equality in front of it.
            $table->index(['article_category_id', 'status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
