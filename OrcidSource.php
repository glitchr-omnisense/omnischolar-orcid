<?php

namespace Omnischolar\Orcid;

use Omnischolar\Exception\NotSupportedException;
use Omnischolar\Model\Affiliation;
use Omnischolar\Model\Author;
use Omnischolar\Model\Contributor;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Identifiers;
use Omnischolar\Model\Scheme;
use Omnischolar\Model\Venue;
use Omnischolar\Model\Work;
use Omnischolar\Model\WorkType;
use Omnischolar\Source\Capability;
use Omnischolar\Source\HttpSource;
use Omnischolar\Source\Page;
use Omnischolar\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ORCID's public API (pub.orcid.org/v3.0, no key): who a researcher says
 * they are - names, biography, keywords, pages - their CV - employments,
 * education, qualifications, distinctions, invited positions, memberships,
 * services - and the works on their record, each in the version they
 * prefer, with its contributors.
 *
 * Only what the researcher made public is served. ORCID searches people,
 * not works: search() is not supported.
 */
final class OrcidSource extends HttpSource
{
    public const BASE_URI = 'https://pub.orcid.org/v3.0/';

    /** The most works ORCID details in one call. */
    private const BULK = 100;

    /** The record's sections that are lines of a CV, by the kind they become. */
    private const SECTIONS = [
        'employments' => Affiliation::EMPLOYMENT,
        'educations' => Affiliation::EDUCATION,
        'qualifications' => Affiliation::QUALIFICATION,
        'invited-positions' => Affiliation::INVITED_POSITION,
        'distinctions' => Affiliation::DISTINCTION,
        'memberships' => Affiliation::MEMBERSHIP,
        'services' => Affiliation::SERVICE,
    ];

    public function __construct(
        HttpClientInterface $http,
        string $baseUri = self::BASE_URI,
        array $headers = [],
    ) {
        parent::__construct($http, $baseUri, $headers);
    }

    public function getName(): string
    {
        return 'orcid';
    }

    public function capabilities(): array
    {
        return [Capability::AUTHOR, Capability::WORKS, Capability::WORK, Capability::AFFILIATIONS, Capability::ABSTRACTS];
    }

    public function author(Identifier|string $author): ?Author
    {
        $orcid = $this->orcid($author);
        $record = $this->getJson($orcid.'/record');
        if (null === $record) {
            return null;
        }
        $person = $record['person'] ?? [];
        $name = $person['name'] ?? [];
        $given = $name['given-names']['value'] ?? null;
        $family = $name['family-name']['value'] ?? null;
        $full = $name['credit-name']['value'] ?? trim(($given ?? '').' '.($family ?? ''));

        $affiliations = [];
        foreach (self::SECTIONS as $section => $kind) {
            foreach ($record['activities-summary'][$section]['affiliation-group'] ?? [] as $group) {
                foreach ($group['summaries'] ?? [] as $summary) {
                    $s = reset($summary);
                    $organization = $s['organization'] ?? [];
                    $disambiguated = $organization['disambiguated-organization'] ?? null;
                    $affiliations[] = new Affiliation(
                        organization: (string) ($organization['name'] ?? ''),
                        kind: $kind,
                        role: $s['role-title'] ?? null,
                        department: $s['department-name'] ?? null,
                        start: self::orcidDate($s['start-date'] ?? null),
                        end: self::orcidDate($s['end-date'] ?? null),
                        city: $organization['address']['city'] ?? null,
                        country: $organization['address']['country'] ?? null,
                        ror: 'ROR' === ($disambiguated['disambiguation-source'] ?? null) ? $disambiguated['disambiguated-organization-identifier'] : null,
                        url: $s['url']['value'] ?? null,
                    );
                }
            }
        }

        return new Author(
            name: '' !== $full ? $full : $orcid,
            given: $given,
            family: $family,
            identifiers: Identifiers::of(Identifier::tryOf(Scheme::ORCID, $orcid)),
            alternativeNames: array_values(array_map(static fn (array $n) => (string) $n['content'], $person['other-names']['other-name'] ?? [])),
            affiliations: $affiliations,
            urls: array_values(array_filter([
                ...array_map(static fn (array $u) => $u['url']['value'] ?? null, $person['researcher-urls']['researcher-url'] ?? []),
                ...array_map(static fn (array $e) => $e['external-id-url']['value'] ?? null, $person['external-identifiers']['external-identifier'] ?? []),
            ])),
            biography: self::text($person['biography']['content'] ?? null),
            keywords: array_values(array_map(static fn (array $k) => (string) $k['content'], $person['keywords']['keyword'] ?? [])),
            country: $person['addresses']['address'][0]['country']['value'] ?? null,
            source: 'orcid',
        );
    }

