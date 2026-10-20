# 0003 — The Arabic composition

**Status:** accepted — Composition A, chosen 2026-10-19 (see the Task 38 addendum)
**Date:** 2026-10-08
**Follows:** [0002](0002-filament-and-bilingual.md), which identified the problem and deferred it

## Why this exists

ADR 0002 listed five ways the hero fails in Arabic and said three were
mechanical and two killed the idea. That was reasoning, not evidence. This
document records what happened when the Arabic wordmark was actually rendered
under the real stylesheet — which corrected one of the five — and proposes
compositions that do not depend on the device that fails.

Nothing here is implemented. Step 9 of the plan in 0002 is where it lands.

---

## D1 — the occlusion, rendered

The hero's central device: one `<h1>`, one text node, with an opaque plate
sitting above its middle in the stacking order. In English you read
`PHOT⟦plate⟧APHER` and your eye supplies the missing letters.

The test rendered `فوتوغرافي` under the real `.hero__wordmark` rule, with
`--wordmark-size` copied verbatim from `home.css`, occluded by an opaque plate
of the same proportion.

### What the render actually showed

**The occlusion fails, but not the way 0002 predicted.** It does not produce
nonsense. It produces two fragments that are each perfectly legible as glyph
sequences — `فوت` on the right, `في` on the left — and the plate cuts *through
a connecting stroke* rather than between two letters. Each fragment ends in a
stub: a horizontal join that runs into the plate edge and stops, which is not
how any Arabic letter ends.

The practical failure is reconstruction, not legibility. English spelling is
redundant enough that four hidden letters out of twelve leave only one
plausible word. Arabic drops short vowels, so `فوت‥في` is compatible with a
large number of words and the reader has no redundancy to spend. The English
device works because the reader is completing a word they can already guess;
the Arabic reader is being asked to guess.

**Verdict: the mid-word occlusion does not transfer.** This is the one that
kills the idea, and it is confirmed.

### And the composition around it mirrors badly

Independently of the typography, running the browser harness with
`--locale=ar` reports the hero's supporting elements colliding with the plate
at desktop widths:

```
home: .hero__statement sits 0px from the plate edge     at 1440, 1024 (both themes)
home: .hero__attribution sits 0px from the plate edge   at 1440, 1024 (both themes)
home: .hero__link sits 0px from the plate edge          at 1440, 1024 (both themes)
home: .hero__link-rule sits 0px from the plate edge     at 1440, 1024 (both themes)
```

Four elements, two widths, two themes — the only failures in an otherwise
clean Arabic matrix (300/304). Under `dir="rtl"` the grid mirrors but the
offsets that hold these elements clear of the plate are physical, so they land
flush against it.

**These are deliberately not fixed.** The spacing belongs to a composition that
D2 proposes replacing; patching offsets on a layout that is going to be rebuilt
would be work thrown away, and it would also hide the signal. They are recorded
here as the empirical form of 0002's "the interlock mirrors" prediction, and
they are the acceptance criterion for whichever composition is chosen: the
Arabic matrix should reach 304/304 when the new hero lands, without a single
physical offset being added.

### Where 0002 was wrong

**`letter-spacing` is not the problem 0002 said it was.** 0002 called it "the
most damaging line in the file." Rendered at the actual value — `--tracking-tight`,
**−0.02em** — the effect is almost invisible, because *negative* tracking
tightens a cursive script rather than severing it. Side by side with
`letter-spacing: normal` the two are hard to tell apart.

The hazard is real but it is somewhere else: **`--tracking-wide: +0.08em`**,
which is where joins visibly break — and which is used **71 times** across the
stylesheets, on every uppercase mono label, eyebrow and meta line on the site.
The hero was the wrong place to look.

### Where 0002 was right

- **`line-height: 0.80` clips.** Demonstrated directly: at 0.80 the shadda
  (ّ) and the descenders leave the line box. At 1.25 the same string fits.
- **`text-transform: uppercase` does nothing.** Arabic is unicase; the rendered
  output is identical with and without it. The brand's entire typographic
  register — heavy condensed capitals — has no Arabic equivalent.

---

## D2 — three compositions

The brief for each: very large display type interlocked with a photograph, the
same density and precision, **without** relying on mid-word occlusion. All three
keep the existing grid, the plate's column logic derived from image
orientation, the metadata and stage rails, and the marquee.

A bilingual site is allowed two compositions. Forcing one layout onto two
scripts is how bilingual sites end up looking translated rather than designed.

