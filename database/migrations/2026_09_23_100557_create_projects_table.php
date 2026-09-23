<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();

            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->text('excerpt')->nullable();
            $table->longText('description')->nullable();

            $table->unsignedSmallInteger('year')->nullable();
            $table->string('location')->nullable();
            $table->string('client')->nullable();

            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();

            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('hero_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            /*
             * This is the public listing's query shape, verbatim:
             *   where status = 'published' and published_at <= now()
             * A composite index in that column order lets MySQL satisfy the
             * equality and then range-scan the timestamp, rather than filtering
             * every published row by date.
             */
            $table->index(['status', 'published_at']);

            // The home page's shape: featured first, then by hand-set order.
            $table->index(['is_featured', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
