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
        Schema::table('users', function (Blueprint $table) {
            // Defaults to the lesser privilege: a new row is never an Owner
            // by accident.
            $table->string('role', 16)->default('editor')->after('password');

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();  // 45 fits IPv6

            $table->index('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'last_login_at', 'last_login_ip']);
        });
    }
};
