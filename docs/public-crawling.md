# Crawling Public Omeka Sites Without an API Key

This bundle can crawl any publicly-accessible Omeka S installation without
credentials. The Omeka S REST API exposes all public items, item sets, and
site metadata anonymously — no `key_identity` or `key_credential` required.

This enables two workflows:

1. **Demo imports** — point any app at a live museum's public data immediately,
   without asking for an API key.
2. **Museum outreach** — build a directory of Omeka institutions, filter by
   license permissiveness, and invite them to try your platform with their own
   data as a proof-of-concept.

---

## Architecture

```
omeka.org/classic/directory    ─┐
omeka.org/s/directory          ─┤── OmekaDirectoryParser
                                 │        │
                                 │        ▼ array of site records
                                 │
                           OmekaPublicCrawler::detectVersion()
                                 │   probes /api/items?per_page=25
                                 │   detects: version, totalItems, license
                                 │
                                 ▼
                         omeka-directory.jsonl
                         (verified live sites, license scored)

                                 │
                                 ▼ for each site of interest:

                        OmekaCrawlMessage ──► OmekaCrawlMessageHandler
                                 │                │
                                 │                ├── OmekaPublicCrawler::crawlItems()
                                 │                │   (lazy Generator, one API page at a time)
                                 │                │
                                 │                ├── OmekaCrawlItemEvent (per item)
                                 │                │   listeners can transform or skip rows
                                 │                │
                                 │                └── JsonlWriter (with sidecar + token dedup)
                                 │
                                 ▼
                     {slug}_{itemSetId}.jsonl
                     (resumable, sparse, Meilisearch-ready)
```

### HTTP Caching

All HTTP requests from `OmekaPublicCrawler` and `OmekaDirectoryParser` go through
a `CachingHttpClient` backed by a filesystem cache at `%kernel.cache_dir%/omeka_http`.

Default TTL: **24 hours**. This means:

- Run `omeka:directory` once — probes all 700+ listed sites.
- Run it again within 24h — zero network requests, all served from cache.
- The Omeka S API returns no `Cache-Control` headers itself; the bundle's
  `default_ttl` setting fills the gap.

Configure in `config/packages/survos_omeka.yaml`:

```yaml
survos_omeka:
    crawler_cache:
        directory: '%kernel.cache_dir%/omeka_http'   # default
        default_ttl: 86400                            # 24h default
```

Override the cache pool for production (Redis, etc.):

```yaml
services:
    omeka.http_cache:
        class: Symfony\Component\Cache\Adapter\RedisTagAwareAdapter
        arguments:
            - '@redis.connection'
            - 'omeka_http'
            - 86400
```

---

## Commands

### `omeka:directory` — Build a directory of live Omeka sites

Fetches the omeka.org Classic and S directories, probes each URL, and writes
a JSONL file of verified installations with license metadata.

```bash
# Probe all sites (Classic + S) → omeka-directory.jsonl in CWD
bin/console omeka:directory

# Only Omeka S sites (recommended — Classic has a different API format)
bin/console omeka:directory --type=s

# Preview as a table without writing
bin/console omeka:directory --type=s --table

# Only live sites with permissive licenses (CC, CC0, or Public Domain)
bin/console omeka:directory --type=s --live-only --permissive-only \
    --output=/data/permissive-omeka-sites.jsonl

# Faster iteration: skip live probing, just parse the directory HTML
bin/console omeka:directory --no-probe
```

After probing, a summary line is printed:

```
127 live  |  23 unreachable  |  license: 48 permissive, 31 restricted, 48 unknown
```

**Output schema** (one JSON object per line):

```jsonc
{
  "name":          "IAAM Center for Family History",
  "url":           "https://iaamcfh.omeka.net",          // resolved installation root
  "listedUrl":     "https://iaamcfh.omeka.net/s/...",    // original directory link
  "type":          "s",                                   // declared type
  "detectedType":  "s",                                   // confirmed by probe
  "description":   "Supporting family history research…",
  "plugins":       ["Mapping", "CSV Import"],
  "live":          true,
  "omekaVersion":  "4.1.1",
  "totalItems":    1578,
  "license":       "pd",                                  // see License Categories below
  "licenseDetail": "Public Domain"
}
```

**License categories** (sampled from the first 25 items of each site):

| Value | Meaning | Usable? |
|---|---|---|
| `cc0` | CC0 / Public Domain Dedication | Yes — no attribution required |
| `pd` | Public Domain (text assertion or explicit statement) | Yes |
| `cc` | Creative Commons (any variant — BY, BY-SA, BY-NC, etc.) | Yes with attribution |
| `restricted` | All rights reserved / copyright / contact required | No |
| `unknown` | No rights or license metadata found in the 25-item sample | Unclear |

Note: `unknown` does not mean restricted. Many well-intentioned open archives
simply omit `dcterms:rights`/`dcterms:license` metadata. The Ward Department
Papers (79,261 US government documents from 1784–1800) shows `unknown` because
no rights fields are populated — but the content is definitively public domain
by age and US government authorship.

---