### Composition A — the vertical interlock

The wordmark runs **down** the leading (right) edge of the plate rather than
across its middle, set at the same extreme size, reading top to bottom as a
single unbroken line. The photograph occupies the remaining width. The
interlock becomes an *edge* relationship: the plate's right edge and the
wordmark's spine share a single line, and the photograph tucks against the
word rather than covering it.

- **Keeps:** extreme scale, precision, a single uninterrupted text node,
  the photograph dominant.
- **Loses:** nothing structural. The occlusion is replaced by adjacency.
- **Risk:** vertical Arabic is unusual and can read as decorative rather than
  authoritative. Needs `writing-mode` handled carefully — the glyphs must stay
  upright and joined, which means rotating the *block*, not the text.
- **Why it is first:** it is the only one that preserves "the word and the
  photograph occupy the same space" without hiding any of the word.

### Composition B — the aperture

The wordmark is whole, large, and horizontal. The photograph is not a plate
across it but a **window cut into the counter-space above it** — a hard-edged
rectangle whose bottom edge is the wordmark's cap line, so the picture sits
*on* the word like a shelf. The word is never interrupted; the interlock is a
shared edge, and the eye reads the two as one object because they touch.

- **Keeps:** the whole word readable, the hard geometric edge, the density.
- **Loses:** the photograph is smaller; it no longer dominates the viewport.
- **Risk:** reads as a conventional masthead if the proportions drift. The
  precision has to carry it: the shared edge must be exact to the pixel.
- **Why it is here:** it is the lowest-risk option and the easiest to make
  responsive, because nothing overlaps.

### Composition C — the stacked column (the one closest to the English)

Two lines of Arabic at extreme size, stacked, with the photograph occupying a
**full-bleed column between them** — so the plate separates two lines of the
wordmark rather than interrupting one. Line one above, picture, line two below.
Each line is complete and joined; the interruption happens at a line break,
which is a place Arabic already permits a break.

- **Keeps:** the plate's vertical column, the photograph at full height, the
  sense of the word and image sharing one field.
- **Loses:** the single-`<h1>`-text-node property. Two lines means either a
  `<br>` or two spans, which affects copy-paste and screen-reader phrasing.
- **Risk:** needs a studio name that splits into two meaningful lines. With
  `مصوّر فوتوغرافي` that is natural (`مصوّر` / `فوتوغرافي`); with a
  one-word name it is not available at all.
- **Why it is here:** it is the only one that preserves the *feeling* of the
  English hero — the picture cutting through the type — while putting the cut
  somewhere the script tolerates.

**Recommendation if one must be picked now: A for the Arabic home page, B as
the fallback if vertical testing reads as decorative.** C is the most faithful
to the English and the most fragile, because it depends on the studio name's
shape.

---

## D3 — Arabic faces

SIL OFL, self-hosted, matching the three existing roles.

| role | Latin | Arabic | note |
| --- | --- | --- | --- |
| display | Archivo (condensed, 800–860) | **Rubik** | Real weight range to 900, geometric, confident at extreme size. Not condensed — no OFL Arabic is, see below. |
| text | Inter Tight | **IBM Plex Sans Arabic** | The closest neutral grotesque Arabic with a matching weight range; designed alongside Plex's Latin, so the proportions were reconciled by its designers rather than by us. |
| mono | JetBrains Mono | **nothing** | See below. |

**There is no condensed Arabic display face under OFL.** Condensation in Arabic
means compressing a cursive baseline, which changes the joins rather than just
the widths. The English hero's authority comes substantially from condensed
capitals; the Arabic hero cannot borrow it and has to find its authority in
scale and weight instead. That constraint is part of why D2's compositions
differ structurally.

### What the mono role becomes

**It does not get an Arabic face, because there is not one worth the name, and
substituting something that merely looks monospaced would be worse than the
honest answer.**

The reason is structural rather than a gap in the market: Arabic is cursive and
contextual, so a fixed advance width per character fights the script at every
join. The few fonts marketed as Arabic monospace achieve it by disconnecting
letters or distorting widths — which is precisely the failure D1 demonstrated,
adopted deliberately.

So the mono role **splits by content rather than by locale**:

- **Numerals, dates, EXIF, dimensions, file sizes, codes** stay in JetBrains
  Mono. These are the actual reason the site has a mono: they are tabular, they
  benefit from fixed advance, and they are largely script-neutral. Arabic pages
  may use either Western or Eastern Arabic numerals — a separate decision — but
  the role is unchanged.
