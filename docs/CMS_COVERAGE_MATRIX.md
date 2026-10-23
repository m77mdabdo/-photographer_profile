# CMS coverage matrix

Every public element, where the owner changes it, where it is stored, and the
automated test that proves the change reaches the page. Verified 22 Oct 2026
against the code and the running site; the end-to-end dashboard round trips
below were run 23 Oct 2026.

**Legend** — **Editable**: changed in the dashboard, no code. **Fixed (reason)**:
intentionally not editable. **Gap**: not editable today; listed under
*Remaining work*.

Dashboard paths are as labelled in the English panel (the Arabic panel shows
the same structure in Arabic).

## Identity and every page

| Public element | Status | Dashboard location | Storage | Evidence |
| --- | --- | --- | --- | --- |
| Studio name (header wordmark, titles, footer) | Editable | System → Site settings → The studio → *Studio name* | `settings` (`site.name`, en/ar) | `HomepageTest::test_the_hero_holds_no_hardcoded_copy` |
| Logo image | Fixed (the brand is a typographic wordmark built from the studio name; no image logo exists in the design) | — | — | — |
| Browser tab icon | Editable | Site settings → Search & sharing → *Browser tab icon* | `settings` (`seo.favicon_media_id`) | `Studio/SettingsPageTest` |
| Navigation labels, order, visibility, destinations | Editable | The studio → Navigation → *The strip at the top* | `menus`, `menu_items` | `NavigationTest` |
| Footer links | Editable | The studio → Navigation → *The foot of the page* | `menu_items` | `NavigationTest` |
| Social links (up to 3) | Editable | Site settings → Footer & social | `settings` (`footer.social_*`) | `SiteCopyTest` |
| Copyright line | Editable | Site settings → Footer & social | `settings` | `SiteCopyTest` |
| Floating “Request a quote” button text | Editable | Site settings → *Quote button text* | `settings` (`closing.cta`) | `ContactPillTest` |
| Button destinations (quote → contact page) | Fixed (always the contact page; a configurable URL would allow sending visitors off-site) | — | — | `ContactPillTest` |
| Language switcher labels | Fixed (each language named in itself) | — | — | `HeaderControlsTest` |
| Error pages (403, 404, 419, 429, 500, 503) | Editable | Site settings → Error pages | `settings` (`errors.*`) | `SeoTest`, `EnglishRenderTest` |
| Search description, sharing image | Editable | Site settings → Search & sharing | `settings` | `SeoTest` |

## Home page

