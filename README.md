# omnischolar/orcid

ORCID's public API for [omnisense/omnischolar](https://github.com/glitchr-omnisense/omnischolar): who
a researcher says they are - names, biography, keywords, pages - their CV - employments,
education, qualifications, invited positions, distinctions, memberships, services - and the works
on their record, each in the version they prefer, with its contributors. No key.

```php
$orcid = (new OrcidSourceFactory($http))->create();
$author = $orcid->author('0000-0002-1825-0097');
$author->affiliations(Affiliation::EMPLOYMENT);          // the CV
$orcid->works('0000-0002-1825-0097');
```

```yaml
omnischolar:
    sources:
        orcid: { factory: orcid }    # base_uri: https://pub.sandbox.orcid.org/v3.0/ for the sandbox
```

See [docs/](docs/index.md).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by ORCID, Inc. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence covers
this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the ORCID Public API's
terms ([Public APIs Terms of Service](https://info.orcid.org/public-client-terms-of-service/)), for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or a
citation, a rate limit to respect), and for any use made of what it returns. The authors publish it
free of charge, make no commercial use of it, and accept no liability for how it is used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