- **Mono-as-a-voice — the eyebrows, the stage labels, the place line, the
  uppercase tracked captions** — becomes **IBM Plex Sans Arabic at the mono's
  size and colour, with `letter-spacing: normal`**. It keeps the role's
  *position* in the hierarchy (small, quiet, secondary) and abandons its
  *mechanism* (fixed width, wide tracking, capitals), because all three of
  those mechanisms are Latin-only.

Stated plainly: **in Arabic the site has two typefaces, not three**, and the
mono's job is done by size and colour rather than by a third face. Pretending
otherwise would mean shipping a font that makes Arabic look like a terminal
emulator rendering it badly.

---

## D4 — does the `size-adjust` fallback method transfer?

**No. It does not transfer, and a guarantee that quietly stops holding in one
locale is worse than no guarantee — so it should be stated as not holding for
Arabic rather than recomputed and hoped for.**

The Latin method, from `fonts.css`:

```
size-adjust      = webfont x-height ÷ fallback x-height
ascent-override  = webfont ascender ÷ size-adjust
descent-override = webfont descender ÷ size-adjust
```

with values tuned to a tenth of a percent (`size-adjust: 101.4%`,
`ascent-override: 86.6%`) against named local fallbacks: `Arial Narrow`,
`Helvetica Neue`, `Menlo`.

Two reasons it fails:

**1. The input metric does not exist.** The formula's numerator is x-height.
Arabic has no x-height — there is no lowercase, no mean line, and the visual
weight sits on the baseline with marks above and deep descenders below. The
nearest analogue is the height of a letter like ح, but it is not a metric fonts
report and it does not predict line-box behaviour the way x-height does for
Latin. The formula cannot simply be recomputed with different numbers; its
input is undefined.

**2. There is no fallback face to measure against.** The Latin fallbacks name
specific fonts that are present on the target platforms and whose metrics are
known constants. The Arabic equivalent differs per platform —
**Geeza Pro** on macOS and iOS, **Segoe UI** on Windows, **Noto Naskh Arabic**
on Android — with materially different proportions. A single `size-adjust`
cannot be correct for three faces at once, and `local()` cannot be used to pick
one reliably across all of them.

**Consequence, stated rather than buried:** Arabic pages will have a CLS budget
Latin pages do not. The two honest options are

- accept the shift and measure it, or
- ship Arabic with `font-display: block` and a short block period, trading a
  brief blank for a stable layout.

Either is defensible. Silently reusing the Latin numbers is not, and
`fonts.css` should carry a comment saying so the moment an Arabic face is added.

---

## D5 — every selector carrying the three hostile properties

Counted across `resources/css/`.

### `line-height` below 1 — **7 selectors**, all clip risks

| file:line | value | becomes in Arabic |
| --- | --- | --- |
| `pages/home.css:97` (`.hero__wordmark`) | 0.80 | **1.1 minimum.** Demonstrated clipping. The hero's `padding-bottom: 0.10em` compensation is tuned for Latin descenders and does not cover Arabic marks. |
| `pages/archive.css:31` | 0.92 | 1.15 |
| `pages/enquiry.css:35` | 0.92 | 1.15 |
| `pages/journal.css:36` | 0.92 | 1.15 |
| `pages/offer.css:43` | 0.92 | 1.15 |
| `pages/offer.css:360` | 0.94 | 1.15 |
| `pages/journal.css:203` | 0.94 | 1.15 |

All seven are display-size headings where sub-1 leading is doing real work in
Latin. The fix is a single logical override under `[lang="ar"]`, not seven
edits — but the list is here so the override can be verified to cover all of
them.

### `letter-spacing` — **94 token uses** (plus 3 literal), of which **71** are the hazard

- **`--tracking-wide: +0.08em` — 71 uses.** This is the one that breaks joins,
  demonstrated. It appears on every uppercase mono label: eyebrows, section
  headings, field legends, meta lines, the stage rail, `.marquee__item`,
  `.project__facts dt`, and throughout `components/auth.css`,
  `components/layout.css`, `pages/enquiry.css`, `pages/offer.css`.
  **Becomes `normal` in Arabic**, without exception.
- **`--tracking-tight: −0.02em` — 23 uses** (the hero and display headings).
  **Becomes `normal`** — harmless as measured, but there is no reason to keep
  a Latin optical correction on a script it was not measured against.

### `text-transform: uppercase` — **83 declarations**

Every one is a **no-op** in Arabic rather than a hazard: the property has no
effect on a unicase script. They can be left in place and will do nothing.

