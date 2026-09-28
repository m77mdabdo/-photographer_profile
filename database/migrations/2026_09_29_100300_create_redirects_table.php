<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();

            /*
             * Paths, not URLs, and always normalised to a single leading slash
             * with no trailing one and no query string. Storing "/old-name"
             * rather than "https://host/old-name" is what lets the same row
             * work in local, staging and production, and is the same reasoning
             * that made media URLs root-relative.
             */
            $table->string('from_path', 512)->unique();
            $table->string('to_path', 512);

            /*
             * 301 by default. A redirect created because a slug changed is
             * permanent by definition — the old address will never be correct
             * again — and a 302 there tells search engines to keep the old URL
             * indexed, which is the opposite of what the redirect is for.
             */
            $table->unsignedSmallInteger('status_code')->default(301);

            $table->boolean('is_active')->default(true);

            /*
             * Hit counting is deliberately a plain counter and not a log.
             * A log of every redirect hit is a request-rate table nobody reads
             * and a privacy surface nobody audited; a count answers the only
             * question anybody actually asks, which is "is this still needed".
             */
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();

            /*
             * Where it came from: 'manual' or 'slug_change'. An automatic
             * redirect and one somebody typed have different lifetimes, and an
             * editor clearing out old rows needs to be able to tell them apart.
             */
            $table->string('source', 24)->default('manual');

            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
    }
};
