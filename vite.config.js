import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

/**
 * Four entry points, two bundles. The public site must never download admin
 * CSS or JS, so the two module graphs are kept disjoint and any accidental
 * overlap is surfaced by name in the build output.
 */
export default defineConfig({
    plugins: [
        laravel({
            /*
             * Three entries, not four.
             *
             * resources/js/public.js was removed. The public layout stopped
             * referencing it in Task 11 when axios was taken off every public
             * page, but the entry stayed and Vite kept emitting a 138-byte
             * bundle plus a 51 kB shared vendor chunk that nothing requested.
             * The public site ships no JavaScript, so it has no JS entry.
             */
            input: [
                'resources/css/public.css',
                'resources/css/auth.css',

                /*
                 * admin.css and admin.js are GONE (Task 34 Part B). They were
                 * the custom admin's two entries; that admin was deleted once
                 * the panel had all six of its media capabilities. What the
                 * panel still uses out of them — the story builder and the
                 * focal-point control — now lives under resources/js/studio
                 * and resources/css/filament/studio, as entries of its own.
                 */

                /*
                 * The public bundle returns, with a purpose.
                 *
                 * It was removed in Task 23 because nothing on the public site
                 * used it and it was dragging 51 kB of axios in behind it.
                 * It is back for motion only, it shares no module with the
                 * admin entry, and its size is asserted against a budget by
                 * the test suite rather than watched by hand.
                 */
                'resources/js/public.js',

                /*
                 * THE PANEL'S THEME IS A FOURTH, SEPARATE ENTRY.
                 *
                 * Filament compiles its own Tailwind build from this file.
                 * Listing it here — rather than importing it from any existing
                 * entry — is what keeps the public bundle byte-identical after
                 * installing a framework of this size: three stylesheets that
                 * share tokens.css and fonts.css by @import, and share nothing
                 * else. Task 1's bundle-separation guarantee now has a third
                 * party in it and still holds.
                 */
                'resources/css/filament/studio/theme.css',

                /*
                 * THE STORY BUILDER'S JS, AS A FIFTH ENTRY.
                 *
                 * Task 32 Part 6 mounts the custom admin's StoryBuilder inside
                 * the panel. Its behaviour is 993 lines that already exist and
                 * are already imported by admin.js, so the panel needs a way
                 * IN to the same module without:
                 *
                 *   · importing admin.js, which also boots the sidebar, the
                 *     uploader and the focal-point control, none of which have
                 *     markup to bind to in the panel
                 *   · touching public.js, which is byte-for-byte pinned
                 *
                 * An entry of its own gives a chunk the story page loads with
                 * @vite and nothing else loads at all. Vite hoists nothing
                 * into the public bundle, because story-builder.js is imported
                 * only by admin.js and by this — never by public.js — and the
                 * test suite checks the public hashes either way.
                 */
                'resources/js/studio/story.js',
                'resources/js/studio/focal.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],

    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },

    build: {
        rollupOptions: {
            output: {
                /**
                 * Vendor code is assigned to a chunk named after the bundle
                 * that reaches it. A dependency pulled in by both entries
                 * cannot be duplicated by Rollup, so instead of silently
                 * becoming a chunk the public site also loads, it lands in
                 * `vendor-shared` — a name that makes the leak obvious in the
                 * build listing and in review.
                 */
                manualChunks(id, { getModuleInfo }) {
                    if (!id.includes('node_modules')) {
                        return;
                    }

                    const seen = new Set();
                    const stack = [id];
                    let reachedPublic = false;
                    let reachedAdmin = false;

                    while (stack.length > 0) {
                        const current = stack.pop();

                        if (seen.has(current)) {
                            continue;
                        }

                        seen.add(current);

                        const info = getModuleInfo(current);

                        if (!info) {
                            continue;
                        }

                        /*
                         * BOTH KINDS OF IMPORTER.
                         *
                         * Rollup keeps static and dynamic importers in
                         * separate lists, and walking only `importers` meant a
                         * dynamically imported dependency had no traceable
                         * owner at all — it fell through to 'vendor-shared',
                         * which reads as "the admin and the public site both
                         * use this" when in fact only one does.
                         *
                         * (ScrollTrigger was that case until Task 42 removed
                         * GSAP from the public site.)
                         */
                        for (const importer of [...info.importers, ...info.dynamicImporters]) {
                            if (importer.includes('/resources/js/admin') || importer.includes('/resources/css/admin')) {
                                reachedAdmin = true;
                            } else if (importer.includes('/resources/')) {
                                reachedPublic = true;
                            }

                            stack.push(importer);
                        }
                    }

                    if (reachedAdmin && !reachedPublic) {
                        return 'vendor-admin';
                    }

                    if (reachedPublic && !reachedAdmin) {
                        return 'vendor-public';
                    }

                    return 'vendor-shared';
                },
            },
        },
    },
});
