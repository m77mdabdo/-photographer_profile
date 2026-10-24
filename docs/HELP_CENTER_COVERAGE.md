# Help Center coverage

Measured, not estimated: `php artisan help:coverage`, from the live dashboard inventory taken by `tests/Support/dashboard-inventory.mjs` (commit a93e3ef, 2026-10-24T13:25:35.464Z). Regenerate both after any dashboard change.

**60 articles** in English and Arabic; **28 performed end to end** in the real dashboard by a browser check; the rest have every on-screen word they quote checked against the dashboard's own strings (`HelpKnowledgeBaseTest`).

**Controls named in Help: 970 of 980 (99%)**, counting English and Arabic separately. This is **not 100%**: see *What is not covered* below.

## What the Help Center is

- **Where:** System → Help (`/admin/help`), in the dashboard's language. Every view has its own address: `?q=` search, `?topic=`, `?article=`, `?guide=1`.
- **Home:** “What would you like to do?” / «تحب تعمل إيه في موقعك؟», a large search box, 12 popular tasks, 11 topics, a 14-lesson path *Learn to manage your website from zero*, the troubleshooting list, and the full guide.
- **Articles:** 60 — 47 tasks, 5 lessons, 8 problems. Each has, in English and Arabic: what you will accomplish, before you begin, where, numbered steps (with a keyboard-accessible *Guide me step by step* mode), what the fields mean, a check-that-it-worked list, problems and fixes, warnings, related tasks, time, level and who may do it. Words on screen are marked and must exist in the dashboard in that language.
- **Search:** English, Arabic and Egyptian Arabic; Arabic letter forms and diacritics normalised; request filler dropped (“how do I”, «ازاي», «عايز»); light stemming; partial words and typos; meanings through a synonym table; specific tasks rank above the long guide; *remove* / «شيل» is answered with a choice between hiding, deleting, deleting for good and restoring. Tested by `tests/Unit/HelpSearchTest.php` (26 real queries) and in the browser by `tests/Support/help-check.mjs`.
- **Contextual help:** 15 **?** buttons beside the fields most often misread (status, publish date, slug, SEO title and description, alt text, featured, price, and the seven lighting controls); each explains the field and opens its article.
- **Generated, so it cannot drift:** *Every sentence in Site settings, section by section* is built from the same registry and labels as the Settings page.

## By module

*Controls*: the fields, sections and actions on the module's screens and dialogs (table furniture and row data excluded). *Named*: how many of them an article names, in that language. *Tutorials*: articles that start on the module. *Performed*: of those, done end to end by a browser check. *Hints*: contextual **?** buttons in the module. *Phone*: what was checked at phone width.

| Module | Controls en/ar | Named en/ar | % | Tutorials | Performed | Search words | Hints | Phone |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Dashboard | 4 / 4 | 4 / 4 | 100% | 6 | 3 | 120 | 0 | layout at 320/390/768 |
| Projects | 55 / 56 | 55 / 56 | 100% | 13 | 10 | 218 | 4 | workflow on a phone + layout |
| Media | 22 / 21 | 22 / 21 | 100% | 7 | 3 | 139 | 1 | workflow on a phone + layout |
| Categories | 9 / 10 | 9 / 8 | 89% | 1 | 0 | 19 | 1 | not checked |
| Cinematic sections | 36 / 38 | 36 / 38 | 100% | 7 | 3 | 116 | 7 | layout at 320/390/768 |
| Journal | 31 / 31 | 30 / 31 | 98% | 1 | 0 | 16 | 5 | not checked |
| Journal categories | 5 / 6 | 5 / 5 | 91% | 1 | 0 | 12 | 3 | not checked |
| Tags | 5 / 8 | 5 / 6 | 85% | 1 | 0 | 15 | 1 | not checked |
| Pages | 19 / 19 | 19 / 19 | 100% | 1 | 0 | 17 | 5 | not checked |
| Services | 27 / 27 | 27 / 27 | 100% | 1 | 1 | 19 | 6 | layout at 320/390/768 |
| Clients | 8 / 8 | 8 / 8 | 100% | 1 | 0 | 19 | 0 | not checked |
| Testimonials | 16 / 17 | 16 / 17 | 100% | 1 | 0 | 15 | 2 | not checked |
| Navigation | 7 / 7 | 6 / 6 | 86% | 1 | 1 | 25 | 0 | not checked |
| Enquiries | 17 / 17 | 16 / 17 | 97% | 1 | 0 | 25 | 0 | layout at 320/390/768 |
| Users | 12 / 12 | 12 / 11 | 96% | 1 | 0 | 24 | 0 | not checked |
| Activity | 4 / 4 | 4 / 4 | 100% | 1 | 0 | 20 | 0 | not checked |
| Redirects | 11 / 11 | 11 / 11 | 100% | 1 | 0 | 17 | 0 | not checked |
| Site settings | 192 / 192 | 192 / 192 | 100% | 9 | 5 | 197 | 0 | layout at 320/390/768 |
| Account security | 2 / 2 | 2 / 2 | 100% | 2 | 0 | 40 | 0 | layout at 320/390/768 |
| Profile | 4 / 4 | 4 / 4 | 100% | 1 | 0 | 19 | 0 | layout at 320/390/768 |

## Tutorials performed in the dashboard

- `tests/Support/cms-roundtrip-check.mjs`: `update-contact-info`, `replace-home-photo`, `adjust-gallery-lighting`, `write-alt-text`, `edit-project`, `edit-seo`, `edit-service`, `english-and-arabic`, `change-a-word`, `edit-home-text`, `translate-arabic`, `edit-navigation-label`, `edit-footer`
- `tests/Support/help-tour-check.mjs`: `change-gallery-photos`, `upload-video`, `unpublish`, `delete-item`, `restore-item`, `edit-paragraph`, `cannot-sign-in`
- `tests/Support/mobile-publish-check.mjs`: `upload-photos`, `upload-from-phone`, `create-project`, `change-project-cover`, `preview-project`, `publish`, `sign-in`, `phone-dashboard`

## What is not covered

Controls on these screens that no article names, in each language. Most are self-explanatory (a list's own column of the same name) or are explained as a class rather than one by one — Site settings has 171 text fields, and “Change a word, a letter or a sentence anywhere on the site” teaches how to find any of them by its label rather than listing each.

- **Categories** — en: —; ar: استخدام واحد · استخدامان
- **Journal** — en: Changed by; ar: —
- **Journal categories** — en: —; ar: استخدام واحد
- **Tags** — en: —; ar: استخدام واحد · استخدامان
- **Navigation** — en: No links; ar: لا روابط
- **Enquiries** — en: Submit; ar: —
- **Users** — en: —; ar: أبداً

## Other limits

- The inventory opens each screen and its main dialogs; controls that appear only after an interaction (inside a collapsed menu link, the story builder's block editors, the picker) are documented from the code and the story builder's own strings, not crawled.
- Phone verification is browser emulation at 320, 390 and 768 px, not real devices.
- No screenshots are embedded in the articles; they name the exact words on screen instead, which a test keeps true.
