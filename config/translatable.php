<?php

declare(strict_types=1);

return [
    /*
     * ───────────────────────────────────────────────────────────────────────
     * WHERE TRANSLATABLE MODELS ARE FOUND. Not which ones they are.
     *
     * TASK 38 0.2. The dashboard's translation worklist used to name four
     * model classes in a private const, and reported "nothing waiting" on a
     * site with eight more translatable models still in English — the
     * category names, the tags, the client list and the testimonials among
     * them. A longer hand-written list would have been the same bug with a
     * later expiry date, so the set is DISCOVERED: every class under these
     * paths that extends Model, uses the Translatable trait and whose table
     * appears in App\Support\TranslatableSchema.
     *
     * THIS IS A SEARCH ROOT, NOT A REGISTRY. Adding a model to app/Models
     * puts it in the worklist with no edit here and nothing to remember.
     * The entry exists so that the test suite can point the same discovery
     * at a fixture model and prove that a model added LATER is counted —
     * which a test naming today's twelve models cannot prove.
     * ───────────────────────────────────────────────────────────────────────
     */
    'model_paths' => [
        app_path('Models'),
    ],
];