| Public element | Status | Dashboard location | Storage | Evidence |
| --- | --- | --- | --- | --- |
| Opening statement | Editable | Site settings → The studio → *Opening sentence* | `settings` | `CommercialContentTest` |
| Hero photograph | Editable | Projects → a project → *Hero image*, with **Featured** on | `projects.hero_media_id`, `is_featured` | `HomepageTest` |
| Hero accent colour | Fixed (derived from the hero photograph so text always clears the contrast floor) | — | computed | `AccentColorTest` |
| Hero video | Gap → use a **Featured video** cinematic section instead | — | — | — |
| Process steps (Brief / Plan / Shoot / Deliver) | Editable | Site settings → Home — the opening screen | `settings` (`stage.*`) | `HomepageTest` |
| Introduction text and label | Editable | Site settings → Home — the sections below | `settings` | `CommercialContentTest` |
| Selected projects (which, order) | Editable | Projects → *Featured* and drag order | `projects` | `HomepageTest` |
| Cinematic galleries, featured image/video | Editable | The work → Cinematic sections | `cinematic_sections`, `cinematic_section_items` | `CinematicSectionsTest`, `cinematic-check.mjs` |
| Lighting: direction, spotlight, darkness, transition, pace, strength, shadow, phone layout and width, on/off | Editable (presets only) | Cinematic sections → *Lighting* | `cinematic_sections` | `CinematicSectionsTest` |
| Cinematic section order and visibility | Editable | Cinematic sections → drag rows; *Shown on the home page* | `sort_order`, `is_active` | `CinematicSectionsTest` |
| Featured story | Editable | the featured project | `projects` | `HomepageTest` |
| Services strip | Editable | The studio → Services (order, names, summaries) | `services` | `CommercialContentTest` |
| Journal strip | Editable | Writing → Journal | `articles` | `JournalTest` |
| Recent images strip | Editable (follows published projects' images) | Projects / Media | `media_usages` | `ArchiveTest` |
| Client names | Editable | The studio → Clients | `clients` | `AboutPageTest` |
| Client logos | Gap (names only) | — | — | — |
| Testimonials | Editable | The studio → Testimonials | `testimonials` | `TestimonialsTest` |
| Closing invitation | Editable | Site settings → *Closing invitation* | `settings` | `HomepageTest` |
| Order of the other home sections | Fixed (editorial sequence; each section hides itself when it has no content) | — | — | `HomepageTest` |
| Section headings and labels | Editable | Site settings → Home — the sections below | `settings` | `SiteCopyTest` |

## Portfolio and projects

| Public element | Status | Dashboard location | Storage | Evidence |
| --- | --- | --- | --- | --- |
| Projects: create, edit, publish, schedule, delete, restore | Editable | The work → Projects | `projects` | `CrudPathsTest`, `WorkIndexTest` |
| Titles, subtitles, excerpts, descriptions, location, client (en/ar) | Editable | Project form, language tabs | `projects` (json) | `TranslatableContentTest` |
| Cover and hero images | Editable | Project form → Imagery | `projects` | `CrudPathsTest` |
| Story / gallery blocks (14 types), order | Editable | Projects → *Story* | `project_blocks` | `StoryBuilderControlsTest` |
| Credits | Editable | Project form → Credits | `projects.credits` | `CrudPathsTest` |
| Categories | Editable | The work → Categories | `categories` | `CrudPathsTest` |
| Portfolio page title, filters, empty state | Editable | Site settings → The work index | `settings` | `WorkIndexTest` |
| Image alt text and captions (en/ar) | Editable | The work → Media → *Edit* | `media` | `MediaLabelsTest` |
| Focal point | Editable | Media → *Inspect* | `media.focal_x/y` | `MediaDetailTest` |

## Services, About, Contact, Journal, pages

| Public element | Status | Dashboard location | Storage | Evidence |
| --- | --- | --- | --- | --- |
| Service names, summaries, descriptions, button labels, SEO (en/ar) | Editable | The studio → Services | `services` | `CommercialContentTest` |
| Service photographs (cover, gallery) | Editable (cover in the form; gallery assigned by the seeder or story tools) | Services → *Cover image* | `services`, `service_media` | `CrudPathsTest` |
| Service prices | Editable (empty shows *On request*) | Services → *Price from (pence)* | `services.price_from_pence` | `ServicesAndPagesTest` |
| Services page title and introduction | Editable | Site settings → Services | `settings` | `CommercialContentTest` |
| About: title, biography, process, button, portrait | Editable | Site settings → About | `settings` | `AboutPageTest` |
| Studio information (location, city) | Editable, empty by default (unverified facts) | Site settings → The studio | `settings` | `CommercialContentTest` |
| Studio photographs gallery | Gap (one portrait on About; studio work can be shown via projects or a cinematic section) | — | — | — |
| Contact page text and every form label | Editable | Site settings → Contact; Contact — the form itself | `settings` | `ContactFormTest` |
| Enquiry recipient email | Editable | Site settings → Contact → *Email address that receives enquiries* | `settings` | `ContactFormTest` |
| Enquiries: read, status, notes | Editable | People & messages → Enquiries | `inquiries` | `DashboardCompletionTest`, `EnquiriesTest` |
| Journal articles, sections, tags | Editable | Writing | `articles` etc. | `JournalTest` |
| Privacy notice, terms, other pages | Editable | Writing → Pages | `pages` | `ServicesAndPagesTest` |
| Per-page SEO title/description, canonical, indexable | Editable | each item's SEO fields | the item | `SeoTest` |
| Redirects | Editable | System → Redirects | `redirects` | `RedirectsTest` |

## Verified through the real dashboard (end to end)

A database field existing is not proof that the owner can change it. These
were changed **by a browser driving the real dashboard** (sign-in with
two-factor, typing into the real fields, pressing the real Save), then:
the form reloaded to prove the value was saved; the public page fetched in
English and in Arabic to prove it appears where it should; the original value
put back **through the dashboard**; and the touched database rows compared
before and after to prove nothing else changed. Run at a phone width (390) and
a desktop width (1440).

| Content | Changed in | Public page checked | Script |
| --- | --- | --- | --- |
| Home opening statement | Site settings | `/`, `/ar` | `tests/Support/cms-roundtrip-check.mjs` |
| Contact page heading | Site settings | `/contact`, `/ar/contact` | same |
| Social link | Site settings → Footer & social | footer, both languages | same |
| Search description (SEO) | Site settings → Search & sharing | `<meta name="description">` | same |
| Service name (en), summary (ar), SEO title | Services → edit | service page, `<title>` | same |
| Project title (en, ar) | Projects → edit | project page, both languages | same |
| Navigation label (en, ar) | Navigation → *The strip at the top* → Edit | header, both languages | same |
| Photograph alt text | Media → search → Edit | `alt` on the project page | same |
| Cinematic gallery title (en, ar) and darkness | Cinematic sections → edit | home page markup (`data-darkness`) | same |
| Cinematic featured image (replaced through the picker, then restored) | Cinematic sections → *Replace* → picker | home page image path | same |
| New project with an uploaded photograph, published from a phone | Projects → create (390 px) | the new project page | `tests/Support/mobile-publish-check.mjs` |

Result 23 Oct 2026: round trip **130/130**, mobile publishing **13/13**.

**Found by this testing and fixed:** in single-image fields, tapping a
photograph in the picker did nothing — the picker announced the choice on an
event no field listened to, so *Replace* could not change a cover, hero or
featured image from the dashboard. The picker now answers the field directly
(`MediaPicker::choose()`), removing an image clears the field, and two feature
tests pin both (`Studio/MediaPickerFieldTest`).

**Noted, harmless:** saving the navigation dialog renumbers the links'
internal order values (0, 10, 20… become 1, 2, 3…). The order itself is
unchanged and is all the site reads; the round trip asserts the order.

**Not driven through the browser:** uploading a featured *video* (the file
field is the same FilePond upload used for photographs, which is driven end to
end; the video's validation and rendering are covered by
`CinematicSectionsTest`).

## Fixed system elements (deliberately not editable)

| Element | Why |
| --- | --- |
| Typography, colours, spacing, motion timing | Design system; editing CSS from the dashboard would break contrast, layout and accessibility guarantees the tests enforce. Lighting is exposed as named presets instead. |
| Security headers, CSP, rate limits | Security configuration, not content. |
| Language list (English, Arabic) | Adding a language needs translation of every string and RTL/LTR review. |
| URL structure | Changing paths breaks inbound links; slugs per item are editable and redirect automatically. |

## Remaining work (gaps)

1. Hero video (use a featured-video cinematic section meanwhile).
2. Client logos (names only today).
3. A studio-spaces gallery with addresses — blocked on the owner's verified
   studio information.