    /**
     * The works on the record - one per group, the version the researcher
     * prefers - newest first, then detailed page by page (contributors,
     * journal, description). ORCID lists them all at once: the query's
     * years and types are applied here, and the cursor is an offset.
     */
    public function works(Identifier|string $author, ?Query $query = null): Page
    {
        $query ??= new Query();
        if ($query->domains) {
            throw NotSupportedException::operation('orcid', 'filter by domain');
        }
        $orcid = $this->orcid($author);
        $summaries = [];
        foreach ($this->getJson($orcid.'/works')['group'] ?? [] as $group) {
            $summary = $group['work-summary'][0] ?? null;
            if (null === $summary) {
                continue;
            }
            $year = isset($summary['publication-date']['year']['value']) ? (int) $summary['publication-date']['year']['value'] : null;
            $type = WorkType::fromName($summary['type'] ?? null);
            if (!$query->inYears($year) || ($query->types && !\in_array($type, $query->types, true))) {
                continue;
            }
            $summaries[] = $summary + ['_date' => self::orcidDate($summary['publication-date'] ?? null) ?? ''];
        }
        usort($summaries, static fn (array $a, array $b) => Query::OLDEST === $query->sort ? $a['_date'] <=> $b['_date'] : $b['_date'] <=> $a['_date']);

        $limit = max(1, min(self::BULK, $query->limit));
        $offset = max(0, (int) $query->cursor);
        $slice = \array_slice($summaries, $offset, $limit);
        $works = [];
        if ($slice) {
            $codes = array_map(static fn (array $s) => (string) $s['put-code'], $slice);
            $details = [];
            foreach ($this->getJson($orcid.'/works/'.implode(',', $codes))['bulk'] ?? [] as $item) {
                if (isset($item['work']['put-code'])) {
                    $details[(string) $item['work']['put-code']] = $item['work'];
                }
            }
            foreach ($slice as $summary) {
                $works[] = $this->toWork($details[(string) $summary['put-code']] ?? $summary);
            }
        }
        $next = $offset + $limit;

        return new Page($works, $next < \count($summaries) ? (string) $next : null, \count($summaries));
    }

    /** One work on a record: "0000-0002-1825-0097/work/9543020" (or "0000-0002-1825-0097:9543020"). */
    public function work(Identifier|string $id): ?Work
    {
        if ($id instanceof Identifier || !preg_match('~(\d{4}-\d{4}-\d{4}-\d{3}[\dX])(?:/work/|:)(\d+)~i', $id, $m)) {
            throw NotSupportedException::identifier('orcid', (string) $id, 'look up (an ORCID work is "<orcid>/work/<put-code>")');
        }
        $data = $this->getJson($m[1].'/work/'.$m[2]);

        return null === $data ? null : $this->toWork($data);
    }

    private function orcid(Identifier|string $author): string
    {
        return (self::identify($author, Scheme::ORCID) ?? throw NotSupportedException::identifier('orcid', (string) $author))->value;
    }

    /** @param array<string, mixed> $w a work, or its summary */
    private function toWork(array $w): Work
    {
        $identifiers = [];
        foreach ($w['external-ids']['external-id'] ?? [] as $external) {
            if ('part-of' === ($external['external-id-relationship'] ?? null)) {
                continue;
            }
            $value = $external['external-id-normalized']['value'] ?? $external['external-id-value'] ?? null;
            $identifiers[] = match (strtolower((string) ($external['external-id-type'] ?? ''))) {
                'doi' => Identifier::tryOf(Scheme::DOI, $value),
                'isbn' => Identifier::tryOf(Scheme::ISBN, $value),
                'arxiv' => Identifier::tryOf(Scheme::ARXIV, $value),
                'pmid' => Identifier::tryOf(Scheme::PMID, $value),
                'pmc' => Identifier::tryOf(Scheme::PMCID, $value),
                'hal' => Identifier::tryOf(Scheme::HAL, $value),
                'ol' => Identifier::tryOf(Scheme::OPENLIBRARY, $value),
                default => null,
            };
        }
        $issn = [];
        foreach ($w['external-ids']['external-id'] ?? [] as $external) {
            if ('issn' === ($external['external-id-type'] ?? null) && null !== ($i = Identifier::tryOf(Scheme::ISSN, $external['external-id-value'] ?? null))) {
                $issn[] = $i->value;
            }
        }

        $contributors = [];
        foreach ($w['contributors']['contributor'] ?? [] as $contributor) {
            $name = (string) ($contributor['credit-name']['value'] ?? '');
            if ('' === $name) {
                continue;
            }
            $role = strtolower((string) ($contributor['contributor-attributes']['contributor-role'] ?? 'author'));
            $contributors[] = Contributor::fromName($name, Identifiers::of(Identifier::tryOf(Scheme::ORCID, $contributor['contributor-orcid']['path'] ?? null)), match (true) {
                str_contains($role, 'editor') => Contributor::EDITOR,
                str_contains($role, 'translator') => Contributor::TRANSLATOR,
                default => Contributor::AUTHOR,
            });
        }

        $journal = $w['journal-title']['value'] ?? null;
        $date = self::orcidDate($w['publication-date'] ?? null);

        return new Work(
            title: self::text($w['title']['title']['value'] ?? null) ?? '',
            type: WorkType::fromName($w['type'] ?? null),
            authors: $contributors,
            year: null !== $date ? (int) substr($date, 0, 4) : null,
            date: $date,
            subtitle: self::text($w['title']['subtitle']['value'] ?? null),
            venue: null !== $journal ? new Venue((string) $journal, Venue::JOURNAL, $issn) : null,
            abstract: self::text($w['short-description'] ?? null),
            language: $w['language-code'] ?? null,
            url: $w['url']['value'] ?? null,
            identifiers: new Identifiers($identifiers),
            source: 'orcid',
        );
    }

    /** @param array{year?: array{value: string}|null, month?: array{value: string}|null, day?: array{value: string}|null}|null $date */
    private static function orcidDate(?array $date): ?string
    {
        return null === $date ? null : self::date($date['year']['value'] ?? null, $date['month']['value'] ?? null, $date['day']['value'] ?? null);
    }
}
