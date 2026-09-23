<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();

            /*
             * Dotted keys, unique. The prefix is not the group: the group is
             * how the admin arranges the form, the key is the address the code
             * asks for, and letting one imply the other would mean moving a
             * field between panels changed its address.
             */
            $table->string('key')->unique();

            // Nullable and always text. The cast column says how to read it,
            // so a boolean and a paragraph can share one column honestly.
            $table->text('value')->nullable();

            $table->string('type', 16)->default('string');
            $table->string('group', 32)->default('general');

            $table->timestamps();

            $table->index('group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
