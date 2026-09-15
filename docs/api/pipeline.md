# Pipeline API

**Status:** proposed, not built
**Service:** Search-Service-App
**First client:** Statamic addon `alt-design/search-service` (CP → Tools → Search Service → Pipeline)

A site reads and replaces its own indexing pipeline using its site API key. The client edits the whole ordered list and saves it in one request, so the API has two endpoints: read the list, replace the list.

## Conventions

Follow the existing API rather than CLAUDE.md defaults (see `.ai/rules`, `routes/api.php`):

- Routes go inside the existing `auth:sanctum` + `track.usage` group, under `throttle:site-api`.
- Invokable controllers in `App\Http\Controllers\Api`, inline `$request->validate()`, hand-built `response()->json()`, snake_case keys, no version prefix, no API Resources.
- The site is always `$request->user()`. Never accept a site id in the URL or body.
- Writes go through `DB::connection('search')` (the steps table lives on the `search` connection).

```php
Route::middleware('throttle:site-api')->group(function () {
    Route::get('/pipeline', PipelineController::class);
    Route::put('/pipeline', UpdatePipelineController::class);
    // ...existing routes
});
```

---

## GET /api/pipeline

Returns the caller's steps in run order.

**200**

```json
{
    "steps": [
        {
            "transformer": "decode_field",
            "enabled": true,
            "config": { "format": "tiptap", "fields": ["body"] }
        },
        {
            "transformer": "filter_configured_fields",
            "enabled": true,
            "config": {}
        },
        {
            "transformer": "adjust_weights",
            "enabled": false,
            "config": { "weights": { "title": 20, "body": 5 } }
        }
    ]
}
```

Rules:

- Order by `position`, then `id` (positions are not unique today, so the tie-breaker matters).
- Include disabled steps.
- `config` is **always a JSON object**. Empty config must serialise as `{}`, not `[]`: cast with `(object)`.
- Omit option keys whose value is `null`.
- No step `id` or `position` in the response. Array order is the position.
- A site with no steps returns `{"steps": []}`.

---

## PUT /api/pipeline

Replaces the caller's whole pipeline.

**Request**

```json
{
    "steps": [
        { "transformer": "decode_field", "enabled": true, "config": { "format": "tiptap", "fields": ["body"] } },
        { "transformer": "trim", "enabled": false, "config": {} }
    ]
}
```

Behaviour:

1. Validate (below). Nothing is written if any step is invalid.
2. In one transaction on the `search` connection: delete the site's steps, then insert the new ones with `position` = array index + 1.
3. `"steps": []` is valid and clears the pipeline.
4. Respond **200** with the saved pipeline, in exactly the GET shape.

### Validation

| Key | Rules |
|---|---|
| `steps` | present, array (list), max 50 items |
| `steps.*` | only keys `transformer`, `enabled`, `config` |
| `steps.*.transformer` | required, one of `array_keys(config('indexing.transformers'))` |
| `steps.*.enabled` | required, boolean |
| `steps.*.config` | present, object. Only the option keys listed for that transformer. Any other key is a 422 |

Options per transformer:

| Transformer | Option | Rules |
|---|---|---|
| `filter_configured_fields` | none | `config` must be `{}` |
| `trim` | `fields` | optional, nullable, list of distinct non-empty strings ≤ 255 |
| `strip_html` | `fields` | as above |
| `decode_field` | `format` | required, one of `json`, `html`, `tiptap` |
| | `fields` | as above |
| `stop_words` | `words` | required, list of 1+ non-empty strings ≤ 100 |
| | `fields` | as above |
| `synonym_inference` | `trigger_words` | required, list of 1–20 non-empty strings ≤ 100 |
| | `max_synonyms` | optional, integer 1–20. Service default stays 5 |
| | `fields` | as above |
| `adjust_weights` | `weights` | required, object with 1+ entries: key = field name (non-empty string ≤ 255), value = integer ≥ 0 |

Notes for implementing:

- `fields` omitted or `null` means all fields. Store it as absent, not `[]` (an empty list would currently match no fields).
- `provider` on `synonym_inference` is **not** accepted from the API: provider names are internal to the service. Steps saved through the API use the default provider.
- Wildcard rules can't vary by a sibling's value, so build the per-step rules from the input:

  ```php
  $rules = [
      'steps' => ['present', 'list', 'max:50'],
      'steps.*' => ['array:transformer,enabled,config'],
      'steps.*.transformer' => ['required', 'string', Rule::in(array_keys(config('indexing.transformers')))],
      'steps.*.enabled' => ['required', 'boolean'],
  ];

  $fields = fn (string $path) => [
      "$path.fields" => ['sometimes', 'nullable', 'list'],
      "$path.fields.*" => ['required', 'string', 'max:255', 'distinct'],
  ];

  foreach ($request->input('steps', []) as $i => $step) {
      $path = "steps.$i.config";

      $rules += match ($step['transformer'] ?? null) {
          'filter_configured_fields' => [$path => ['present', 'array', 'max:0']],
          'trim', 'strip_html' => [$path => ['present', 'array:fields'], ...$fields($path)],
          'decode_field' => [$path => ['present', 'array:format,fields'], "$path.format" => ['required', 'in:json,html,tiptap'], ...$fields($path)],
          // ...one entry per transformer, as in the table
          default => [],
      };
  }
  ```

- `weights` needs a closure rule to reject a JSON list (`[20, 5]` decodes to integer keys): `array_is_list($value)` → fail.
- Consider using the same rules from the Livewire pipeline page. It currently doesn't validate `decodeFormat` and keeps empty entries from `"a,,b"`.

**422**

Laravel's default body. Error keys use the request path, so clients can point at the step:

```json
{
    "message": "The steps.0.config.format field is required.",
    "errors": {
        "steps.0.config.format": ["The steps.0.config.format field is required."]
    }
}
```

### Other responses

| Status | When |
|---|---|
| 401 | Missing or invalid key: `{"message": "Unauthenticated."}` |
| 429 | Over `site-api` (60/min per site) |

---

## Behaviour clients need to know

- **Changes only apply to documents indexed after saving.** Stored values and weights are not recalculated, so the client re-pushes documents to apply a new pipeline.
- **Last write wins.** Saves from the dashboard and the API overwrite each other, with no conflict detection.
- **Unknown transformers:** clients built against this list refuse to edit a pipeline containing a transformer they don't know, rather than silently dropping it on save. Adding a transformer to the registry needs a client update to be editable there.

## Tests the service should have

1. GET returns only the caller's steps, in position order, with `config` as `{}` when empty.
2. PUT replaces the steps, renumbers positions from 1, and returns the GET shape.
3. PUT with `"steps": []` clears the pipeline.
4. PUT leaves other sites' steps untouched.
5. 422, and nothing written, for: unknown transformer, missing `decode_field.format`, unknown option key, `provider` supplied, negative weight, `weights` sent as a list, empty `words`.
6. 401 without a key.

## Open questions

1. **Write access.** Keys are issued with `*` abilities, so any key holder can change the pipeline, while the dashboard requires Owner/Admin. Is that acceptable, or should pipeline writes need a token ability?
2. **Field configs.** `index_field_configs` has no UI or API, and `IndexWriter` stores nothing for unconfigured fields. Does a `/api/fields` spec come next?
3. **Draft preview.** `/api/evaluate` only runs the saved pipeline. Should it accept an optional `steps` array to preview unsaved changes (under `throttle:evaluate`, since `synonym_inference` calls the LLM)?
