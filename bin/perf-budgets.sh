#!/bin/zsh
# The performance budgets, measured on the production rig (docs/operations/performance.md).
#
#   bin/perf-budgets.sh LABEL [runs]
#
# Needs the rig running: `php artisan serve --port=8001` with production env,
# and `node bin/gzip-proxy.mjs --from=8090 --to=8001`.
#
# DANGER: this caches the PRODUCTION config for the whole app directory while it
# runs. Never run the PHP test suite at the same time — with a cached config the
# suite talks to the development database. The caches are cleared at the end,
# even if Lighthouse fails.
set -u
LABEL=${1:-run}
RUNS=${2:-3}
ROOT=${0:A:h:h}
OUT=$ROOT/storage/app/lighthouse
mkdir -p $OUT
cd $ROOT

clear_caches() { php artisan config:clear >/dev/null; php artisan route:clear >/dev/null; php artisan view:clear >/dev/null; }
trap clear_caches EXIT

export APP_ENV=production APP_DEBUG=false AUTH_REQUIRE_TWO_FACTOR=true
php artisan config:cache >/dev/null && php artisan route:cache >/dev/null && php artisan view:cache >/dev/null
unset APP_ENV APP_DEBUG AUTH_REQUIRE_TWO_FACTOR

curl -s -o /dev/null -w "served: %{http_code} " http://127.0.0.1:8090/
curl -s -D - -o /dev/null -H 'Accept-Encoding: gzip' http://127.0.0.1:8090/ | grep -i content-encoding | tr -d '\r'

for pair in "en /" "ar /ar"; do
  locale=${pair%% *}; page=${pair#* }   # not "path": in zsh that is $PATH
  for i in $(seq 1 $RUNS); do
    file=$OUT/$LABEL-$locale-$i.json
    caffeinate -dims npx --no-install lighthouse "http://127.0.0.1:8090$page" --only-categories=performance --quiet \
      --output=json --output-path=$file --chrome-flags="--headless=new" >/dev/null 2>&1
    node -e '
const r = require(process.argv[1]); const a = r.audits;
const fonts = a["network-requests"].details.items.filter((x) => /woff2/.test(x.url)).map((x) => x.url.split("/").pop() + ":" + Math.round(x.transferSize / 100) / 10 + "kB");
console.log(process.argv[2], "score", Math.round(r.categories.performance.score * 100), "FCP", (a["first-contentful-paint"].numericValue / 1000).toFixed(2), "LCP", (a["largest-contentful-paint"].numericValue / 1000).toFixed(2), "TBT", Math.round(a["total-blocking-time"].numericValue), "CLS", a["cumulative-layout-shift"].numericValue.toFixed(4), "bytes", (a["total-byte-weight"].numericValue / 1000).toFixed(1) + "kB", fonts.join(" "))' $file "$LABEL-$locale#$i"
  done
done

node bin/cls-after-scroll.mjs http://127.0.0.1:8090 / /work /ar /ar/work
