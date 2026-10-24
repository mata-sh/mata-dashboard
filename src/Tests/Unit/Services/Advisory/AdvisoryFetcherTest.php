<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Advisory;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Mata\Config\Monitoring\Section\Advisory\AdvisorySource;
use Mata\Config\Monitoring\Section\Advisory\AdvisorySourceType;
use Mata\Config\Monitoring\Section\Advisory\OsvPackage;
use Mata\Services\Advisory\AdvisoryRelevanceFilter;
use Mata\Services\Advisory\Dto\AdvisoryItem;
use Mata\Services\Advisory\Fetch\AdvisoryFetchException;
use Mata\Services\Advisory\Fetch\CsafAdvisoryFetcher;
use Mata\Services\Advisory\Fetch\FeedAdvisoryFetcher;
use Mata\Services\Advisory\Fetch\GithubAdvisoryFetcher;
use Mata\Services\Advisory\Fetch\OsvAdvisoryFetcher;
use Mata\Services\Audit\Severity;
use Mata\Tests\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

/**
 * Feed shapes the initial sources really publish, and the filter rules operators rely on.
 *
 * @covers \Mata\Services\Advisory\AdvisoryRelevanceFilter
 * @covers \Mata\Services\Advisory\Fetch\CsafAdvisoryFetcher
 * @covers \Mata\Services\Advisory\Fetch\FeedAdvisoryFetcher
 * @covers \Mata\Services\Advisory\Fetch\GithubAdvisoryFetcher
 * @covers \Mata\Services\Advisory\Fetch\OsvAdvisoryFetcher
 *
 * @internal
 */
final class AdvisoryFetcherTest extends TestCase
{
    public function testCertBundRssCarriesTheGermanSeverityAndTheProduct(): void
    {
        $items = $this->feed()->parse(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><title>WID</title>
              <item>
                <title>[NEU] [kritisch] PHP: Schwachstelle ermöglicht Codeausführung (CVE-2026-1234)</title>
                <link>https://wid.cert-bund.de/portal/wid/securityadvisory?name=WID-SEC-2026-0001</link>
                <description>Ein entfernter Angreifer kann &lt;b&gt;beliebigen&lt;/b&gt; Code ausführen.</description>
                <category>kritisch</category>
                <pubDate>Fri, 23 Oct 2026 11:25:52 GMT</pubDate>
              </item>
              <item>
                <title>[UPDATE] [mittel] Red Hat Enterprise Linux: Schwachstelle</title>
                <link>https://wid.cert-bund.de/portal/wid/securityadvisory?name=WID-SEC-2026-0002</link>
                <category>mittel</category>
              </item>
            </channel></rss>
            XML);

        $this->assertCount(2, $items);
        $this->assertSame('https://wid.cert-bund.de/portal/wid/securityadvisory?name=WID-SEC-2026-0001', $items[0]->externalId);
        $this->assertSame(Severity::Critical, $items[0]->severity);
        $this->assertSame(['PHP'], $items[0]->products);
        $this->assertSame(['CVE-2026-1234'], $items[0]->cveIds);
        $this->assertSame('Ein entfernter Angreifer kann beliebigen Code ausführen.', $items[0]->summary);
        $this->assertSame('2026-10-23T11:25:52+00:00', $items[0]->publishedAt?->format(DATE_ATOM));
        $this->assertSame(Severity::Medium, $items[1]->severity);
        $this->assertNull($items[1]->publishedAt);
    }

    public function testAtomEntriesUseTheirIdAndAlternateLink(): void
    {
        $items = $this->feed()->parse(<<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom"><title>Vendor</title>
              <entry>
                <id>urn:vendor:advisory:42</id>
                <title>Moderate: OpenSSL update</title>
                <link rel="self" href="https://vendor.example/api/42"/>
                <link rel="alternate" href="https://vendor.example/advisories/42"/>
                <updated>2026-10-20T08:00:00Z</updated>
                <summary>Fixes CVE-2026-0042.</summary>
              </entry>
            </feed>
            XML);

        $this->assertSame('urn:vendor:advisory:42', $items[0]->externalId);
        $this->assertSame('https://vendor.example/advisories/42', $items[0]->link);
        $this->assertSame(Severity::Medium, $items[0]->severity);
        $this->assertSame(['CVE-2026-0042'], $items[0]->cveIds);
    }

    public function testAnHtmlErrorPageIsAFailureNotAnEmptyFeed(): void
    {
        $this->expectException(AdvisoryFetchException::class);

        $this->feed()->parse('<html><body>Service unavailable</body></html>');
    }

