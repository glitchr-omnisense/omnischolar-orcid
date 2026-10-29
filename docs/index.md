# omnischolar/orcid

ORCID's public API for [omnisense/omnischolar](https://github.com/glitchr-omnisense/omnischolar):
who a researcher says they are - names, biography, keywords, pages - their CV - employments,
education, qualifications, distinctions, invited positions, memberships, services - and the works
on their record, each in the version they prefer, with its contributors. The source calls the
public API v3.0, without a key:

| Operation | Request |
|---|---|
| `author($orcid)` | `GET https://pub.orcid.org/v3.0/<orcid>/record` |
| `works($orcid)` | `GET .../<orcid>/works` (all at once), then `.../<orcid>/works/<put-code>,<put-code>...` for the details of one page |
| `work($id)` | `GET .../<orcid>/work/<put-code>` |
| `search()` | not supported: ORCID searches people, not works |

The base address is `OrcidSource::BASE_URI` (`https://pub.orcid.org/v3.0/`); ORCID's sandbox is
`https://pub.sandbox.orcid.org/v3.0/` (`base_uri`).

## Installation

```sh
composer require omnischolar/orcid
```

Requires PHP 8.2, `omnisense/omnischolar` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a
Symfony application with `OmnischolarBundle`, the factory is registered as soon as the package is
installed.

## Credentials

None: the public API serves what researchers made public, without a client. Calls carry the
`User-Agent` of the `user_agent` option (with the `mailto`).

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `base_uri` | string | `https://pub.orcid.org/v3.0/` | no | the sandbox: `https://pub.sandbox.orcid.org/v3.0/` |
| `throttle` | float | `0.0` | no | seconds left between two calls of the source |
| `mailto` | string | `null` | no | added to the `User-Agent` |
| `user_agent` | string | `omnischolar/1.x (+https://github.com/glitchr-omnisense/omnischolar)` | no | |

## Capabilities

`capabilities()`: `author`, `works`, `work`, `affiliations`, `abstracts`.

| Operation | Supported | Reads |
|---|---|---|
| `author()` | yes | an ORCID (bare, `orcid:` or its orcid.org address; checksum checked) |
| `works()` | yes | an ORCID |
| `work()` | yes | `"<orcid>/work/<put-code>"` or `"<orcid>:<put-code>"` (a string) |
| `search()` | no (`does not search`) | |

## Behaviour

**An author.** The credit name, else the given and family names (the ORCID when both are
missing); the other names; the ORCID; the biography; the keywords; the researcher's URLs and those
of their other identifiers (Scopus, ResearcherID...); the country of their first address; and
every affiliation section as `Affiliation` lines - kind (`employment`, `education`,
`qualification`, `invited-position`, `distinction`, `membership`, `service`), organisation, role,
department, start and end dates, city, country, ROR when ORCID has it, URL.

**Works.** ORCID lists a record's works all at once: one summary per group, the preferred
version. The query's years (`inYears()`) and types (`WorkType::fromName()` of ORCID's type) are
applied here; the summaries are sorted by date, newest first (`oldest` reverses; other sorts are
newest); one page (`limit` 1 to 100, the cursor an offset) is then detailed in one bulk call;
`Page::$total` is the number of works kept. `domains` is refused; `text`, `title`, `author` and
`openAccess` are not read.

**A work.** Title and subtitle, type, contributors (credit names, ORCIDs, roles: author, editor,
translator), date and year, the journal title as venue with its ISSN, the short description as
abstract, language, URL; identifiers: DOI, ISBN, arXiv, PMID, PMCID, HAL, Open Library - those not
marked `part-of` (a book's ISBN on a chapter is left out). No citation counts, no metrics.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnischolar\Orcid\OrcidSourceFactory;
use Omnischolar\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$orcid = (new OrcidSourceFactory(HttpClient::create()))->create();

$a = $orcid->author('0000-0002-1825-0097');          // Josiah Carberry, ORCID's demonstration record
echo $a->name, ': ', count($a->affiliations), ' CV lines; ', implode('; ', array_map(fn ($f) => $f->kind.' '.$f->organization, array_slice($a->affiliations, 0, 2))), "\n";

$page = $orcid->works('0000-0002-1825-0097', new Query(limit: 3));
echo $page->total, " works, first 3:\n";
foreach ($page as $work) {
    printf("  %s  %-10s %s %s\n", $work->date ?? $work->year, $work->type->value, mb_substr($work->fullTitle(), 0, 80), $work->doi() ?? '');
}
```

```
Josiah Carberry: 2 CV lines; employment Wesleyan University; employment Brown University
6 works, first 3:
  2012  article    A Methodology for the Emulation of Architecture: Journal of Psychoceramics 10.5555/12345680
  2012  article    The Memory Bus Considered Harmful: Journal of Psychoceramics 10.5555/666655554444
  2011  article    The Impact of Interactive Epistemologies on Cryptography: Journal of Psychoceram 10.5555/987654321
```

(called live on 2026-10-26 at 14:18 UTC, from the core's harness image; the record gives these
works the journal's name as their subtitle)

In Symfony:

```yaml
omnischolar:
    sources:
        orcid: { factory: orcid }
```

## Errors

| Case | Result |
|---|---|
| an unknown ORCID or work (404) | `null` |
| anything but an ORCID; `work()` of an `Identifier` or of a string without a put-code; `search()`; `domains` | `NotSupportedException` |
| another 4xx | `ProviderException` |
| a 429 | `RateLimitedException` |
| a 5xx, a timeout | `UnavailableException` |

## Verified, and not

Tested offline: `Tests/OrcidSourceTest.php` (7 tests), on `MockHttpClient` with answers recorded on
2026-10-04 - Josiah Carberry (0000-0002-1825-0097, ORCID's demonstration record): record, works,
their details in two pages, one work; Keitaro Nakatani's record, public but empty. Called live on
2026-10-04 and on 2026-10-26 (the example above). The sandbox was not called.

Limits of the service: only what the researcher made public; no search of works; many works have
no contributors listed.

## Responsibility

An independent, unofficial client, not affiliated with ORCID, Inc. Whoever runs it complies with
ORCID's terms ([Public APIs Terms of Service](https://info.orcid.org/public-client-terms-of-service/)):
see the README's Responsibility section.
