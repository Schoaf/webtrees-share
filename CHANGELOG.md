# Changelog

## 0.1.0

Initial version.

- Create a request for one Individual (`CreateRequest`), snapshotting their key facts (birth/death date and
  place) at request time.
- Public, no-login guest page to view the snapshot and submit corrections/additions plus a free-text note
  (`Request`).
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
