<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep what they typed, alongside what we could make of it.
     *
     * The date field was a native <input type="date">, which renders as a
     * stock mm/dd/yyyy control in a US-locale browser on a UK studio's site
     * and is the one thing on the page that is not typeset. It is now a plain
     * text field, and "Date, if you have one" invites answers a date picker
     * cannot hold: "late April", "the week of the 14th", "whenever the tide
     * is right".
     *
     * So both columns exist. `shoot_date` is the parsed value when there is
     * one, and drives any future sorting or diary view. `shoot_date_text` is
     * always exactly what the sender wrote, because "sometime in the spring"
     * is information and rounding it to a date would be inventing one.
     *
     * Neither is required, and a value that parses to nothing is still
     * stored — the submission is never refused over a date.
     */
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->string('shoot_date_text', 120)->nullable()->after('shoot_date');
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropColumn('shoot_date_text');
        });
    }
};
