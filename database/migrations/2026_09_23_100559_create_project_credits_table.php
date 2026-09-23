<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A list, not columns. Credit roles vary per shoot — photographer,
     * stylist, MUA, retoucher, producer — and a column per role would need a
     * migration every time a new one appears.
     */
    public function up(): void
    {
        Schema::create('project_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('role', 64);
            $table->string('name', 128);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_credits');
    }
};
