# 0001 — Livewire for the admin, vanilla for the public site

**Status:** accepted
**Date:** 2026-09-23

## Context

The admin was first built with Blade and vanilla JavaScript, deliberately, so
that the decision to adopt Livewire could be made against evidence rather than
taste. Two flows in the media library resolved themselves with
`window.location.reload()`:

- after the uploader's serial queue drained, to show the new rows
- after a background encode finished, because a processed image has renditions
  the DOM does not have

Both worked. Both were crude. Reloading discards whatever is in the search box
and the scroll position, and it re-runs every query on the page to update one
badge.

The next module is a story builder with reorderable blocks and live preview.
There, reloading is not an option: reconciliation *is* the feature.

## Evidence

Measured, not estimated.

| | Vanilla (Task 4) | Livewire (Task 5) | Change |
| --- | ---: | ---: | ---: |
| Admin JS, code lines only | 290 | 252 | −38 |
| — status poller | 64 | 0 | **−64** |
| — uploader | 134 | 134 | 0 |
| — focal point | 57 | 85 | +28 (drag added) |
| — sidebar + entry | 35 | 33 | −2 |
| Admin JS bundle | 5,500 B (2,219 gz) | 4,865 B (1,935 gz) | −635 B |
| Admin CSS bundle | 62,254 B (13,517 gz) | 63,351 B (13,735 gz) | +1,097 B |
| `window.location.reload()` in admin JS | 2 | **0** | −2 |
| Composer dependencies added | — | 1 (`livewire/livewire` v4.4.6, **no transitive deps**) | +1 |

The bundle figures **exclude Livewire's own runtime**, which the browser
fetches separately from `/livewire/livewire.js` (~30 kB gzipped). Honest
accounting: the admin ships *more* JavaScript than before, not less. What fell
is the JavaScript **we** maintain.

The poller is the clearest win. 64 lines of interval management, visibility
handling and badge-text sniffing became one conditional attribute:

```blade
<div @if ($hasUnfinished) wire:poll.3s @endif>
```

It stops polling when the last job finishes because the wrapper stops existing.
There is no timer to clear and no cleanup to forget.

## Decision

**Adopt Livewire for the admin only.**

- `@livewireStyles` / `@livewireScripts` appear in the admin layout and nowhere
  else.
- `config/livewire.php` sets `'inject_assets' => false`, so assets are never
  injected into a page that did not ask for them.
- The public home page was verified to contain zero matches for `livewire`,
  `wire:`, and the Livewire asset routes, and the built public bundles contain
  no occurrence of the string.

## What stays vanilla, and why

**The uploader.** It keeps its own `XMLHttpRequest` queue, uploading strictly
one file per request. This is not inertia — it is the constraint that keeps the
box alive. A media worker peaks near **302 MB RSS** while encoding a 4000×2667
image, and PHP's `memory_limit` does not constrain GD's allocations, so a
parallel upload burst is how you get an OOM kill that PHP cannot even report.
Livewire's file upload would hand that scheduling to the framework. We are not
trading that away for convenience.

What did change is only *what happens afterwards*: instead of reloading, the
uploader dispatches a browser event that the index component listens for.

```js
window.Livewire?.dispatch('media-refreshed');
```

**The focal-point picker.** Pointer dragging at 60fps has no business making
network round trips. It stays local and writes to plain number inputs, which
also keeps it working with no JavaScript at all.

**The entire public site.** It is served static HTML with responsive images and
no client framework. Livewire on a photography portfolio would be cost without
benefit.

## What would make us reverse this

- Admin page-weight becoming a real complaint. Livewire's runtime is ~30 kB
  gzipped on top of our own bundle.
- Round-trip latency making the story builder feel worse than a local-state
  implementation would — particularly drag-to-reorder, where every intermediate
  position would otherwise hit the server. If reordering needs local state and a
  single save, we will have learned that Livewire is the wrong tool for that
  specific surface, not for the admin as a whole.
- Livewire's file upload being needed for something, which would put the serial
  upload guarantee at risk.

Reverting is cheap while the surface area is two components. It gets expensive
once the story builder is built on it. That is precisely why this evaluation
happened on the media library first.
