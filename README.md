# omnischolar/orcid

ORCID's public API for [glitchr/omnischolar](https://github.com/glitchr-studio/omnischolar): who
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

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
