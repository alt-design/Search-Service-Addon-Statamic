# Search Service for Statamic

Connects a Statamic site to the Alt Search Service: it keeps your entries indexed as they
are edited, and gives you a search tag and a JSON endpoint for the front end.

The service holds references and scores rather than your content, so results are hydrated
back into real entries on the site and your API key never reaches the browser.

You will need a site URL and API key from the search service.

## Requirements

- Statamic 6
- PHP 8.4
- A queue worker, or `QUEUE_CONNECTION=sync`, since indexing runs through queued jobs

## Installation

The package is not on Packagist yet, so point Composer at the repository:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/alt-design/Search-Service-Addon-Statamic.git" }
]
```

```bash
composer require alt-design/search-service
```

Nothing else is needed while the repository is public. If it is made private again,
Composer will want a read-only GitHub token in `~/.composer/auth.json`:

```json
{ "github-oauth": { "github.com": "<token>" } }
```

## Configuration

Set the service URL and API key in `.env`:

```
SEARCH_SERVICE_URL=https://search.example.com
SEARCH_SERVICE_KEY=your-site-api-key
```

Both can also be set in the control panel under the addon's settings, which is useful when
a client holds their own key. Anything in `.env` takes priority.

Check the connection with:

```bash
php please search-service:check
```

| Config key | Env | Default | What it does |
| --- | --- | --- | --- |
| `url` | `SEARCH_SERVICE_URL` | none | Root URL of the service, without `/api` |
| `key` | `SEARCH_SERVICE_KEY` | none | The site API key |
| `batch_size` | `SEARCH_SERVICE_BATCH_SIZE` | 50 | Documents per request when syncing |
| `search_rate_limit` | `SEARCH_SERVICE_SEARCH_RATE_LIMIT` | 30 | Front-end searches a minute, per visitor |
| `search_cache_seconds` | `SEARCH_SERVICE_SEARCH_CACHE_SECONDS` | 60 | How long identical queries are cached, 0 to disable |

## Choosing what gets indexed

Control panel, Tools, Search Service. Three screens:

- **Fields** lists your collections and the fields each one indexes, with a weight per field.
  A collection with no fields configured is not indexed at all, so this page is where a
  collection is switched on. Saving queues a re-index of everything it covers.
- **Pipeline** is the ordered list of transformations applied at index time: decoding Bard,
  stripping HTML, removing stop words, adjusting weights, and inferring synonyms.
- **Test Pipeline** runs one entry through the pipeline and shows the result without
  indexing anything.

Field names are sent to the service as `collection.handle`, so two collections sharing a
field handle keep their own weights.

Permissions: `view search-service`, with `edit search-service fields` and
`edit search-service pipeline` beneath it.

## Keeping the index up to date

Saving, unpublishing or deleting an entry queues a job that brings that one document into
line, so ordinary editing needs nothing from you.

For a first index, or to repair drift after the service has been unreachable:

```bash
php please search-service:sync
php please search-service:sync --collection=articles --collection=pages
```

A full run also removes documents for entries the site no longer has. A run limited to
named collections does not.

## Searching

### Antlers

```antlers
{{ search_service:results q="{{ get:q }}" paginate="10" }}
    {{ if match == "corrected" }}
        <p>Nothing matched that spelling, so these are results for "{{ corrected }}".</p>
    {{ /if }}

    {{ results }}
        <h2><a href="{{ url }}">{{ title }}</a></h2>
        <p>{{ content | strip_tags | truncate:200 }}</p>
    {{ /results }}

    {{ if no_results }}<p>Nothing matched.</p>{{ /if }}

    {{ paginate }}
        {{ if prev_page }}<a href="{{ prev_page }}">Previous</a>{{ /if }}
        Page {{ current_page }} of {{ total_pages }}
        {{ if next_page }}<a href="{{ next_page }}">Next</a>{{ /if }}
    {{ /paginate }}
{{ /search_service:results }}
```

Each result is the entry itself, so every field in its blueprint is available, plus
`{{ score }}`. Drop `paginate` for a plain list; the results still live in `{{ results }}`.

`{{ match }}` says how the query was answered:

| Value | Meaning |
| --- | --- |
| `exact` | Every word matched as typed |
| `prefix` | The last word was treated as half typed, so "sof" found "sofa" |
| `corrected` | A word was misspelled, and `{{ corrected }}` holds what was searched for instead |
| `partial` | Only some of the words matched |
| `none` | Nothing matched |

### JSON

`GET /!/search-service/search?q=sofa&limit=10&offset=0`

```json
{
    "query": "sofa",
    "limit": 10,
    "offset": 0,
    "total": 24,
    "match": "exact",
    "corrected": null,
    "results": [
        {
            "reference": "6f9a...",
            "score": 12.5,
            "title": "Choosing a sofa",
            "url": "/articles/choosing-a-sofa",
            "collection": "articles"
        }
    ]
}
```

Unpublished entries and references that no longer resolve are dropped, so a page can come
back shorter than the total suggests. The endpoint answers 503 when the service cannot be
reached, and 429 when a visitor exceeds `search_rate_limit`.

## Notes

- Changing fields or the pipeline only affects documents indexed afterwards, which is why
  saving the Fields screen queues a re-index.
- Saves from the control panel and from the API overwrite each other, with no conflict
  detection.
- If a search page is statically cached, exclude it, or every distinct query writes its own
  cache file that never invalidates.