They are listed because the *design intent* behind them does not survive. 83
places on this site say "this is a label, and it is a label because it is
small, tracked and capitalised." In Arabic two of those three signals vanish
and the third must be removed. **Every one of those 83 places needs a different
way of saying "label" in Arabic** — weight, colour, size, or a rule — and that
is a design task, not a find-and-replace.

### The shape of the fix

One block, not two hundred edits:

```css
[lang="ar"] {
    /* The two Latin optical corrections, neutralised. */
    --tracking-tight: normal;
    --tracking-wide: normal;
}
```

That covers all 97 letter-spacing declarations, because every one of them reads
a token. The 7 line-heights do not read a token and need explicit overrides.
The 83 uppercase rules need nothing mechanical and a great deal of design.

---

## Addendum, Task 34e — the Arabic faces, and the swap

**Status:** accepted
**Date:** 2026-10-17

Everything above was written about an Arabic site with **no Arabic font**. Three
Latin faces were self-hosted, subset, metric-matched and pinned by a test that
fails on a 0.4-point drift, and Arabic rendered in whatever the operating system
supplied — Geeza Pro on macOS, Segoe UI or Tahoma on Windows, Noto Naskh on
Android. Half the product, and nothing in the suite or the browser harness
noticed, because a page set in a fallback renders, returns 200 and satisfies
every check that asks whether text is there.

### What ships

| Role | Face | File | Bytes |
|---|---|---|---|
| display | Rubik, variable 300–900 | `rubik-arabic.woff2` | 32,480 |
| text | IBM Plex Sans Arabic 400 | `ibm-plex-sans-arabic-400-arabic.woff2` | 33,512 |
| text | IBM Plex Sans Arabic 600 | `ibm-plex-sans-arabic-600-arabic.woff2` | 35,884 |

Both are SIL OFL, self-hosted with their licences beside them. There is no
subsetting tool on this machine — no `pyftsubset`, no fontTools, no
`woff2_compress` — so these are the per-script subsets Google Fonts publishes,
carrying the unicode-range they publish with them. For scale: the unsubsetted
upstream `Rubik[wght].ttf` is 359,804 bytes and every published Rubik subset
together is 123,840; `IBMPlexSansArabic-Regular.ttf` is 235,924 and its four
published subsets are 52,096.

**The mono role does not change.** The decision above stands: a monospace is a
Latin typographic device, and `--font-mono` resolves to the text stack under
`[lang='ar']`. Numerals, dates, EXIF and dimensions stay JetBrains Mono in both
languages because they are Latin in both.

### size-adjust does not transfer

The three Latin faces each have a metric-matched fallback: a `local()` face with
`size-adjust`, `ascent-override` and `descent-override` read out of the binary,
so the swap moves nothing. That technique cannot be applied here.

- **There is no x-height to match.** Arabic has no case. Its vertical rhythm is
  the baseline, the tooth height and the depth of the descending bowls, and
  `size-adjust` scales none of those relative to anything comparable.
- **The fallback is a different face on every system**, and `local()` cannot
  name "whatever this machine has". A single number tuned against Geeza Pro is
  wrong for Segoe UI and wrong again for Noto Naskh.

### The measured cost, and the decision

Layout shift on Arabic pages, cold cache, Slow-3G network emulation (400 ms
latency, 400 kbps), three runs each, Chrome's own `layout-shift` entries:

| Page | CLS |
|---|---|
| `/ar` | 0.0034 |
| `/ar/work` | 0.0024 |
| `/ar/about` | **0.0078** |

The worst Arabic page shifts **0.0078**, about one thirteenth of the 0.1 that
Core Web Vitals calls "good", and the shifts land on the navigation and the
facts list rather than on the hero.

**Decision: `font-display: swap` is kept.** `block` would hide Arabic text for
up to three seconds to avoid a shift of eight thousandths; `optional` would mean
a first-time Arabic reader never sees the designed face at all. Both trade the
reader's content for a number already an order of magnitude inside the
threshold. If the figure ever climbs — a heavier face, a slower host — the
measurement is repeatable and the decision is reversible in one declaration.

---

## Addendum, Task 38 Part A — Composition A is built

**Status:** accepted
**Date:** 2026-10-19
**Decided by:** the owner, in the Task 38 brief: *"A1 — ARABIC AT WIDE WIDTHS:
the vertical interlock, ADR 0003 option A, chosen by the owner."*

This document proposed three compositions and chose none for eleven days. A is
now built, at widths ≥900.

