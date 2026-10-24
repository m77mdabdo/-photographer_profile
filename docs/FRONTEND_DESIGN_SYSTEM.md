# Front-end design system

The rules the public site is built on, and where they live. Every value here is
a CSS custom property in `resources/css/tokens.css`; components use the
tokens, never literals.

## Files and layering

`resources/css/public.css` imports, in order:

1. `tokens.css` — palette, type, spacing, motion, z-index, both themes, the
   Arabic overrides.
2. `fonts.css` — `@font-face` for the web fonts, their metric-matched
   fallbacks (`size-adjust` per weight band, so the swap moves nothing) and the
   Arabic pages' figures subsets.
3. `base.css` — element defaults.
4. `components/*.css` — one file per component (header, layout, picture,
   blocks, statement, arrive, cinematic, motion, transition…).
5. `pages/*.css` — page compositions (home, archive, journal, offer = services,
   enquiry = contact).

The dashboard has its own theme (`resources/css/filament/studio/`), built as a
separate entry; nothing from it reaches the public CSS (asserted by
`StudioPanelTest`).

## Colour

Dark is the default; light follows the visitor's system or their choice
(`data-theme` on `<html>`, set by the theme switch).

| Role | Dark | Light |
| --- | --- | --- |
| `--bg` | ink `#0b0b0c` | warm paper `#f4f1ea` |
| `--bg-elevated` | `#141416` | `#e8e2d6` |
| `--text-primary` | paper `#f7f5f2` | warm ink `#17150f` |
| `--text-secondary` | warm grey `#8a8681` | stone `#5f574b` |
| `--accent` | brass `#b8a07e` | deep brass `#7d6136` |
| `--border` | paper at 12% | ink at 16% |

The home hero may tint its accent from the featured photograph
(`AccentColor`), always held above WCAG contrast against the ground.

## Typography

| Role | Token | English | Arabic |
| --- | --- | --- | --- |
| Display (wordmark, headings, statements) | `--font-display` | Archivo, condensed, weight `--wght-display` 800 | Rubik |
| Text | `--font-sans` | Inter Tight | IBM Plex Sans Arabic |
| Labels, figures, dates | `--font-mono` | JetBrains Mono | figures only (JetBrains Mono Figures), words in the text face |

Scale: `--fs-display-xl` … `--fs-meta`, all fluid `clamp()`s; line heights
`--lh-tight` 1.05, `--lh-heading` 1.15, `--lh-body` 1.65.

**Arabic rules** (`:root:lang(ar)` in `tokens.css`, ADR 0003):
- No letter-spacing: `--tracking-*` are 0 — tracking breaks the joins of a
  cursive script.
- No uppercase transforms carry meaning (Arabic has no case).
- Display line-height never below 1.1 — Arabic marks sit above and below
  the Latin band and are clipped by tight leading.
- Figures are Western (ADR 0005) and set in the Latin faces; on Arabic pages
  those faces load as small figures subsets so a space or a year does not
  download a whole Latin font.

## Space and grid

Spacing scale `--sp-1` (4px) … `--sp-16` (320px). Page gutter `--gutter`
(16–48px fluid). Compositions use a 12-column grid inside the gutter.
Hairlines: `--rule-hair` inside a group, `--rule-fine` for section heads,
`--rule-strong` for the one emphasised edge (active filter, current step).

## Breakpoints

One structural breakpoint: **900px**. `max-width: 899px` is the stacked,
single-column (phone and small tablet) layout; `min-width: 900px` is the
composed layout. Smaller refinements at 700/600px exist inside components.
The site is checked at 320, 360, 375, 390, 414, 768, 1024, 1280, 1440 and
1920 (`tests/Support/responsive-check.mjs`).

## Right to left

- Write layout with **logical properties**: `margin-inline-start`,
  `padding-inline-end`, `inset-inline-start`, `text-align: start/end`. They
  flip with `dir`, so one rule serves both languages.
- Grids flip on their own; a rule that names grid lines (`grid-column: 10 / -1`)
  lands on the other side in Arabic — that is usually right, and the hero
  relies on it.
- Mirror-only icons (arrows) flip with `scale: -1 1` under `[dir='rtl']`.
- Latin runs inside Arabic text are isolated (U+2068/U+2069) by
  `App\Support\Fallback`, so numbers and names read in the right order.
- **The hero is the one place the two languages compose differently**, and
  deliberately: the English wordmark is crossed by the photograph mid-word;
  Arabic cannot be read with a hidden middle (connected letters, no written
  short vowels), so the photograph sits over the space between the two words,
  each tucked against its edge — 0.06em, never under it (`.hero--split`,
  `App\Support\HeroSplit`, ADR 0003). The statement and link follow the
  English rule by photograph orientation, mirrored: before the plate for a
  portrait photograph, 32px from it in both languages. Names that are not two words take the vertical composition
  (`.hero--vertical`).

## Components to reuse

| Component | Use it for |
| --- | --- |
| `<x-picture :media=… sizes=…>` | every photograph: renditions, intrinsic size, focal point, lazy by default (`:priority="true"` for the LCP image) |
| `<x-t :of=$record field=…>` | a translatable field, with the fallback marked |
| `<x-statement>` | a large display sentence, accent on its last word |
| `<x-spec>` | label/value metadata rows |
| `components/blocks/*` | project story blocks, one per block type |
| `components/home/*` | home page sections, including the cinematic sequence |
| `<x-locale-switch>`, `<x-globe-switch>`, `<x-theme-switch>` | header controls |

## Motion

- Everything renders complete without JavaScript; motion is added.
- `prefers-reduced-motion: reduce` turns all of it off — the arrival layer
  and the cinematic engine are not even loaded.
- Only `transform` and `opacity` animate. Durations: `--dur-fast` 150ms,
  `--dur-base` 300ms, `--dur-slow` 500ms, `--dur-reveal` 1100ms; easing
  `--ease-out`, `--ease-editorial`.
- The arrival layer (`js/public/arrive/`) dissolves images and statements in
  as they enter; it is deterministic and reversible.

### Cinematic galleries

`js/public/cinematic.js`. A gallery section is given a height of
`100vh + (n − 1) × 100vh × pace` and its stage sticks while the visitor
scrolls through it; progress `p` (0–1) maps to a position
`a = p × (n − 1)` along the photographs. Each photograph's light is
`smoothstep(1 − |i − a| / softness)` and its veil opacity is
`darkness × (1 − light)` — so exactly one photograph is fully lit at a
whole-number position and the hand-over between two is as wide as the
*Transition* preset. On phones the strip translates by `a × stride` in the
reading direction. All inputs are the dashboard's named presets
(`CinematicSection::DARKNESS`, `PACE`, `SOFTNESS`…), so the numbers are not
in the markup. It is checked frame by frame by `tests/Support/cinematic-check.mjs`.

## Accessibility

- Tap targets at least 44×44 in the header and 24×24 everywhere (WCAG 2.2);
  checked at phone widths.
- Visible focus on everything focusable (`:focus-visible`, accent outline).
- One `<h1>` per page; the wordmark is one text node even where the
  photograph covers part of it.
- Alt text comes from the media library, per language.
- Contrast held by tokens and asserted by `register-contrast.mjs`.
