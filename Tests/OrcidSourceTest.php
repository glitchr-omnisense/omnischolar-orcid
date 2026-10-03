<?php

namespace Omnischolar\Orcid\Tests;

use Omnischolar\Exception\NotSupportedException;
use Omnischolar\Model\Affiliation;
use Omnischolar\Model\Scheme;
use Omnischolar\Model\WorkType;
use Omnischolar\Orcid\OrcidSourceFactory;
use Omnischolar\Source\Query;
use Omnischolar\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from pub.orcid.org/v3.0 on 2026-10-04: the record and
 * works of Josiah Carberry (0000-0002-1825-0097, ORCID's own demonstration
 * record), the bulk details of its works in two pages of four, one work
 * (work/19980729); and Keitaro Nakatani's record (0009-0005-1387-7295),
 * public but empty.
 */
final class OrcidSourceTest extends TestCase
{
    private const CARBERRY = '0000-0002-1825-0097';

    /** @var list<array{string, array<string, list<string>>}> */
    private array $calls = [];

    private function source(): SourceInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = [$path, $options['normalized_headers'] ?? []];
            $fixture = match ($path) {
                '/v3.0/'.self::CARBERRY.'/record' => 'record-carberry.json',
                '/v3.0/'.self::CARBERRY.'/works' => 'works-carberry.json',
                '/v3.0/'.self::CARBERRY.'/works/9543020,4562455,4562454,4562453' => 'bulk-page1.json',
                '/v3.0/'.self::CARBERRY.'/works/4562456,19980729' => 'bulk-page2.json',
                '/v3.0/'.self::CARBERRY.'/work/19980729' => 'work.json',
                '/v3.0/0009-0005-1387-7295/record' => 'record-nakatani.json',
                '/v3.0/0009-0005-1387-7295/works' => 'works-nakatani.json',
                default => null,
            };

            return $fixture ? new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture)) : new MockResponse('{"response-code":404}', ['http_code' => 404]);
        });

        return (new OrcidSourceFactory($http))->create();
    }

    public function testTheIdentityAndTheCv(): void
    {
        $author = $this->source()->author('https://orcid.org/'.self::CARBERRY);

        self::assertSame('Josiah Carberry', $author->name);
        self::assertSame(['Josiah', 'Carberry'], [$author->given, $author->family]);
        self::assertSame(self::CARBERRY, $author->orcid());
        self::assertSame(['Josiah Stinkney Carberry', 'J. Carberry', 'J. S. Carberry'], $author->alternativeNames);
        self::assertSame(['psychoceramics', 'ionian philology'], $author->keywords);
        self::assertStringStartsWith('Josiah Carberry is a fictitious person', $author->biography);
        self::assertContains('http://library.brown.edu/about/hay/carberry.php', $author->urls);

        $employments = $author->affiliations(Affiliation::EMPLOYMENT);
        self::assertCount(2, $employments);
        self::assertSame('Wesleyan University', $employments[0]->organization);
        self::assertSame('Professor', $employments[0]->role);
        self::assertSame('Psychoceramics', $employments[0]->department);
        self::assertSame('1930-02-29', $employments[0]->start);
        self::assertNull($employments[0]->end);
        self::assertTrue($employments[0]->isCurrent());
        self::assertSame(['Middletown', 'US'], [$employments[0]->city, $employments[0]->country]);
        self::assertSame('Accept: application/json', $this->calls[0][1]['accept'][0]);
    }

    public function testTheWorksPageByPageWithTheirContributors(): void
    {
        $source = $this->source();
        $first = $source->works(self::CARBERRY, new Query(limit: 4));

        self::assertSame(6, $first->total);
        self::assertCount(4, $first);
        self::assertSame('4', $first->next);
        self::assertSame('A Methodology for the Emulation of Architecture', $first->works[0]->title, 'newest first, as the record orders the same year');
        self::assertSame('10.5555/12345680', $first->works[0]->doi());
        self::assertSame(2012, $first->works[0]->year);
        self::assertSame(WorkType::ARTICLE, $first->works[0]->type);

        $second = $source->works(self::CARBERRY, new Query(limit: 4, cursor: $first->next));
        self::assertCount(2, $second);
        self::assertTrue($second->isLast());
        $plasmons = $second->works[1];
        self::assertSame('Bulk and surface plasmons in artificially structured materials', $plasmons->title);
        self::assertSame('IEEE Transactions on Plasma Science', $plasmons->venue->name);
        self::assertSame(['Quinn', 'Carberry'], array_map(static fn ($c) => $c->family, $plasmons->authors));
        self::assertSame(1987, $plasmons->year);
        self::assertSame('/v3.0/'.self::CARBERRY.'/works/4562456,19980729', $this->calls[3][0], 'one call details a page');
    }

    public function testTheYearsAreAppliedHere(): void
    {
        $page = $this->source()->works(self::CARBERRY, new Query(from: 2011, to: 2012));
        self::assertSame(3, $page->total);
    }

    public function testAWorkByItsPutCode(): void
    {
        $work = $this->source()->work(self::CARBERRY.'/work/19980729');
        self::assertSame('Bulk and surface plasmons in artificially structured materials', $work->title);
        self::assertSame('orcid', $work->source);
    }

    public function testARecordWithNothingPublic(): void
    {
        $source = $this->source();
        $author = $source->author('0009-0005-1387-7295');

        self::assertSame('Keitaro NAKATANI', $author->name);
        self::assertSame([], $author->affiliations);
        self::assertSame(0, $source->works('0009-0005-1387-7295')->total);
        self::assertNull($source->author('0000-0001-5000-0007'), 'unknown');
    }

    public function testNoSearchNorIdentifiersOtherThanOrcid(): void
    {
        try {
            $this->source()->search(new Query(text: 'psychoceramics'));
            self::fail();
        } catch (NotSupportedException) {
        }
        $this->expectException(NotSupportedException::class);
        $this->source()->author('A5108007452');
    }

    public function testTheOrcidChecksum(): void
    {
        self::assertNull(\Omnischolar\Model\Identifier::tryOf(Scheme::ORCID, '0000-0002-1825-0098'));
    }
}
