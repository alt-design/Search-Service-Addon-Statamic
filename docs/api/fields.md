# Fields API

**Status:** proposed, not built
**Service:** Search-Service-App
**First client:** Statamic addon `alt-design/search-service` (CP → Tools → Search Service → Fields)

A site reads and replaces its own index field configs using its site API key. Same shape as the Pipeline API: the client edits the whole list and saves it in one request, so there are two endpoints, read the list and replace the list.

This is the missing half of indexing. `index_field_configs` gates everything downstream:

- `IndexWriter::write` looks up a config per field and skips any field without one.
- `FilterToConfiguredFieldsTransformer` filters the document to the same list.
- `index_fields` has an FK to `index_field_configs` with `cascadeOnDelete`.
- Weight is copied onto each `index_fields` row at write time, and `SearchService` ranks with `sum(ts_rank_cd(...) * f.weight) + d.boost`.

Until a site has configs, `POST /api/index` returns 202, writes an `index_documents` row, stores no field values, and `/api/search` returns nothing. Nothing reports an error.

## Conventions

Follow the existing API rather than CLAUDE.md defaults (see `.ai/rules`, `routes/api.php`):

- Routes go inside the existing `auth:sanctum` + `track.usage` group, under `throttle:site-api`.
- Invokable controllers in `App\Http\Controllers\Api`, inline `$request->validate()`, hand-built `response()->json()`, snake_case keys, no version prefix, no API Resources.
- The site is always `$request->user()`. Never accept a site id in the URL or body.
- Writes go through `DB::connection('search')` (all three index tables live on the `search` connection).

```php
Route::middleware('throttle:site-api')->group(function () {
    Route::get('/fields', FieldsController::class);
    Route::put('/fields', UpdateFieldsController::class);
    Route::delete('/index/{reference}', DeleteIndexController::class);
    // ...existing routes
});
```

---

## Field naming

`index_field_configs` is unique on `(site_id, field)` and holds one flat string per field. The service treats that string as opaque: it never parses it, and it must match the key the client sends in `POST /api/index` `documents.*.fields` exactly.

Clients that index more than one content type prefix the field name with the type, so weights can differ per type:

```
articles.title
articles.body
pages.title
```

The Statamic addon uses `<collection handle>.<field handle>`. This is a client convention, not a service rule: a client indexing one type can send bare `title` and it works the same.

Consequence worth stating plainly: because the service matches on the whole string, changing the convention changes what search matches against. Moving an existing site from `title` to `articles.title` needs a full reindex, and until that reindex finishes, the old rows keep their old configs and the new ones find no config and store nothing.

Accepted characters: `a-z A-Z 0-9 _ - .`, max 255. Anything else is rejected, so a field name can't carry whitespace or SQL-ish punctuation into the index.

---

## GET /api/fields

Returns the caller's field configs, ordered by field name.

**200**

```json
{
    "fields": [
        { "field": "articles.body", "weight": 1 },
        { "field": "articles.title", "weight": 20 },
        { "field": "pages.title", "weight": 10 }
    ]
}
```

A site with no configs returns `{"fields": []}`. That is the state every new site starts in, and the client should treat it as "indexing will store nothing yet" rather than as an empty success.

---

## PUT /api/fields

Replaces the whole list. The request body is the complete set of fields the site wants indexed; anything not in it is removed.

**Request**

```json
{
    "fields": [
        { "field": "articles.title", "weight": 20 },
        { "field": "articles.body", "weight": 1 }
    ]
}
```

**200** — the saved list in `GET /api/fields` shape, plus what the write removed:

```json
{
    "fields": [
        { "field": "articles.body", "weight": 1 },
        { "field": "articles.title", "weight": 20 }
    ],
    "removed": ["articles.summary"]
}
```

`removed` is always present, empty list included, so a client can show a result without checking for the key.

### Behaviour

Three things the implementation has to get right, because the obvious version of each is wrong.

**1. Upsert, do not delete and re-insert.** `UpdatePipelineController` deletes every step and inserts the submitted list, which is fine for pipeline steps because nothing references them. Doing that here would cascade through the `index_fields` FK and empty the entire index on every save, including for fields whose weight never changed. Fields present in both the stored list and the payload keep their row and their id.

