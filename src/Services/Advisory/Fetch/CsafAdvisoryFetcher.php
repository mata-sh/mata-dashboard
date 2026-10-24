<?php

declare(strict_types=1);

namespace Mata\Services\Advisory\Fetch;

use DateTimeImmutable;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Utils;
use JsonException;
use Mata\Config\Monitoring\Section\Advisory\AdvisorySource;
use Mata\Services\Advisory\AdvisoryText;
use Mata\Services\Advisory\Dto\AdvisoryItem;
use Mata\Services\Audit\Severity;
use Psr\Log\LoggerInterface;

/**
 * CSAF 2.0 advisories of one provider. `url` is the provider's changes.csv or one of its ROLIE
 * feeds, as listed in its provider-metadata.json. Both index every document with its last
 * change; the cursor is the newest change processed, so a poll downloads only documents that
 * changed since. The first poll takes the newest ones. A changed document keeps its tracking
 * ID, so the poller does not notify it again.
 */
final class CsafAdvisoryFetcher implements AdvisoryFetcherInterface
{
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const MAX_PRODUCTS = 50;
    private const DOCUMENT_PATH_PATTERN = '#^(?!.*\.\.)[A-Za-z0-9][A-Za-z0-9._/-]*\.json$#';
    private const AFFECTED_STATUSES = ['known_affected', 'first_affected', 'last_affected'];

    public function __construct(
        private ClientInterface $http,
        private string $userAgent,
        private LoggerInterface $logger,
        private int $timeoutSeconds = 20,
        private int $maxDocuments = 100,
    ) {}