### What shipped

The wordmark runs down the leading edge of the plate at full display size as
one unbroken line; the photograph takes the width beside it; the statement and
attribution take the width beyond that. Nothing is occluded. Reading
right-to-left, the hero is **word · gap · photograph · statement**.

### The mechanism: the block rotates, the text does not

`writing-mode: vertical-*` makes the BOX vertical and then has to decide what
to do with each glyph, and the only orientation that keeps Arabic joined is a
whole-run rotation one would then be relying on as a side effect. A transform
says what it means. The element stays `horizontal-tb` and is rotated as a
rendered line, so its joins are the joins the horizontal line has — they are
that line. `-90deg`, not `+90deg`: CSS rotates clockwise, so `+90` would send
the start of a right-to-left line to the bottom and the word would read
upwards.

**The rotation is on a wrapper, not on the `<h1>`.** `hero-develop` animates
the wordmark with GSAP's `yPercent`, which writes the whole transform matrix;
a `transform` in the stylesheet was erased the moment the intro timeline
touched it — measured as `matrix(1, 0, 0, 1, 0, 15.517)`, a pure translation,
with the word still lying horizontally across the plate. Putting the rotation
in the timeline would have made the composition depend on JavaScript, so an
Arabic visitor with no script would see the broken occlusion. The `<h1>`
inside the wrapper is untouched: one text node, one heading, selectable and
copy-pasteable as one word, announced once.

### The numbers, and where they came from

| | value | how |
| --- | --- | --- |
| mean advance per character | **0.499em** | measured in the browser off the shipped Rubik at the shipped weight — `أنس فريد` sets 399.17px wide at 100px over 8 characters. Archivo's equivalent is 0.56, which is why the English formula could not be reused. |
| leading | **1.1** | D5 above demonstrated 0.80 clips the shadda and the descending bowls. The Latin `padding-bottom: 0.10em` is tuned for Latin descenders and does not cover an Arabic mark; it is removed. |
| gap | **32px** designed | the floor Task 34d reached by measuring, after two values tuned against screenshots were wrong. The word itself clears by **48px at 1440** and **45–46px at 1024**, the surplus being the line box's inset inside the rotated block. |

### D1's acceptance criterion, met

D1 said the new composition's acceptance criterion was that the Arabic matrix
reach a clean run "without a single physical offset being added". No physical
offset was added: the band is carried by the hero's own
`padding-inline-start`, and every placement is logical.

`tests/Support/hero-interlock.mjs` asserts the geometric claim directly — in
Arabic no part of the wordmark's box intersects the plate's. Run against HEAD
before any of this existed, it reported **0/8**:

```
1440   OCCLUDED by 567x243px      1024   OCCLUDED by 401x197px
 768   FLUSH (11px < 32px)         390   FLUSH (17px < 32px)
```

It now reports **8/8 clear**.

### The risk this document named, judged on the render

D2 recorded the risk plainly: *"vertical Arabic is unusual and can read as
decorative rather than authoritative."* Rendered at all four widths in both
themes, the honest judgement is that **it reads as typographic rather than
decorative**, and the reason is scale: at `min(68svh / (chars × 0.499), 13vw)`
the word is the largest object on the screen and is set in the display face at
display weight, so it reads as the masthead it is rather than as an ornament
down the side.

What is true and worth stating is that **a rotated line asks more of a reader
than a horizontal one.** That cost is accepted here because this is a
wordmark — a name recognised as a shape before it is parsed — and because the
alternative that this document proposed as the fallback, Composition B, buys
the horizontal line by making the photograph small enough to stop dominating
the viewport. If the owner judges on seeing it that the tilt is too much,
**B remains the stated fallback and that is a finding rather than a failure**;
the mechanism here is one rule block and would come out cleanly.

### Narrow widths: the stack is kept

Below 900 the hero already stacked, and Task 34d confirmed `أنس فريد` reads
whole there. The vertical interlock trades width for height and at 390 there
is neither: 68svh of run would set the word at about 66px, a third of its
stacked size, and the band would take nearly half the measure. Carrying the
reference's full-bleed treatment instead would put the wordmark back ON the
photograph, which is the occlusion problem again with a softer edge.

What changed at narrow widths is the clearance — measured at 11px (768) and
17px (390), under the 32px floor, so the two read as collided rather than
stacked — and the leading, which was still the clipping 0.80 because the fix
had been written inside the wide-width block.

### What the reference had that this hero does not take

