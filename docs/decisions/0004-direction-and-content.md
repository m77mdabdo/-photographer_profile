# 0004 — Direction and content must not disagree

**Status:** accepted
**Date:** 2026-10-12
**Task:** 32, Part 0

## The defect

With the locale cookie set to `ar`, every page in this application rendered
`dir="rtl"` while its interface text was still English.

The mechanism is two independent facts meeting:

1. **Direction follows the visitor.** All three layouts set
   `dir="{{ Locale::direction() }}"`, which reads the stored preference. That
   is correct and stays.
2. **Content did not follow anything.** Four of Fortify's five pages had their
   English typed straight into the markup with no `__()` call at all, and
   `lang/ar` contained one file — `studio.php`. Laravel 12 does not publish its
   own lang files; it loads them from `vendor/laravel/framework/.../lang/en/`
   and falls back to that English for any locale with no file of its own. So
   `auth.failed`, every `validation.*` message and both pagination controls
   resolved to English under `ar`.

The visible result is not merely "untranslated". Unicode's bidirectional
algorithm lays out an English sentence inside an RTL paragraph with its
**sentence-final punctuation at the visual start of the line**, labels mirror
to the right of their fields while reading left-to-right, and the eye has no
consistent place to start. A page whose direction and content disagree is
harder to read than either language alone would be.

### Measured, before the fix

| Surface | Latin words in the visible text under `/ar` |
|---|---|
| `/login` | `Email address`, `Password`, `Stay signed in on this device`, `Forgotten your password?` |
| `/forgot-password` | the entire page |
| `/ar` | 30 distinct |
| `/ar/work` | 23 |
| `/ar/journal` | 18 |
| `/ar/services` | 13 |
| `/ar/about` | 13 |
| `/ar/contact` | 60 |
| `/ar/archive` | 36 |

Hardcoded visitor-visible strings in Blade, by area:

| Area | Strings | Note |
|---|---|---|
| old admin | 417 | deleted at step 10 (Task 32 Part 6) |
| **public site** | **144** | Task 32 Part 2 moves these into the panel |
| error pages | 24 | six pages × four strings |
| vendor (published) | 3 | includes `alt="Laravel Logo"` in the mail header |
| email | 2 | |
| **total** | **590** in 59 files | |

## The second defect, found while reproducing the first

`forgot-password`, `reset-password`, `confirm-password` and
`two-factor-challenge` were all rendered by `<x-layouts::admin>` — the
**signed-in working layout**. An unauthenticated visitor who asked for a
password link was served the whole admin shell: the sidebar, every navigation
label, and a list that still named screens from before the panel's navigation
was rebuilt. `two-factor-challenge` is reached *half*-signed-in, which is
worse: Fortify has the credentials but not the second factor.

Only `login.blade.php` was on the front door's own layout.

## The decision

**Translate the pages. Direction keeps following the reader.**

The alternative — making `dir` follow the page's *content* language — is a
smaller change and the wrong one. These four pages survive the step-10
deletion of the custom admin, so they are the shipped product rather than
scaffolding, and an Arabic owner reading an English login form in a
left-to-right box has not been served; they have been told their language is
for the public site only. Direction follows the reader everywhere else on this
site. What was missing was content following direction.

### What that meant in practice

- All four stray views moved to `layouts.auth`, which also closes the
  navigation leak.
- Their copy moved into `studio.auth` in both locales — 23 keys.
- `lang/ar/auth.php`, `lang/ar/passwords.php`, `lang/ar/pagination.php` and
  `lang/ar/validation.php` added. The validation file is **complete**: all 109
  rules, 136 leaf messages. A partial file is worse than none, because Laravel
  resolves against the Arabic array first and falls back only for an *absent*
  key — so a half-finished translation answers in Arabic for `required` and
  English for `max`, which is this same disagreement at field level.
- `lang/en/*` is deliberately **not** created. English keeps coming from the
  framework; a local copy would be a second source of truth that silently wins
  on upgrade.
- **LTR islands stay LTR, per element.** An email address, a password and a
  six-digit code read left-to-right in every language, so each input carries
  its own `dir="ltr"` rather than the page being forced one way.

### The guarantee, not the intention

`FrontDoorDirectionTest` renders each front-door page under the `ar` cookie and
**fails on any run of three or more Latin letters in the visible body text**.
The studio's own name is set to an Arabic value in the test so there is no
legitimate exception left to excuse. That assertion is what found
`Skip to content` — in all three layouts, typed in English in two of them and
passed through `__()` with no translation in the third, so an Arabic visitor's
first tab stop was an English sentence on every page of the site.

## Scope deliberately left to later parts of Task 32

The same disagreement covers the whole public site (144 strings) and the six
error pages (24). Those are not fixed here because Part 2 moves most of them
out of Blade and into the panel entirely — translating a string in a template
that is about to become a settings row would be work done twice. Part 3d
extends the Arabic scan above to every public page.

The old admin's 417 strings are **not** being translated. It is deleted in
Part 6 and nothing would read them.
