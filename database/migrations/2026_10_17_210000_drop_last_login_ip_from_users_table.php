<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 36 E1 — A RAW IP COLUMN THAT NOTHING WRITES AND NOTHING READS.
 *
 * users.last_login_ip arrived with the login-tracking migration and was never
 * wired to anything: no code in the application sets it, no screen shows it,
 * and every row in the development database was null. It is removed rather
 * than filled, for two reasons.
 *
 * THE FIRST IS THE PROJECT'S OWN POSITION ON IP ADDRESSES, already taken
 * twice and written down both times: Inquiry::hashIp stores an HMAC of a
 * visitor's address and never the address, and the activity log deliberately
 * records a failed sign-in's email and NOT its IP — "an audit screen is not a
 * reason to start keeping them in the clear". A raw last_login_ip would be
 * the one place this application contradicted that.
 *
 * THE SECOND IS THAT IT WOULD ANSWER NOTHING. The question a studio of two
 * people asks is "was somebody in the account", and last_login_at — which
 * the panel already has a column for, and which this task finally fills —
 * answers it. The address would add a jurisdiction and a home town to a
 * table that holds neither.
 *
 * Reversible: down() puts the column back, empty, which is exactly the state
 * it has always been in.
 * ═══════════════════════════════════════════════════════════════════════════
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'last_login_ip')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('last_login_ip');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'last_login_ip')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
        });
    }
};
