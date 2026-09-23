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
            input: [
                'resources/css/public.css',
                'resources/js/public.js',
                'resources/css/admin.css',
                'resources/js/admin.js',
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

                        for (const importer of info.importers) {
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
