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
                'resources/css/admin.css',
                'resources/js/admin.js',

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

                    /*
                     * SCROLLTRIGGER GETS ITS OWN CHUNK.
                     *
                     * It is 18 kB gzipped and exactly one module needs it —
                     * the hero parallax, which imports it on demand. Grouping
                     * it with GSAP core under one name made Rollup merge the
                     * two, and the entry's STATIC import of core then dragged
                     * ScrollTrigger onto every page: the journal and the
                     * archive would each have paid 45 kB for a scrub they
                     * never run.
                     *
                     * Named separately, it stays a dynamic chunk and only a
                     * page carrying data-motion="parallax" fetches it.
                     */
                    if (id.includes('gsap/ScrollTrigger')) {
                        return 'gsap-scrolltrigger';
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
                         * ScrollTrigger is exactly that case: imported on
                         * demand by the parallax module and by nothing else.
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