    public function testGithubAdvisoriesKeepVersionRangesAndSkipWithdrawnOnes(): void
    {
        $items = (new GithubAdvisoryFetcher(new Client(), 'test'))->parse((string) json_encode([
            [
                'ghsa_id' => 'GHSA-aaaa-bbbb-cccc', 'cve_id' => 'CVE-2026-1', 'summary' => 'Heap overflow in cleanup_xml_node',
                'description' => "### Summary\r\n\r\n`cleanup_xml_node()` **overflows**.", 'severity' => 'moderate',
                'html_url' => 'https://github.com/php/php-src/security/advisories/GHSA-aaaa-bbbb-cccc', 'published_at' => '2026-10-21T13:34:25Z',
                'withdrawn_at' => null, 'cvss_severities' => ['cvss_v3' => ['score' => 5.3], 'cvss_v4' => ['score' => null]],
                'vulnerabilities' => [['package' => ['name' => 'ext-soap'], 'vulnerable_version_range' => '<8.4.26', 'patched_versions' => '8.4.26']],
            ],
            ['ghsa_id' => 'GHSA-dddd-eeee-ffff', 'summary' => 'Withdrawn', 'withdrawn_at' => '2026-10-22T00:00:00Z'],
        ]));

        $this->assertCount(1, $items);
        $this->assertSame(Severity::Medium, $items[0]->severity);
        $this->assertSame(5.3, $items[0]->cvssScore);
        $this->assertSame(['ext-soap <8.4.26 (fixed 8.4.26)'], $items[0]->products);
        $this->assertSame('cleanup_xml_node() overflows.', $items[0]->summary);
    }

