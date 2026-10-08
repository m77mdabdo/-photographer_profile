# 0003 — The Arabic composition

**Status:** investigation complete, compositions proposed, none chosen
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
