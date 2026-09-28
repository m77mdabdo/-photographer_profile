<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Internal notes on an enquiry. Never shown to the enquirer, and written
     * on the assumption that one day they might be — a note is a business
     * record, and a subject access request would reach it.
     */
    public function up(): void
    {
        Schema::create('inquiry_notes', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('inquiry_id')->constrained()->cascadeOnDelete();

            /*
             * Nulled rather than cascaded when the author's account goes: the
             * note is a record of what was decided, and deleting a colleague
             * must not delete the reasoning behind a booking.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->text('body');

            $table->timestamps();

            $table->index(['inquiry_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_notes');
    }
};
