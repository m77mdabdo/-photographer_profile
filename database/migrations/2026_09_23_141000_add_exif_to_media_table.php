<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Camera data, read from the original and stored as separate columns
     * rather than a JSON blob, because these are the six things the site
     * actually renders and each of them is queryable — "everything shot on the
     * 35mm" is a real question someone will ask of an archive.
     *
     * GPS IS ABSENT BY DESIGN. There is no column for it here and no code path
     * that reads it. A photographer's home coordinates are in the EXIF of most
     * frames shot indoors, and the safest place to put data you must never
     * leak is nowhere.
     */
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            // f-number. 4,2 covers f/0.95 to f/99.
            $table->decimal('exif_aperture', 4, 2)->nullable()->after('dominant_color');

            // Kept as the string the camera meant — "1/250" is not 0.004.
            $table->string('exif_shutter', 24)->nullable()->after('exif_aperture');

            $table->unsignedInteger('exif_iso')->nullable()->after('exif_shutter');
            $table->unsignedSmallInteger('exif_focal_length')->nullable()->after('exif_iso');
            $table->string('exif_camera', 120)->nullable()->after('exif_focal_length');
            $table->string('exif_lens', 120)->nullable()->after('exif_camera');

            // When the shutter fired, not when the file was uploaded.
            $table->timestamp('captured_at')->nullable()->after('exif_lens');

            /*
             * Distinguishes "we have not looked yet" from "we looked and this
             * frame carries nothing". Without it a backfill cannot tell which
             * rows it still has to visit, and would re-read every original on
             * every run forever.
             */
            $table->timestamp('exif_read_at')->nullable()->after('captured_at');

            $table->index('captured_at');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropIndex(['captured_at']);
            $table->dropColumn([
                'exif_aperture', 'exif_shutter', 'exif_iso', 'exif_focal_length',
                'exif_camera', 'exif_lens', 'captured_at', 'exif_read_at',
            ]);
        });
    }
};
