# Part 2a — inventory: every string and image a visitor can see

**Task:** 32, Part 2a
**Measured at:** `ec2e80d`
**Method:** `scratchpad/inventory.php` — strips Blade comments, directives and
echo expressions first, so what is counted is literal text between tags plus
the four attributes a visitor reads (`placeholder`, `aria-label`, `title`,
`alt`). Old-admin views are excluded because Part 6 deletes them; the Fortify
two-factor setup page is included because it survives.

## Counts by source

| Source | Count | Owner can change it today? |
|---|---|---|
| **Settings rows** (`settings` table, translatable) | **64 keys**, 57 referenced in public views | yes — Settings page |
| **Model columns** (project title, article body, service summary…) | 36 distinct attributes | yes — the resources |
| **Media library** (every content image) | 14 render sites, all via `<x-picture :media>` | yes — chosen per record |
| **Lang files** (`studio.*`) | 3 keys in public views | no — code |
| **Hardcoded in Blade** | **97 strings in 32 views** | **no — code** |

The 64 settings are the work of earlier tasks and already answer most of the
client's requirement. This inventory exists to name the 97 that do not.

## The 97 hardcoded strings, by verdict

### → MOVE TO THE PANEL (67)

Copy an owner would reasonably want to change.

| View | n | What |
|---|---|---|
| `archive/index.blade.php` | 10 | "Series", "Year", "All", the empty state's two lines, "See the whole sheet", the roll counter's label, the filter's aria-label |
| `projects/show.blade.php` | 6 | the metadata labels — Year, Client, Location, Categories, Credits — and the unpublished-preview warning |
| `work/index.blade.php` | 5 | "All", two empty-state lines, "See all work", the filter's aria-label |
| `journal/index.blade.php` | 5 | "All", the empty state, "Read everything", "min" (read time), the filter's aria-label |
| `contact/show.blade.php` | 5 | the honeypot label, "required"/"optional" markers |
| `pagination/editorial.blade.php` | 5 | Previous, Next, "Page n of m" |
| `layouts/public.blade.php` | 4 | **Instagram**, **Bluesky**, the copyright line, the nav's aria-label |
| `components/home/*.blade.php` | 7 | the home sections' headings and standfirsts — "Introduction", "Work, in series", "At length", "Read the story", "Loose frames", "Unfiled, most recent first" |
| `home.blade.php` | 2 | the empty state |
| `contact/sent.blade.php` | 2 | "Sent at", "Where to go next" |
| `journal/show.blade.php` | 2 | "min", "Where to go next" |
| `components/blocks/*.blade.php` | 4 | "Open on", "Watch the video", two gallery aria-labels |
| `components/theme-switch.blade.php` | 1 | "Colour scheme" |
| `pages/show.blade.php` | 1 | "Last updated" |
| `services/index.blade.php` | 1 | "of working" |
| `errors/*.blade.php` | 24 | six pages × (code, heading, explanation, "Return home") — **2b names these explicitly** |

Social links are listed here as **strings**, but they are links: the owner
needs the label, the URL and the ability to have neither. Hardcoding
`Instagram` and `Bluesky` also decides which two networks the studio uses,
for ever, in a Blade template.

### → KEEP IN LANG FILES (6 + 3)

Interface furniture with no editorial content. An owner changing "Skip to
content" is changing an accessibility affordance, not their website.

| String | Why not a setting |
|---|---|
| `studio.a11y.skip` | an accessibility affordance, identical on every site |
| `Language`, `current` (the locale switcher) | names the mechanism, not the studio |
| `components/auth-errors` "There … with what you entered" | a validation summary |
| `admin/two-factor-setup.blade.php` (6) | security copy; wrong wording here is a security instruction, not a style choice |

### → KEEP HARDCODED (2d, stated below)

| String | Why |
|---|---|
| `components/structured-data.blade.php` (1) | inside a JSON-LD escaping comment; not rendered text |
| `mail/inquiry-received.blade.php` (2) | the studio's own notification. Moves to lang, not settings: the owner is the only reader and a blank subject line would silently break delivery |

## Images

| Image | Source today | Verdict |
|---|---|---|
| Every content image (hero, covers, story blocks, gallery, archive frames, article featured, service covers, About portrait) | **media library**, via `<x-picture :media>` — the portrait already through `about.portrait_media_id` | already correct |
| Default Open Graph image | `config('seo.default_og_image')` = **`null`**, hardcoded | → **setting, media-picked** |
| Favicon | `public/favicon.ico` is Laravel's **0-byte placeholder**, and no `<link rel="icon">` exists in any layout | → **setting, media-picked**; the site currently has no favicon at all |

## What this means

The client's requirement was "the owner must be able to change every string,
image and section". Measured against that:

- **Images: effectively already met** for content. Two site-wide images — the
  share image and the favicon — are not, and one of them does not exist.
- **Strings: 64 of 161 editable** (the 64 settings against the 97 hardcoded).
  Section *headings and standfirsts* on the home page are settings; the
  *labels* around the content are not, which is why the site reads as
  half-controlled: an owner can rewrite "Introduction" but not "Year".
- The single largest cluster is the **error pages (24)** and the **metadata
  labels (16 across archive, projects, work, journal)**.

Everything after this section of the task is mechanical: these 67 become
settings rows, in both locales, with fallbacks.
