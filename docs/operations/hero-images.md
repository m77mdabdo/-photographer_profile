# Choosing a hero photograph

The home page hero is not a slot that accepts any picture. It interlocks the studio
name with a photograph by drawing an **opaque plate** over the middle of the word, and
it takes the page's accent colour **out of the photograph itself**. Both of those make
demands, and a frame that does not meet them degrades the page rather than breaking it.

This is written down because it is not discoverable from the code, and because the
admin can only advise — it will never stop anyone choosing the image they want.

## The three requirements

### 1. Low key

The plate is a hard-edged rectangle sitting on a near-black ground. A dark photograph
reads as a window cut into the page. A pale one reads as a white rectangle stuck on
top of it, and the two halves of the composition stop belonging to each other.

**Target: average brightness at or below 30%.** The admin reports the measured figure.

This is the requirement people break most often, because a bright, beautiful image
looks bright and beautiful in the media library and only falls apart in place.

### 2. A subject that survives a centre crop

The plate occupies between five and eight of the twelve grid columns, depending on the
photograph's shape, and it always sits around the middle. Roughly the **middle 55% of
the frame** is shown; the rest is cropped away.

A subject near the left or right edge will be cut in half. The admin measures where the
detail in the frame sits — the per-column spread of brightness, which is high across a
face and low across a backdrop — and warns when its centre drifts more than 18% from
the middle. It does not know *what* the subject is; it knows where the picture is busy.

### 3. One colour worth taking

The accent — the rule under the project link, the sequence label, the attribution, the
first stage marker — is derived from the photograph by picking the most **salient**
colour in it: strongly saturated, covering at least 1.5% of the frame, and near enough
to a readable lightness that it survives correction. See `App\Support\AccentPalette`.

A black-and-white photograph has no colour to lend. The page falls back to its default
accent, which is **the correct answer, not a failure** — but the hero is then not tied
to that picture the way the design intends.

## What the admin does

Tick **Featured on the home page** in the project form and a panel appears under the
checkbox reporting all three, each with the measured number and what it will look like.

It is advice. Nothing is disabled, no validation fails, and the save button does not
care. If the client wants a high-key black-and-white photograph with the subject hard
left, they can have it, and they will have been told what it will do.

## Finding suitable frames

`php artisan media:fetch-demo --art-direction=hero` searches for the kind of picture
this composition wants — dramatic studio portrait, low key portrait, moody fashion
editorial, chiaroscuro portrait, dark still life. Portraiture satisfies all three
requirements more reliably than anything else, which is why four of the five search
terms are portraits.

## A measured caution

Of nine images fetched under that art direction, **three turned out to be true
black-and-white photographs** — every entry in their extracted palette had a channel
spread of exactly zero. No colour-extraction method can find a hue in them, because
there is none.

So "low key" and "has a dominant colour" do pull against each other, but not for the
reason it first appeared. It is not that darkness destroys colour; it is that the
genres that produce low-key images include monochrome, and monochrome has no colour by
intent. The fix is in the brief given to whoever selects the photographs, not in the
extraction.

## If the hero requirements are relaxed

Everything here follows from the plate being opaque, which is what makes the wordmark's
readability **structural** rather than statistical — a letter is either fully on the
page ground or fully hidden, never at some contrast that depends on what the photograph
happens to contain. Solving a pale photograph with a scrim over the type would trade
that guarantee for an average-case one. Do not.
