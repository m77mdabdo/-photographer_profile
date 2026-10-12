# 0005 — Western numerals in both locales, localised date formatting

**Status:** accepted
**Date:** 2026-10-12
**Task:** 32, Part 3e

## Decision

**Digits are Western (`0123456789`) in Arabic as well as English.** Dates,
month names and number *formatting* are localised; the glyphs are not.

## Why not Arabic-Indic (`٠١٢٣٤٥٦٧٨٩`)

Three reasons, in order of weight.

1. **This site is mostly showing camera data, and camera data is Western
   everywhere.** The archive, the project rail and the media library show
   focal lengths (`35mm`), apertures (`f/1.4`), shutter speeds (`1/250`), ISO
   values and pixel dimensions. Those are read as Western numerals in Arabic
   photography writing, on Arabic camera bodies, and in every lens
   specification the owner will ever quote. Rendering `ISO ٦٤٠٠` would be
   wrong, not merely unusual.

2. **Mixing is worse than either.** If EXIF stays Western — and it must — then
   Arabic-Indic dates beside Western apertures gives one page two numbering
   systems. A reader then has to decide, per number, which system they are
   looking at. One consistent system is legible; two is a puzzle.

3. **Regional reality.** Arabic-Indic digits are standard in Egypt and parts of
   the Gulf print tradition, and Western digits dominate the Levant, North
   Africa and almost all Arabic web interfaces. For a commercial studio whose
   Arabic-reading clients are likely to be agencies and art buyers, Western
   digits are the unremarkable choice. Arabic-Indic would read as a stylistic
   statement, and this is not the place to make one.

## What IS localised

- **Month and day names**, through Carbon's locale — `١٢ أكتوبر` is wrong but
  `12 أكتوبر 2026` is right, and that is what `->locale('ar')` gives.
- **Thousands separators and decimal marks**, through PHP's formatter where a
  number is large enough to need them.
- **Direction**, which the layout already handles: a Western numeral inside an
  RTL paragraph is laid out correctly by the bidirectional algorithm without
  help.

## What this decision rules out, deliberately

A `Numerals` helper. The first draft of the footer called
`Numerals::year(now()->year)`, and with this decision taken that method
returns its argument unchanged. A class whose only implementation is the
identity function is not an abstraction; it is a place for a future
contributor to wonder what it is for. If the decision is ever reversed, the
change is a helper introduced at that point with a reason attached — not a
seam left open for years in case somebody wants it.

## The guarantee

`ArabicRenderTest` asserts that no Arabic page contains an Arabic-Indic digit,
so a stray `ArabicNumber` formatter or a copied snippet cannot reintroduce one
silently.
