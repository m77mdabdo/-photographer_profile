<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 34 D3 — WHO CHANGED WHAT, AND WHEN.
 *
 * NO updated_at, AND THAT IS THE POINT. A log entry is written once and never
 * touched again; a column for "when this record of the past was altered" is
 * an invitation to alter it. The resource is read-only and the policy refuses
 * every write in words, but the SCHEMA saying so is the version that cannot
 * be worked around.
 *
 * nullOnDelete ON THE ACTOR. Deleting a user must not delete the record of
 * what they did — that is the one deletion an audit trail exists to survive.
 * The name is copied into actor_name at write time so the entry still reads
 * sensibly afterwards: "Removed by Imogen Hartley (deleted)" rather than
 * "Removed by nobody".
 *
 * THE SUBJECT IS A TYPE AND AN ID, not a foreign key. The thing acted upon is
 * usually gone by the time anybody reads about it — that is what "deleted"
 * means — so a constraint here would either block the delete or cascade the
 * evidence away.
 * ═══════════════════════════════════════════════════════════════════════════
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Kept so the entry still names somebody after the account goes.
            $table->string('actor_name', 120)->nullable();

            // 'published', 'deleted', 'role_changed', 'settings_saved', 'signed_in'…
            $table->string('event', 40);

            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            /* What the thing was called when it happened, for the same reason
             * as actor_name: a deleted project has no title to look up. */
            $table->string('subject_label', 200)->nullable();

            /* Anything else worth keeping — the role before and after, which
             * settings keys moved. Small by design: an audit row is not a
             * backup, and a diff of a 40 kB body would make it one. */
            $table->json('properties')->nullable();

            $table->timestamp('created_at')->nullable()->index();

            $table->index(['event', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
