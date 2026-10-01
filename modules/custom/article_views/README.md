# Article Views

Tracks which authenticated users have viewed which `article` nodes, once per
user per article, and exposes total and per-article view counts.

Depends on [user_api] for authentication (Bearer tokens via simple_oauth) and
reuses its JSON response/error conventions directly — no `?_format=json`
needed, same `{ "message": ..., "data": {...} }` / `{ "error": {...} }`
envelope, same error codes where they apply.

## Endpoint reference

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| `POST` | `/api/article/{nid}/view` | Bearer | Record that the current user viewed this article |
| `GET` | `/api/article/{nid}/views` | none | Distinct viewer count for one article |
| `GET` | `/api/articles/views/total` | none | Distinct viewer count across all articles |

### Record a view

```bash
curl -X POST https://your-site/api/article/42/view \
  -H "Authorization: Bearer $ACCESS_TOKEN"
```

`200 OK`:

```json
{
  "message": "View recorded.",
  "data": { "article_id": 42, "first_view": true }
}
```

Calling this again with the same token for the same article answers the same
shape with `"first_view": false` and no new row — the uniqueness is enforced
by the `article_views` table's `(uid, nid)` primary key, not a check in PHP,
so it holds even under two simultaneous requests from the same user.

### Article view count

```bash
curl https://your-site/api/article/42/views
```

```json
{ "message": "Article view count.", "data": { "article_id": 42, "viewers": 7 } }
```

### Total view count

```bash
curl https://your-site/api/articles/views/total
```

```json
{ "message": "Total article viewers.", "data": { "viewers": 213 } }
```

This is `COUNT(DISTINCT uid)` across every article — the number of people who
have read at least one article, not the number of article-views recorded (a
user who read 5 articles counts once here).

## Error codes

In addition to `user_api`'s existing codes (`unauthenticated`, `access_denied`,
`not_found`, `method_not_allowed`, `validation_failed`):

| Code | Status | Meaning |
| --- | --- | --- |
| `article_not_found` | 404 | No such node, it isn't an `article`, or the current user can't view it |

## Design notes

**Why a plain DB table, not a content entity.** This is a fact table (who
viewed what), not content with its own fields, revisions or admin UI — the
entity API's overhead buys nothing here. `hook_schema()` plus three indexed
columns is enough.

**Why `INSERT` + catch, not a `SELECT` then `INSERT`.** Checking for an
existing row first and inserting only if none is found is a race: two
requests from the same user arriving close together could both pass the
check before either inserts. Instead `record()` just attempts the insert and
catches `IntegrityConstraintViolationException` — the table's composite
primary key on `(uid, nid)` is what actually enforces "once", atomically, at
the database layer.

**Why the view-recording endpoint requires a token.** `uid` has to come from
the authenticated session, never a client-supplied value — otherwise any
caller could record (or inflate) view counts for other users' accounts.

**Why the count endpoints are public.** They return a number, not personal
data, matching the existing (open) `/api/stats` endpoint in `stats_api`.

**Shared error handling.** `user_api`'s `ApiExceptionSubscriber` was widened
to also cover `article_views.*` routes and `/api/article(s)` paths, so a
missing token or bad method on these routes gets the same JSON error
envelope as the rest of the API, rather than a themed HTML page.

[user_api]: ../user_api/README.md
