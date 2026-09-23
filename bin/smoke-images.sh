#!/bin/sh
#
# Fetches the images a page actually points at, over HTTP, from a running app.
#
# WHY THIS IS NOT A PHPUNIT TEST
#
# Every image on this site 404'd in a browser for an entire task while the
# suite passed, and the suite could not have caught it:
#
#   1. Laravel's test client routes requests through the HTTP kernel. The
#      variant URLs resolve to /storage/..., which is a symlink served by the
#      web server from the filesystem — there is no route, so the test client
#      cannot fetch one however hard it tries.
#   2. Even if it could, APP_URL in the test environment matches the test
#      client's host. The bug was that APP_URL did not match the host the app
#      was actually SERVED on, which is a property of the deployment, not of
#      the code, and no in-process test can observe it.
#
# So this is the manual/CI half, and it is the half that fetches bytes.
#
# Usage:  bin/smoke-images.sh [base-url]           (default http://127.0.0.1:8000)
# Exit:   0 if every image on every checked page returns 200 with an image type.

set -eu

BASE="${1:-http://127.0.0.1:8000}"
PAGES="/ /work /archive"
FAILED=0
CHECKED=0

printf 'Smoke-testing images at %s\n\n' "$BASE"

for PAGE in $PAGES; do
    HTML=$(curl -fsS --max-time 30 "$BASE$PAGE" 2>/dev/null) || {
        printf '  FAIL  %s did not respond\n' "$PAGE"
        FAILED=$((FAILED + 1))
        continue
    }

    # Every distinct src on the page, first eight — enough to catch a
    # misconfigured host, few enough to stay fast.
    URLS=$(printf '%s' "$HTML" \
        | grep -o 'src="[^"]*storage[^"]*"' \
        | sed 's/src="//; s/"$//' \
        | sort -u \
        | head -8)

    if [ -z "$URLS" ]; then
        printf '  WARN  %s rendered no media images\n' "$PAGE"
        continue
    fi

    printf '  %s\n' "$PAGE"

    for URL in $URLS; do
        # Root-relative by design; absolute is accepted so the script also
        # works against a CDN-backed deployment.
        case "$URL" in
            http*) FULL="$URL" ;;
            *) FULL="$BASE$URL" ;;
        esac

        RESULT=$(curl -s -o /dev/null --max-time 30 -w '%{http_code} %{content_type}' "$FULL")
        CODE=${RESULT%% *}
        TYPE=${RESULT#* }
        CHECKED=$((CHECKED + 1))

        case "$CODE-$TYPE" in
            200-image/*)
                printf '    ok    %s  %s\n' "$CODE" "$URL"
                ;;
            *)
                printf '    FAIL  %s %s  %s\n' "$CODE" "$TYPE" "$FULL"
                FAILED=$((FAILED + 1))
                ;;
        esac
    done
done

printf '\n%d checked, %d failed\n' "$CHECKED" "$FAILED"

[ "$FAILED" -eq 0 ] || exit 1
