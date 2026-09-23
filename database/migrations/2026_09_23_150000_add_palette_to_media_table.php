<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * The colours a frame is made of, with each one's share of it.
     *
     * dominant_color stays exactly as it is. It is the average of the whole
     * frame, which is the right value for the tint behind a loading image and
     * the wrong value for an accent — those are two different questions and
     * this column is here because they were being answered with one number.
     *
     * JSON rather than a table: a palette is eight rows that are only ever
     * read together, with the image they belong to, and never queried across.
     */
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->json('palette')->nullable()->after('dominant_color');

            // Distinguishes "not extracted yet" from "extracted, and this
            // frame genuinely has no colour in it" — the same distinction
            // exif_read_at draws, and for the same backfill reason.
            $table->timestamp('palette_read_at')->nullable()->after('palette');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn(['palette', 'palette_read_at']);
        });
    }
};