    public function testGithubRateLimitIsReportedWithItsResetTime(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '1792835549'], '{"message":"API rate limit exceeded"}'),
        ]))]);
        $source = new AdvisorySource(
            'php-src',
            'PHP',
            '',
            AdvisorySourceType::Github,
            'https://api.github.com/repos/php/php-src/security-advisories',
            true,
            60,
            [],
            Severity::Low,
            true
        );

        $this->expectException(AdvisoryFetchException::class);
        $this->expectExceptionMessage('rate limit exceeded until 2026-10-24');

        (new GithubAdvisoryFetcher($client, 'test'))->fetch($source, null);
    }

    public function testOsvKeepsAVulnerabilityTwoDatabasesListOnceWithTheWatchedPackagesRanges(): void
    {
        $affected = [
            ['package' => ['ecosystem' => 'PyPI', 'name' => 'django'], 'ranges' => [['type' => 'ECOSYSTEM', 'events' => [
                ['introduced' => '4.2'], ['fixed' => '4.2.1'], ['introduced' => '5.0'], ['fixed' => '5.0.3'],
            ]]]],
            ['package' => ['ecosystem' => 'PyPI', 'name' => 'django-debug-toolbar'], 'ranges' => [['type' => 'ECOSYSTEM', 'events' => [['introduced' => '0'], ['fixed' => '1.0']]]]],
        ];
        $source = new AdvisorySource('django', 'Django', '', AdvisorySourceType::Osv, 'https://osv.dev/list', true, 60, [], Severity::Low, true, package: new OsvPackage('PyPI', 'django'));
        $fetcher = new OsvAdvisoryFetcher($this->client([new Response(200, [], (string) json_encode(['vulns' => [
            ['id' => 'PYSEC-2026-1', 'aliases' => ['CVE-2026-1234', 'GHSA-aaaa-bbbb-cccc'], 'details' => 'SQL injection.', 'affected' => $affected],
            ['id' => 'GHSA-aaaa-bbbb-cccc', 'aliases' => ['CVE-2026-1234', 'PYSEC-2026-1'], 'summary' => 'SQL injection in QuerySet.filter',
                'details' => 'Versions <4.2.1 are **affected**.', 'database_specific' => ['severity' => 'HIGH'], 'published' => '2026-10-20T10:00:00Z', 'affected' => $affected],
            ['id' => 'GHSA-dddd-eeee-ffff', 'summary' => 'Withdrawn', 'withdrawn' => '2026-10-21T00:00:00Z', 'affected' => $affected],
        ]]))]), 'test');

        $items = $fetcher->fetch($source, null)->items;

        $this->assertCount(1, $items);
        $this->assertSame('GHSA-aaaa-bbbb-cccc', $items[0]->externalId, 'The rated record wins over its unrated PYSEC alias.');
        $this->assertSame(Severity::High, $items[0]->severity);
        $this->assertSame(['CVE-2026-1234'], $items[0]->cveIds);
        $this->assertSame(['django >=4.2 <4.2.1 (fixed 4.2.1)', 'django >=5.0 <5.0.3 (fixed 5.0.3)'], $items[0]->products);
        $this->assertSame('Versions <4.2.1 are affected.', $items[0]->summary);
        $this->assertSame('https://osv.dev/vulnerability/GHSA-aaaa-bbbb-cccc', $items[0]->link);
    }

    public function testOsvKeepsTheIdsOfAWithdrawnDuplicateSoTheAdvisoryStoredUnderItIsRecognized(): void
    {
        $source = new AdvisorySource('django', 'Django', '', AdvisorySourceType::Osv, 'https://osv.dev/list', true, 60, [], Severity::Low, true, package: new OsvPackage('PyPI', 'django'));
        $fetcher = new OsvAdvisoryFetcher($this->client([new Response(200, [], (string) json_encode(['vulns' => [
            ['id' => 'GHSA-aaaa-bbbb-cccc', 'aliases' => ['CVE-2026-1234'], 'database_specific' => ['severity' => 'HIGH']],
            ['id' => 'PYSEC-2026-1', 'aliases' => ['GHSA-aaaa-bbbb-cccc'], 'withdrawn' => '2026-10-21T00:00:00Z'],
        ]]))]), 'test');

        $items = $fetcher->fetch($source, null)->items;

        $this->assertCount(1, $items);
        $this->assertSame('GHSA-aaaa-bbbb-cccc', $items[0]->externalId);
        $this->assertEqualsCanonicalizing(['GHSA-aaaa-bbbb-cccc', 'CVE-2026-1234', 'PYSEC-2026-1'], $items[0]->ids());
    }

    public function testCsafPollsNeverSplitDocumentsChangedInTheSameSecondAndSkipDocumentsThatAreGone(): void
    {
        $index = static fn (array $lines): Response => new Response(200, [], implode("\n", array_map(
            static fn (string $path, string $changed): string => "\"2026/{$path}.json\",\"{$changed}T22:00:00+0000\"",
            array_keys($lines),
            $lines,
        )) . "\n");
        $requests = [];
        $fetcher = new CsafAdvisoryFetcher($this->client([
            $index(['a' => '2026-10-20', 'b' => '2026-10-21']), $this->csaf('B'), $this->csaf('A'),
            $index(['a' => '2026-10-20', 'b' => '2026-10-21', 'c' => '2026-10-22', 'd' => '2026-10-22', 'e' => '2026-10-22', 'f' => '2026-10-23']),
            $this->csaf('C'), new Response(404), $this->csaf('E'),
            $index(['f' => '2026-10-23']), $this->csaf('F'),
        ], $requests), 'test', new NullLogger(), maxDocuments: 2);
        $source = new AdvisorySource('bsi', 'BSI', '', AdvisorySourceType::Csaf, 'https://csaf.example/.well-known/csaf/white/changes.csv', true, 60, [], Severity::Low, true);

        $baseline = $fetcher->fetch($source, null);
        $next = $fetcher->fetch($source, $baseline->cursor);
        $last = $fetcher->fetch($source, $next->cursor);

        $this->assertSame('2026-10-21T22:00:00Z', $baseline->cursor);
        $this->assertSame(['C', 'E'], array_map(static fn (AdvisoryItem $item): string => $item->externalId, $next->items), 'd is gone; e shares c\'s second and stays in the batch.');
        $this->assertSame('2026-10-22T22:00:00Z', $next->cursor);
        $this->assertSame(['F'], array_map(static fn (AdvisoryItem $item): string => $item->externalId, $last->items));
        $this->assertContains('https://csaf.example/.well-known/csaf/white/2026/e.json', $requests);
    }

    public function testCsafDocumentsFromARolieFeedPreferTheProviderRatingAndTheReadableLink(): void
    {
        $feed = ['feed' => ['entry' => [
            ['updated' => '2026-10-22T22:00:00.000+00:00', 'link' => [['rel' => 'self', 'href' => 'https://csaf.example/white/wid-sec-1.json']]],
            ['updated' => '2026-10-23T00:00:00Z', 'link' => [['rel' => 'self', 'href' => 'https://csaf.example/white/ssa-2.json']]],
        ]]];
        $fetcher = new CsafAdvisoryFetcher($this->client([
            new Response(200, [], (string) json_encode($feed)),
            $this->csaf('SSA-2', cvss: 9.8),
            $this->csaf('WID-SEC-W-2026-1', 'hoch', 9.8, [
                ['category' => 'self', 'url' => 'https://csaf.example/white/wid-sec-1.json'],
                ['category' => 'self', 'url' => 'https://csaf.example/portal?name=WID-SEC-2026-1'],
            ]),
        ]), 'test', new NullLogger());
        $source = new AdvisorySource('bsi', 'BSI', '', AdvisorySourceType::Csaf, 'https://csaf.example/white/feed.json', true, 60, [], Severity::Low, true);

        [$siemens, $bsi] = $fetcher->fetch($source, null)->items;

        $this->assertSame(Severity::High, $bsi->severity, 'The aggregate severity "hoch" wins over CVSS 9.8.');
        $this->assertSame(9.8, $bsi->cvssScore);
        $this->assertSame('https://csaf.example/portal?name=WID-SEC-2026-1', $bsi->link);
        $this->assertSame(['Open Source PHP <8.4.26'], $bsi->products, 'Only affected products, not the fixed version.');
        $this->assertSame(['CVE-2026-1234'], $bsi->cveIds);
        $this->assertSame('Ein Angreifer kann Code ausführen.', $bsi->summary);
        $this->assertSame(Severity::Critical, $siemens->severity);
        $this->assertSame('https://csaf.example/white/ssa-2.json', $siemens->link);
    }

    public function testKeywordsMatchWholeWordsAndUnratedAdvisoriesPassTheSeverityFilter(): void
    {
        $source = new AdvisorySource('s', 'S', '', AdvisorySourceType::Feed, 'https://example.org', true, 60, ['PHP', 'jQuery'], Severity::High, true);
        $filter = new AdvisoryRelevanceFilter();

        $this->assertFalse($filter->evaluate($source, $this->item('PHPMailer: injection', Severity::Critical))->relevant);
        $this->assertSame(['PHP'], $filter->evaluate($source, $this->item('PHP 8.4: overflow', Severity::Critical))->matchedKeywords);
        $this->assertSame(['jQuery'], $filter->evaluate($source, $this->item('Fix in jquery ui', Severity::High))->matchedKeywords);
        $this->assertFalse($filter->evaluate($source, $this->item('PHP: minor', Severity::Medium))->relevant);
        $this->assertTrue($filter->evaluate($source, $this->item('[oss-security] PHP fix', Severity::Unknown))->relevant);
        $this->assertSame(['PHP'], $filter->evaluate($source, $this->item("PHP: Gr\xFC\xDFe overflow", Severity::Critical))->matchedKeywords, 'Invalid UTF-8 must not hide a match.');
    }

    /**
     * @param list<Response> $responses
     * @param list<string>   $requests  receives the requested URLs
     */
    private function client(array $responses, array &$requests = []): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(static function (callable $handler) use (&$requests): callable {
            return static function (RequestInterface $request, array $options) use ($handler, &$requests) {
                $requests[] = (string) $request->getUri();

                return $handler($request, $options);
            };
        });

        return new Client(['handler' => $stack]);
    }

    /** @param list<array<string,string>> $references */
    private function csaf(string $id, ?string $aggregateSeverity = null, ?float $cvss = null, array $references = []): Response
    {
        return new Response(200, [], (string) json_encode([
            'document' => array_filter([
                'title' => "{$id}: PHP vulnerability",
                'tracking' => ['id' => $id, 'initial_release_date' => '2026-10-22T22:00:00.000+00:00'],
                'aggregate_severity' => $aggregateSeverity !== null ? ['text' => $aggregateSeverity] : null,
                'notes' => [['category' => 'description', 'text' => 'PHP ist eine Skriptsprache.'], ['category' => 'summary', 'text' => 'Ein Angreifer kann Code ausführen.']],
                'references' => $references,
            ]),
            'product_tree' => ['branches' => [['category' => 'vendor', 'name' => 'Open Source', 'branches' => [['category' => 'product_name', 'name' => 'PHP', 'branches' => [
                ['category' => 'product_version_range', 'name' => '<8.4.26', 'product' => ['product_id' => 'T1', 'name' => 'Open Source PHP <8.4.26']],
                ['category' => 'product_version', 'name' => '8.4.26', 'product' => ['product_id' => 'T1-fixed', 'name' => 'Open Source PHP 8.4.26']],
            ]]]]]],
            'vulnerabilities' => [[
                'cve' => 'CVE-2026-1234',
                'product_status' => ['known_affected' => ['T1'], 'fixed' => ['T1-fixed']],
                'scores' => $cvss !== null ? [['products' => ['T1'], 'cvss_v3' => ['baseScore' => $cvss]]] : [],
            ]],
        ]));
    }

    private function feed(): FeedAdvisoryFetcher
    {
        return new FeedAdvisoryFetcher(new Client(), 'test');
    }

    private function item(string $title, Severity $severity): AdvisoryItem
    {
        return new AdvisoryItem('id', $title, null, null, $severity, null, [], [], null, 'xml', '');
    }
}
