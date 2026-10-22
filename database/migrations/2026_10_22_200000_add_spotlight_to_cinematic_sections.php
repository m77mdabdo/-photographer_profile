<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The exclusive spotlight (master directive, Part 6): one photograph lit at
 * a time, a moving strip on phones, and direction that follows the language
 * unless the owner overrides it. Additive only — existing sections keep
 * their values; light_direction gains 'auto' as the default for new rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cinematic_sections', function (Blueprint $table): void {
            $table->boolean('spotlight')->default(true)->after('animate');
            $table->string('softness', 10)->default('soft')->after('spotlight');      // crisp | soft | gentle
            $table->string('mobile_width', 3)->default('80')->after('mobile_layout'); // 70 | 80 | 90 (% of the screen)
            $table->string('light_direction', 4)->default('auto')->change();          // auto | ltr | rtl
            $table->string('mobile_layout', 10)->default('track')->change();          // track | grid | stack
        });
    }

    public function down(): void
    {
        Schema::table('cinematic_sections', function (Blueprint $table): void {
            $table->dropColumn(['spotlight', 'softness', 'mobile_width']);
            $table->string('light_direction', 3)->default('ltr')->change();
            $table->string('mobile_layout', 10)->default('grid')->change();
        });
    }
};
