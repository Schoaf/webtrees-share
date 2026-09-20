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