- **The plate moving on scroll (A7):** already built. `motion/parallax.js`
  moves `.hero__image` by at most 6%, transform only, desktop only, and the
  Arabic composition keeps the same element, so it applies in both languages.
  No second mechanism.
- **A "scroll to discover" prompt (A8):** declined. At 390 the first screen
  ends partway down the photograph, which already says there is more below,
  and a fixed line of prompting copy is the reference's furniture rather than
  something this hero lacks.
- **Index rows under the narrow hero (A9):** declined, because they exist. At
  390 the metadata rail (year, place, client, series) and the stage list
  already sit below the photograph as label-on-the-leading-edge,
  value-on-the-trailing-edge rows, both from real content.

### What did not change

The English hero is untouched: **40/40 element boxes identical** before and
after at 1440, 1024, 768 and 390, measured after the intro timeline settles.
The plate keeps its orientation-derived SPAN; it loses only its mid-grid START,
which existed to centre the plate over a centred wordmark so it could occlude
it. CLS stays 0.0000 on `/` and 0.0006 on `/ar`, and the hero image keeps
`fetchpriority="high"` and no `loading` attribute.

---

## Addendum, Task 38 Part A second pass — the English composition, cut at the word

**Status:** accepted, supersedes the Composition A addendum above for two-word names
**Date:** 2026-10-20
**Decided by:** the owner, having seen the finished English hero: the Arabic
should be the *same* composition, not a different one that works.

### What shipped

At widths ≥ 900 the Arabic wordmark is set exactly as the English one is — one
line, display face, at the hero's extreme size — and crossed by the same plate
in the same orientation-derived span. The one change is where the plate falls:
**over the space between the two words.** English cuts mid-word because Latin
letters are unjoined and every fragment is still a letterform; Arabic is
cursive and its only break is between words, so "أنس" stands whole on one
side of the photograph and "فريد" whole on the other.

### Mechanism

- The `<h1>` is one text node. It is set `text-align-last: justify`, so a
  one-line, two-word name puts each word against one edge and stretches the
  only space across the middle. The plate is a layer above; the text does not
  know it is there.
- **The plate's place is derived from the name** (A2). The component splits
  the settings value into words and shares the columns the plate leaves free
  between the two sides in proportion to each word's *set width* — to a
  fraction of a column. The type is the largest size at which each word fits
  its own side with 0.18em to spare, capped at the English 30vh.
- **Set widths come from the font**, not from a character count.
  `bin/wordmark-metrics.py` reads each Arabic letter's isolated, initial,
  medial and final advances out of `rubik-arabic.woff2` (GSUB init/medi/fina)
  into `App\Support\WordmarkMetrics`, and the server applies Arabic joining to
  pick each letter's form. It reproduces the browser to three decimals
  (أنس 1.939em vs 1.938 measured; فريد 1.922 vs 1.920). A character count was
  tried first and misjudged the two words in opposite directions — أنس sets at
  0.646em per character, فريد at 0.480 — and put "أنس" 14px under the plate.
  No script runs, so the plate is never moved after first paint.

### Measured

`tests/Support/hero-interlock.mjs` now asserts that the plate crosses the
wordmark and that every character it covers is whitespace, reading each
character's box with `document.createRange()` so the `<h1>` is never split to
be measured. On d7c3d7d (the vertical interlock) it fails at 1440 and 1024:
"the plate does not cross the wordmark". Now 8/8: clear of the plate by
33–39px at 1440 and 24–28px at 1024, stacked 56px clear at 768 and 390.
Type 185.8px at 1440 against the English 224px — the most two words and a
portrait plate can share 1360px.

The English hero is unchanged at 1440, 1024, 768 and 390 in both themes:
at most 1/255 on one channel, zero pixels off by more than 8.

### One word, or more than two (A3)

One word has no gap to cut at, and three or more words have several, which a
single justified line cannot weight unequally while staying one text node.
Both keep the vertical interlock above, now scoped to `.hero--vertical`
rather than deleted. `HeroCompositionTest` asserts which name takes which.

### Narrow widths (A5): the stack, still

Below 900 the hero stacks and "أنس فريد" reads whole above the photograph. The
reference's phone layout — full-bleed photograph, wordmark over it — was
declined for the same reason as before: a word set over a photograph is at the
mercy of the frame behind it, and the cut-at-the-gap composition needs both
words *beside* the plate, which 390px cannot hold at any size worth setting.

A7 (parallax) exists; A8 (scroll prompt) and A9 (index rows) remain declined
for the reasons above.