### `omeka:crawl` — Fetch items from a specific site into JSONL

```bash
# Discover what's available
bin/console omeka:crawl https://iaamcfh.omeka.net --list-sites
bin/console omeka:crawl https://iaamcfh.omeka.net --list-collections

# Crawl everything → iaamcfh_omeka_net_all.jsonl
bin/console omeka:crawl https://iaamcfh.omeka.net

# One collection, normalized (flat, sparse, Meilisearch-ready)
bin/console omeka:crawl https://iaamcfh.omeka.net --item-set=5 --normalize

# Explicit output path
bin/console omeka:crawl https://iaamcfh.omeka.net \
    --item-set=5 --normalize \
    --output=/data/iaamcfh-centenarians.jsonl

# Count without writing
bin/console omeka:crawl https://iaamcfh.omeka.net --dry-run

# Re-crawl a completed file
bin/console omeka:crawl https://iaamcfh.omeka.net --item-set=5 --force

# Async (configure routing in messenger.yaml first)
bin/console omeka:crawl https://iaamcfh.omeka.net --transport=async
```

**Resumability:** The jsonl-bundle sidecar (`.sidecar.json`) tracks completion.
The token index (`.jsonl.idx.json`) stores each item's `o:id` as a dedup key.
If a crawl is interrupted, re-running resumes from where it stopped — already-
written items are skipped automatically without re-reading the file.

**Async routing** (add to `config/packages/messenger.yaml`):

```yaml
framework:
    messenger:
        routing:
            Survos\OmekaBundle\Message\OmekaCrawlMessage: async
```

---

## Transforming Items with Events

An `OmekaCrawlItemEvent` is dispatched for each item before it is written.
Register a listener to transform or skip items:

```php
use Survos\OmekaBundle\Event\OmekaCrawlItemEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class OmekaItemEnricher
{
    public function __invoke(OmekaCrawlItemEvent $event): void
    {
        // Skip items without a title
        if (empty($event->row['title'])) {
            $event->skip = true;
            return;
        }

        // Stamp with source for multi-tenant indexing
        $event->row['_source']    = 'omeka';
        $event->row['_siteUrl']   = $event->siteUrl;
        $event->row['_itemSetId'] = $event->itemSetId;
    }
}
```

---

## Using the Crawler Programmatically

```php
use Survos\OmekaBundle\Crawler\OmekaPublicCrawler;

final class OmekaImportService
{
    public function __construct(private readonly OmekaPublicCrawler $crawler) {}

    public function probe(string $url): void
    {
        $result = $this->crawler->detectVersion($url);
        // $result = [
        //   'version'       => 's',
        //   'omekaVersion'  => '4.1.1',
        //   'totalItems'    => 1578,
        //   'license'       => 'pd',
        //   'licenseDetail' => 'Public Domain',
        //   'error'         => null,
        // ]
    }

    public function streamItems(string $url, ?int $itemSetId = null): void
    {
        // Lazy generator — one API page in memory at a time
        foreach ($this->crawler->crawlItems($url, $itemSetId) as $raw) {
            $flat = $this->crawler->normalizeItem($raw);
            // $flat is sparse: null/empty stripped, dcterms: flattened to plain keys
            // ['id' => 3, 'title' => 'Mrs. Ercelle Chillis', 'description' => '...']
            $this->index($flat);
        }
    }

    public function classifyRights(string $rightsText): string
    {
        [$category, $label] = $this->crawler->classifyLicenseString($rightsText);
        // $category: 'cc0' | 'pd' | 'cc' | 'restricted' | 'unknown'
        return $category;
    }
}
```

---

## Verified Public Sites for Testing and Demos

These sites have been confirmed live, Omeka S, and have permissive or public
domain content. They are suitable as test data sources for demos and development.

### IAAM Center for Family History
**URL:** `https://iaamcfh.omeka.net`
**License:** Public Domain (explicitly stated on items)
**Items:** ~1,578 | **Collections:** 66

African American genealogy and oral history archive from the International
African American Museum in Charleston, SC. Rich content including centenarian
interviews, funeral programs, photographs, and Bible records.

```bash
bin/console omeka:crawl https://iaamcfh.omeka.net --list-collections
bin/console omeka:crawl https://iaamcfh.omeka.net --item-set=5 --normalize
# item set 5 = "Centenarian Stories" (~20 video interview items)
```

Sample normalized item:
```json
{
  "id": 3,
  "title": "Mrs. Ercelle Chillis",
  "description": "In this interview, the 109-year-old Mrs. Ercelle Chillis…",
  "date": "27 September 2023",
  "creator": "Mike Priester/BluVision Productions",
  "format": "Video",
  "language": "English",
  "url": "https://iaamcfh.omeka.net/api/items/3",
  "rights": "Public Domain | All records should be cited as coming from the Center Family History…"
}
```

---

### A Journal of the Plague Year (COVID-19 Archive)
**URL:** `https://covid-19archive.org`
**License:** Unknown in item metadata (community-contributed, user-submitted content)
**Items:** ~17,828 | **Collections:** 34 | **Sites:** multiple

