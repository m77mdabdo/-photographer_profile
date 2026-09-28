<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * A commission enquiry from a stranger.
     *
     * This is the only table in the project holding another person's personal
     * data, so the shape of it is a privacy decision as much as a data one:
     * it stores what a studio needs in order to answer, and nothing that is
     * merely interesting. There is no user agent column, no referrer, no
     * session id and no raw IP — see ip_hash.
     */
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table): void {
            $table->id();

            // Where in the pipeline this enquiry is. See App\Enums\InquiryStatus.
            $table->string('status', 16)->default('new');

            // Which surface it arrived through, so a future newsletter or
            // referral form is distinguishable from the contact page.
            $table->string('source', 32)->default('contact_form');

            // --- who ---------------------------------------------------
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('phone', 40)->nullable();
            $table->string('company', 120)->nullable();

            // --- what they are asking for ------------------------------
            $table->string('project_type', 60)->nullable();
            $table->date('shoot_date')->nullable();
            $table->string('location', 120)->nullable();
            $table->string('budget_range', 40)->nullable();
            $table->string('heard_from', 60)->nullable();
            $table->text('message');

            /*
             * Consent, as a moment rather than a boolean.
             *
             * "They agreed" is not a defensible record; "they agreed at
             * 14:32 on the 3rd" is. The column is nullable because an
             * enquiry created by an admin on someone's behalf has no consent
             * moment to record, and a false timestamp would be worse than
             * none.
             */
            $table->timestamp('consented_at')->nullable();

            /*
             * A HASH of the submitter's IP, never the address.
             *
             * Rate limiting and abuse triage need to know "was this the same
             * origin as that", which a one-way hash answers completely. They
             * never need to know where somebody lives, which is the only
             * extra thing the raw address provides. The column cannot be
             * un-hashed and the admin never displays it.
             */
            $table->char('ip_hash', 64)->nullable();

            $table->timestamp('read_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            /*
             * The admin list filters by status and sorts by arrival, which is
             * one composite index rather than two single ones. Email is
             * indexed on its own because rate limiting looks it up directly,
             * and because "has this person written before" is the first
             * question anyone asks of a new enquiry.
             */
            $table->index(['status', 'created_at']);
            $table->index('email');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