**2. Removing a field deletes its stored values.** A field in the stored list but absent from the payload has its config deleted, and the FK cascade deletes every `index_fields` row pointing at it, across every document on the site. Only a full reindex brings those values back. The API does this without a confirmation step, so the warning belongs in the client UI, before the request is sent. The `removed` key in the response exists so the client can also report it afterwards.

**3. A weight change updates already-indexed rows.** `IndexWriter` copies the config's weight onto each `index_fields` row at write time, so changing a config weight alone would do nothing to documents already indexed, and the site would see no ranking change until the next reindex. The write updates `index_fields.weight` for the affected configs in the same transaction.

The whole write runs in one transaction on the `search` connection. Nothing is written unless every field validates.

### Validation

| Field | Rules |
| --- | --- |
| `fields` | `present`, `list`, `max:200` |
| `fields.*` | `array:field,weight` |
| `fields.*.field` | `required`, `string`, `max:255`, `regex:/^[A-Za-z0-9_.-]+$/`, `distinct` |
| `fields.*.weight` | `required`, `integer:strict`, `between:0,1000` |

`integer:strict` matches the pipeline API, so `"20"` can't be saved where `20` is meant.

Weight `0` is allowed and means the field is searchable but contributes nothing to the score: it still satisfies the `WHERE` clause in `SearchService`, so a document matching only on a zero-weight field appears, ranked by `d.boost` alone.

An empty list is valid and removes every config for the site, which empties the index of field values. The client is expected to confirm that first.

### Other responses

| Status | When |
| --- | --- |
| 401 | No key, or a key that doesn't resolve to a site |
| 422 | Validation failure, standard Laravel error bag |
| 429 | `throttle:site-api` |

---

## DELETE /api/index/{reference}

Removes one document from the site's index. The gap this fills: an entry deleted or unpublished in the CMS currently stays in the index and keeps appearing in results, with no way to remove it.

**204** — no body.

Idempotent. Deleting a reference that was never indexed also returns 204, so a client's deletion listener doesn't have to know whether the entry had made it into the index. `index_fields` rows go with the document through the FK cascade.

The reference is URL-encoded in the path. `{reference}` is scoped to the calling site, so one site can't delete another's document even if it knows the reference.

| Status | When |
| --- | --- |
| 204 | Deleted, or there was nothing to delete |
| 401 | No key |
| 429 | `throttle:site-api` |

There is no bulk delete. A client removing many documents calls this per reference; if that turns out to be a real pattern, `DELETE /api/index` with a `references` array is the follow-up.

---

## Behaviour clients need to know

- Field configs are per site and flat. The `<type>.<field>` prefix is the client's doing, and the service will happily hold `title` and `articles.title` side by side as two unrelated fields.
- A field with a config but no value on a given document simply has no `index_fields` row for that document. That is normal, not an error.
- `POST /api/index` does not validate field names against the configs. Unknown fields are dropped during the write, quietly. Checking that the two lists agree is the client's job.

## Tests the service should have

- A site reads only its own configs, ordered by field name.
- Reading with no configs returns an empty list, not 404.
- PUT with a field already stored keeps its `index_fields` rows.
- PUT that drops a field deletes that field's `index_fields` rows and no others.
- PUT that changes a weight updates `index_fields.weight` on already-indexed rows.
- PUT reports dropped fields in `removed`.
- PUT with an empty list removes everything for that site and nothing for another site.
- A validation failure writes nothing.
- Field names outside the accepted characters are rejected.
- `"20"` as a weight is rejected.
- Both endpoints return 401 without a key.
- DELETE removes the document and its fields, and returns 204.
- DELETE for an unknown reference returns 204.
- DELETE can't reach another site's document with the same reference.

## Open questions

1. **Write access.** Same as the pipeline API: keys are issued with `*` abilities, so any key holder can replace the field list, and replacing it destructively. Worth a token ability before this is in front of clients.
2. **Reindex trigger.** Removing a field or changing the prefix convention needs a reindex, and the service has no way to ask for one. The addon has `statamic:search-service:index` planned, so for now the answer is "the client reindexes itself", but a service-side `POST /api/reindex` that asks the client to push again may be wanted once there is more than one client type.
3. **Orphaned configs.** Nothing prunes a config whose fields stopped being sent. It costs a row and a filter entry, so it is harmless, but a site that has churned through several naming conventions accumulates them with nothing surfacing it.