Crowdsourced pandemic documentation project by Arizona State University and
RRCHNM. Rich variety — personal narratives, photographs, audio, documents —
across global sub-collections (Australia, New Orleans, Peru, College responses).

```bash
bin/console omeka:crawl https://covid-19archive.org --list-collections
bin/console omeka:crawl https://covid-19archive.org --item-set=1 --normalize
# item set 1 = "Covid 19" (main US collection)
```

**Note on license:** No `dcterms:rights` metadata is set on items, but the
project is explicitly open/public — contributions were submitted with the
intent of public archiving.

---

### Papers of the War Department, 1784–1800
**URL:** `https://wardepartmentpapers.org`
**License:** Public Domain (US government documents, 18th–19th century)
**Items:** ~79,261 | **Collections:** 8

Digitised US War Department records including manuscript letters, orders,
and correspondence. All content is pre-1927 US government material —
definitively public domain even without explicit metadata.

```bash
bin/console omeka:crawl https://wardepartmentpapers.org --list-collections
bin/console omeka:crawl https://wardepartmentpapers.org --item-set=6 --normalize
# item set 6 = "Documents" (primary source letters and orders)
```

---

### Hearing the Americas
**URL:** `https://hearingtheamericas.org`
**License:** Unknown in metadata (academic/research project, RRCHNM)
**Items:** ~374 | **Collections:** 11

Early sound recordings (1890s–1920s) from Latin America and the Caribbean —
cylinders, 78rpm discs. Items are indexed by artist, song, and genre. The
recordings themselves are pre-1927 and public domain; metadata is CC by
project.

```bash
bin/console omeka:crawl https://hearingtheamericas.org --list-collections
bin/console omeka:crawl https://hearingtheamericas.org --item-set=56 --normalize
# item set 56 = "Songs"
```

---

### Oregon GLAM (Jordan Schnitzer Museum / UO Libraries)
**URL:** `https://glam.uoregon.edu`
**License:** Mixed — Public Domain Mark 1.0 on many items; some "rights reserved"
**Items:** ~1,365 | **Collections:** 65

University of Oregon art and special collections. Multiple exhibit sub-sites
(Yōkai Senjafuda, The Artful Fabric of Collecting, etc.). Items tagged with
Public Domain Mark 1.0 via `dcterms:rights`.

```bash
bin/console omeka:crawl https://glam.uoregon.edu --list-collections
bin/console omeka:crawl https://glam.uoregon.edu --normalize
```

---

### Saltaire Collection
**URL:** `https://explore.saltairecollection.org`
**License:** Unknown in item metadata (community museum, UK)
**Items:** ~7,780 | **Collections:** 84

UNESCO World Heritage Site community archive — photographs, maps, documents,
and objects from the Victorian model village of Saltaire, West Yorkshire.
Good structural variety (photos, text documents, maps). No explicit rights
metadata on items but publicly listed as an open community archive.

```bash
bin/console omeka:crawl https://explore.saltairecollection.org --list-collections
bin/console omeka:crawl https://explore.saltairecollection.org --normalize
```

---

## Important Caveats

### Omeka Classic vs. Omeka S

The `omeka:crawl` command and `OmekaPublicCrawler` target **Omeka S only**.

Omeka Classic uses a different API format:
- Response header: `omeka-total-results` (no `-s-`)
- Item schema: `element_texts` arrays instead of JSON-LD with `dcterms:*` properties
- No `@context`, `o:id`, `o:title` — uses `id` (integer), `title` (string)

`detectVersion()` correctly identifies Classic sites and returns `'version' => 'classic'`.
The `omeka:directory --type=classic` command will probe and list them, but
`omeka:crawl` will fail gracefully with an error. Classic support is out of scope
for this bundle.

### Sub-site vs. Installation Root

Omeka S is multi-tenant. The directory often lists sub-site URLs like:

```
https://exhibits.lib.utah.edu/s/1918-flu-pandemic-in-utah/page/welcome
```

The API lives at the **installation root**:

```
https://exhibits.lib.utah.edu/api/items
```

`OmekaPublicCrawler::resolveApiUrl()` handles this automatically. Pass any
form of URL — sub-site path, bare domain, trailing `/api` — and it resolves
correctly.

`/api/items` returns items from **all sites** in the installation. To scope to
a specific sub-site's items, use `--item-set` (item sets are typically scoped
per site). Use `--list-collections` to see which item sets are available.

### Copyright of Item Content

The Omeka API serving data publicly does not grant permission to use that data
commercially or redistribute it. Always check:

1. `dcterms:rights` field on items — free-text rights statement
2. `dcterms:license` field on items — often a URI to a CC licence or RightsStatements.org
3. The institution's terms of service

The `license` field in `omeka-directory.jsonl` and the `--permissive-only` flag
help narrow to sites where open use is explicitly indicated, but they are based
on a 25-item sample — not a guarantee across all items in a collection.

For demo purposes, Public Domain and CC-licensed content is safe to import and
display. For production indexing, confirm directly with the institution.
