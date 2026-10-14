<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TASK 34 D1 — THE NAVIGATION BECOMES DATA.
 *
 * Until now the public navigation was six entries hardcoded in
 * layouts/public.blade.php, with only their LABELS coming from settings. The
 * owner could rename "Work" and could not reorder it, remove it, or add a
 * link to a page they had just written. Adding one meant a developer.
 *
 * WHY MENUS ARE FIXED ROWS AND ITEMS ARE NOT.
 *
 * A menu is a PLACE IN THE DESIGN — the header strip, the footer column.
 * There are two, the templates render those two, and a third that nothing
 * renders would be a row nobody ever sees. So menus are seeded and not
 * creatable, exactly like settings keys, and the editing happens in the
 * items.
 *
 * WHY A TARGET IS NOT A URL.
 *
 * An internal link is stored as a route name or a page id, never as typed
 * text. "Internal links resolve to real routes" is then true by construction
 * rather than by validation: there is no way to express /wrok in the first
 * place, and renaming a page cannot leave the menu pointing at a 404.
 * External links are the only free text, and they are validated as URLs and
 * marked so the template can add rel="noopener".
 * ═══════════════════════════════════════════════════════════════════════════
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table): void {
            $table->id();

            /*
             * The place in the design this menu fills. Unique, because two
             * menus claiming the header is a question with no right answer.
             */
            $table->string('location', 32)->unique();

            $table->timestamps();
        });

        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();

            // The word a visitor reads, per locale.
            $table->json('label');

            /*
             * 'route'    a named route this application defines
             * 'page'     a flat page, by id, so renaming its slug is safe
             * 'external' somewhere else entirely
             */
            $table->string('type', 16);

            // For 'route': the route name. For 'external': the URL.
            $table->string('target', 2048)->nullable();

            // For 'page': which one. Null for the others.
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['menu_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menus');
    }
};