    public function fetch(AdvisorySource $source, ?string $cursor): AdvisoryFetchResult
    {
        $since = $cursor !== null ? strtotime($cursor) : false;
        [$urls, $next] = $this->batch($this->index($source->url), $since === false ? null : $since);

        $items = [];
        foreach ($urls as $url) {
            $item = $this->document($url);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return new AdvisoryFetchResult($items, $next === null ? null : gmdate('Y-m-d\TH:i:s\Z', $next));
    }

    /**
     * Without a cursor the newest documents, else those changed after it, oldest first. A
     * batch never ends inside a second: BSI stamps a whole day's ~150 changes alike, and a cut
     * there would move the cursor past documents not yet fetched.
     *
     * @param array<string,int> $index document URL => last change
     *
     * @return array{0:list<string>,1:?int} document URLs and the new cursor
     */
    private function batch(array $index, ?int $cursor): array
    {
        if ($index === []) {
            return [[], $cursor];
        }
        if ($cursor === null) {
            arsort($index);

            return [array_slice(array_keys($index), 0, $this->maxDocuments), max($index)];
        }

        $pending = array_filter($index, static fn (int $changed): bool => $changed > $cursor);
        asort($pending);
        $urls = [];
        foreach ($pending as $url => $changed) {
            if (count($urls) >= $this->maxDocuments && $changed !== $cursor) {
                break;
            }
            $urls[] = $url;
            $cursor = $changed;
        }

        return [$urls, $cursor];
    }

    /**
     * @return array<string,int> document URL => last change
     *
     * @throws AdvisoryFetchException
     */
    private function index(string $url): array
    {
        [$status, $body] = $this->get($url, 'application/json, text/csv;q=0.9');
        if ($status !== 200) {
            throw new AdvisoryFetchException("HTTP {$status} from {$url}");
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw new AdvisoryFetchException('index is larger than 10 MB; use the provider\'s changes.csv if it has one');
        }

        return str_starts_with(ltrim($body), '{') ? $this->rolieIndex($body) : $this->changesIndex($body, $url);
    }

    /**
     * @return array<string,int>
     *
     * @throws AdvisoryFetchException
     */
    private function rolieIndex(string $json): array
    {
        try {
            $feed = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AdvisoryFetchException('ROLIE feed is not valid JSON', previous: $exception);
        }
        $entries = is_array($feed) && is_array($feed['feed'] ?? null) ? ($feed['feed']['entry'] ?? []) : null;
        if (!is_array($entries)) {
            throw new AdvisoryFetchException('not a CSAF ROLIE feed');
        }

        $index = [];
        foreach ($entries as $entry) {
            $changed = is_array($entry) && is_string($entry['updated'] ?? null) ? strtotime($entry['updated']) : false;
            foreach (is_array($entry) && is_array($entry['link'] ?? null) ? $entry['link'] : [] as $link) {
                $href = is_array($link) && ($link['rel'] ?? null) === 'self' ? ($link['href'] ?? null) : null;
                if ($changed !== false && is_string($href) && str_starts_with(strtolower($href), 'https://')) {
                    $index[$href] = max($changed, $index[$href] ?? 0);
                }
            }
        }

        return $index;
    }

    /**
     * Lines of `"2026/wid-sec-w-2026-0001.json","2026-10-22T22:00:00+0000"`, paths relative to
     * the file's directory.
     *
     * @return array<string,int>
     *
     * @throws AdvisoryFetchException
     */
    private function changesIndex(string $csv, string $url): array
    {
        $directory = substr($url, 0, (int) strrpos($url, '/') + 1);
        $index = [];
        foreach (preg_split('/\R/', $csv) ?: [] as $line) {
            [$path, $changed] = str_getcsv($line, ',', '"', '') + [null, null];
            $time = is_string($changed) ? strtotime($changed) : false;
            if (is_string($path) && preg_match(self::DOCUMENT_PATH_PATTERN, $path) === 1 && $time !== false) {
                $index[$directory . $path] = max($time, $index[$directory . $path] ?? 0);
            }
        }
        if ($index === [] && trim($csv) !== '') {
            throw new AdvisoryFetchException('not a CSAF changes.csv or ROLIE feed');
        }

        return $index;
    }

    /**
     * A document the provider cannot serve right now fails the poll, which retries it. One it
     * will never serve (gone, too large, not CSAF) is logged and skipped, so it cannot block
     * every later document.
     *
     * @throws AdvisoryFetchException
     */
    private function document(string $url): ?AdvisoryItem
    {
        [$status, $body] = $this->get($url, 'application/json');
        if ($status === 429 || $status >= 500) {
            throw new AdvisoryFetchException("HTTP {$status} from {$url}");
        }

        try {
            $csaf = $status === 200 && strlen($body) <= self::MAX_BYTES ? json_decode($body, true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $csaf = null;
        }
        $document = is_array($csaf) && is_array($csaf['document'] ?? null) ? $csaf['document'] : [];
        $tracking = is_array($document['tracking'] ?? null) ? $document['tracking'] : [];
        $id = is_string($tracking['id'] ?? null) ? trim($tracking['id']) : '';
        if (!is_array($csaf) || $id === '') {
            $this->logger->warning('advisories.csaf_document_skipped', ['url' => $url, 'reason' => match (true) {
                $status !== 200 => "HTTP {$status}",
                strlen($body) > self::MAX_BYTES => 'larger than 10 MB',
                default => 'not a CSAF document',
            }]);

            return null;
        }

        return $this->item($csaf, $document, $tracking, $id, $url);
    }

    /**
     * @param array<mixed> $csaf
     * @param array<mixed> $document
     * @param array<mixed> $tracking
     */
    private function item(array $csaf, array $document, array $tracking, string $id, string $url): AdvisoryItem
    {
        $vulnerabilities = array_filter(is_array($csaf['vulnerabilities'] ?? null) ? $csaf['vulnerabilities'] : [], 'is_array');
        $cvss = $this->cvssScore($vulnerabilities);
        $severity = AdvisoryText::severity(is_array($document['aggregate_severity'] ?? null) ? ($document['aggregate_severity']['text'] ?? null) : null);
        if ($severity === Severity::Unknown) {
            $severity = AdvisoryText::severityFromCvss($cvss);
        }

        return new AdvisoryItem(
            externalId: $id,
            title: AdvisoryText::title(is_string($document['title'] ?? null) ? $document['title'] : null, $id),
            link: $this->link($document) ?? $url,
            summary: AdvisoryText::plain($this->note($document, 'summary') ?? $this->note($document, 'description')),
            severity: $severity,
            cvssScore: $cvss,
            cveIds: AdvisoryText::cveIds(...array_map(static fn (array $vulnerability): string => is_string($vulnerability['cve'] ?? null) ? $vulnerability['cve'] : '', $vulnerabilities)),
            products: $this->products(is_array($csaf['product_tree'] ?? null) ? $csaf['product_tree'] : [], $vulnerabilities),
            publishedAt: $this->date($tracking['initial_release_date'] ?? null),
            rawFormat: 'json',
            raw: json_encode($csaf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<array<mixed>> $vulnerabilities */
    private function cvssScore(array $vulnerabilities): ?float
    {
        $scores = [];
        foreach ($vulnerabilities as $vulnerability) {
            foreach (is_array($vulnerability['scores'] ?? null) ? $vulnerability['scores'] : [] as $score) {
                $cvss = is_array($score) ? ($score['cvss_v3'] ?? $score['cvss_v2'] ?? null) : null;
                $base = is_array($cvss) ? ($cvss['baseScore'] ?? null) : null;
                if (is_int($base) || is_float($base)) {
                    $scores[] = (float) $base;
                }
            }
        }

        return $scores === [] || max($scores) <= 0 ? null : round(max($scores), 1);
    }

    /**
     * The human-readable self reference (BSI portal, Siemens HTML) rather than the JSON itself.
     *
     * @param array<mixed> $document
     */
    private function link(array $document): ?string
    {
        foreach (is_array($document['references'] ?? null) ? $document['references'] : [] as $reference) {
            $url = is_array($reference) && ($reference['category'] ?? null) === 'self' ? ($reference['url'] ?? null) : null;
            if (is_string($url) && str_starts_with(strtolower($url), 'https://') && !str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?: ''), '.json')) {
                return $url;
            }
        }

        return null;
    }

    /** @param array<mixed> $document */
    private function note(array $document, string $category): ?string
    {
        foreach (is_array($document['notes'] ?? null) ? $document['notes'] : [] as $note) {
            if (is_array($note) && ($note['category'] ?? null) === $category && is_string($note['text'] ?? null)) {
                return $note['text'];
            }
        }

        return null;
    }

    /**
     * Full names of the products the vulnerabilities mark affected, such as "Red Hat
     * Enterprise Linux <cockpit-machines-348.1-1.el9_8".
     *
     * @param array<mixed>        $productTree
     * @param array<array<mixed>> $vulnerabilities
     *
     * @return list<string>
     */
    private function products(array $productTree, array $vulnerabilities): array
    {
        $names = [];
        $this->collectProductNames(is_array($productTree['branches'] ?? null) ? $productTree['branches'] : [], $names);
        $named = is_array($productTree['full_product_names'] ?? null) ? $productTree['full_product_names'] : [];
        foreach (is_array($productTree['relationships'] ?? null) ? $productTree['relationships'] : [] as $relationship) {
            $named[] = is_array($relationship) ? ($relationship['full_product_name'] ?? null) : null;
        }
        foreach ($named as $product) {
            if (is_array($product) && is_string($product['product_id'] ?? null) && is_string($product['name'] ?? null)) {
                $names[$product['product_id']] = $product['name'];
            }
        }

        $products = [];
        foreach ($vulnerabilities as $vulnerability) {
            $status = is_array($vulnerability['product_status'] ?? null) ? $vulnerability['product_status'] : [];
            foreach (self::AFFECTED_STATUSES as $affected) {
                foreach (is_array($status[$affected] ?? null) ? $status[$affected] : [] as $productId) {
                    if (is_string($productId) && isset($names[$productId])) {
                        $products[$names[$productId]] = true;
                    }
                }
            }
        }

        return array_slice(array_map('strval', array_keys($products)), 0, self::MAX_PRODUCTS);
    }

    /**
     * @param array<mixed>         $branches
     * @param array<string,string> $names    product ID => full product name
     */
    private function collectProductNames(array $branches, array &$names): void
    {
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                continue;
            }
            $product = is_array($branch['product'] ?? null) ? $branch['product'] : [];
            if (is_string($product['product_id'] ?? null) && is_string($product['name'] ?? null)) {
                $names[$product['product_id']] = $product['name'];
            }
            $this->collectProductNames(is_array($branch['branches'] ?? null) ? $branch['branches'] : [], $names);
        }
    }

    /**
     * @return array{0:int,1:string} status and body, read up to one byte past the size limit
     *
     * @throws AdvisoryFetchException
     */
    private function get(string $url, string $accept): array
    {
        try {
            $response = $this->http->request('GET', $url, [
                'timeout' => $this->timeoutSeconds,
                'connect_timeout' => min(10, $this->timeoutSeconds),
                'http_errors' => false,
                'headers' => ['User-Agent' => $this->userAgent, 'Accept' => $accept],
            ]);
        } catch (GuzzleException $exception) {
            throw new AdvisoryFetchException('request failed: ' . $exception->getMessage(), previous: $exception);
        }

        return [$response->getStatusCode(), Utils::copyToString($response->getBody(), self::MAX_BYTES + 1)];
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
