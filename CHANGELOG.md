# Changelog

## 0.1.0

Initial version.

- Create a request for one Individual (`CreateRequest`), snapshotting their key facts (birth/death date and
  place) at request time.
- Public, no-login guest page to view the snapshot and submit corrections/additions, a photo, and a
  free-text note (`Request`).
- Requests and their responses live in a single table (`webtreesshare_request`); nothing is written to the
  tree until the requester reviews and applies it.
- Requester review page: old vs. submitted values per field, accept individually, apply as a normal edit
  under the requester's own session (`RequestReview`).
- Email notification to the requester when a response comes in; a `RequestNotifications` endpoint and a
  webtrees menu badge for unread responses.
- Server-side "send by email" flow with a personalizable message (`RequestEmailTemplate`,
  `SendRequestEmail`) — the canonical template is always rendered server-side, never trusted from the client.
- After submitting, suggests the person's parents/children as further requests, ranked by how many of the
  fixed fields are still missing for them (`RequestContinue`).
- `Info` endpoint so a client (e.g. a companion app) can check the module is installed and enabled before
  offering the feature.
- Guest-submitted photos are held outside the tree's media library (webtrees' own "data" filesystem) until
  the requester accepts them; only then are they turned into a real media object, the same way
  webtreesand-api's own photo upload does it (content-hash filename, media object accepted immediately,
  link to the person a normal edit).

### Fixes since first deploy

- `module.php`'s autoloader didn't convert `\` to `/` when resolving a class in a sub-namespace
  (`WebtreesShare\Migrations\Migration0` → `src/Migrations/Migration0.php`), so the migration was never
  found on Linux hosting.
- `webtreesshare_request.gedcom_id`/`creator_user_id` now declared `unsigned()`, matching
  `gedcom.gedcom_id`/`user.user_id` (both auto-increment, hence unsigned) — InnoDB otherwise rejects the
  foreign key with errno 150.
- Every `viewResponse()` call now passes `tree` — the base layout needs it and `ViewResponseTrait` doesn't
  supply it automatically; the public guest pages were fataling without it.
- The person's name is stored as plain text (`strip_tags()`), not `fullName()`'s pre-formatted HTML — it
  was leaking raw markup into plain-text emails and getting double-escaped on display.

### Guest page redesign, real date pickers, more fields

- The three guest-facing pages (Request/RequestSubmit/RequestContinue) render through a new standalone
  layout with no webtrees site chrome at all - not appropriate for a visitor with no account - styled to
  match the companion app.
- Date fields use a real `<input type="date">`, converting to/from GEDCOM's `"12 MAR 1930"` format
  (`GedcomSnapshot::gedcomDateToIso()`/`isoDateToGedcom()`); a date the picker can't represent (a
  qualifier, a range, ...) is left blank with the old value shown as a hint.
- The guest form now also asks for given name, surname and title, alongside birth/death date and place.
  `WebtreesShareModule::FIELDS` entries carry a `kind` (`subline`, `value`, or `name_part`) since these
  don't all live in the same place in the GEDCOM - a title's value sits on its own fact line, and a
  changed given name/surname also rebuilds the record's primary `NAME` line so the display name stays
  consistent.
- The person's *existing* photo, if any, is snapshotted at request time the same way the other fields
  are, and shown on the guest page (`RequestExistingPhoto`) - without this, a signed-out guest had no way
  to see it, since the tree's media folder isn't reachable without an account.
