# omnischolar/orcid

## Installation

```sh
composer require omnischolar/orcid
```

```php
use Omnischolar\Orcid\OrcidSourceFactory;

$orcid = (new OrcidSourceFactory($httpClient))->create();
```

## Calls

| Method | Reads | ORCID public API v3.0 |
|---|---|---|
| `author()` | ORCID | `{orcid}/record` |
| `works()` | ORCID | `{orcid}/works` (all at once: one summary per group, the preferred version), then `{orcid}/works/{put-codes}` for the details of one page (100 at most) |
| `work()` | `"<orcid>/work/<put-code>"` | `{orcid}/work/{put-code}` |
| `search()` | - | not supported: ORCID searches people, not works |

`Query` on `works()`: `from`/`to` and `types` applied here, `sort` `newest` (default) or
`oldest`, `limit`, `cursor` (an offset). `domains` is refused.

## What an author holds

The credit name, or the given and family names; the other names; the ORCID; the biography; the
keywords; the researcher's URLs and those of their other identifiers (Scopus, ResearcherID...);
the country of their address; and every affiliation section as `Affiliation` lines - kind
(`employment`, `education`, `qualification`, `invited-position`, `distinction`, `membership`,
`service`), organisation, role, department, start and end dates, city, country, ROR when ORCID
has it, URL.

## What a work holds

Title and subtitle, type, contributors (credit names, ORCIDs, roles), date, journal title with
its ISSN, short description as abstract, language, URL; identifiers: DOI, ISBN, arXiv, PMID,
PMCID, HAL, Open Library (those marked "self"; "part-of" ones, a book's ISBN for a chapter, are
left out).

## What ORCID does not give

Only what the researcher made public: Keitaro Nakatani's record (0009-0005-1387-7295) is public
but empty. No citation counts, no metrics, no search of works. Many works have no contributors
listed.

## Tests

Recorded on 2026-10-04: Josiah Carberry (0000-0002-1825-0097, ORCID's demonstration record):
record, works, their details in two pages, one work; Keitaro Nakatani's empty record.
