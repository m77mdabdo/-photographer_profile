<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Where the untouched original lives.
            $table->string('disk', 32)->default('media_originals');
            $table->string('path');
            $table->string('original_filename');
            $table->string('extension', 12);
            $table->string('mime_type', 96);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // Processing lifecycle.
            $table->string('status', 16)->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->text('failure_reason')->nullable();

            // Editorial metadata.
            $table->string('alt')->nullable();
            $table->text('caption')->nullable();
            $table->string('credit')->nullable();
            $table->string('copyright')->nullable();

            // Presentation hints.
            $table->decimal('focal_x', 4, 3)->default(0.5);
            $table->decimal('focal_y', 4, 3)->default(0.5);
            $table->text('lqip')->nullable();
            $table->char('dominant_color', 7)->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('checksum');
            $table->index('status');
            $table->index(['deleted_at', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
