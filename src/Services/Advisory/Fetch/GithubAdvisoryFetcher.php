<?php

declare(strict_types=1);

namespace Mata\Services\Advisory\Fetch;

use DateTimeImmutable;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Mata\Config\Monitoring\Section\Advisory\AdvisorySource;
use Mata\Services\Advisory\AdvisoryText;
use Mata\Services\Advisory\Dto\AdvisoryItem;
use Mata\Services\Audit\Severity;

/**
 * Published security advisories of a public GitHub repository, newest first. One page of
 * 100 is fetched: anything older was already stored by the first poll. Unauthenticated
 * requests share GitHub's limit of 60 per hour per IP; set `token_env` for more.
 */
final class GithubAdvisoryFetcher implements AdvisoryFetcherInterface
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private ClientInterface $http,
        private string $userAgent,
        private int $timeoutSeconds = 20,
    ) {}

    public function fetch(AdvisorySource $source, ?string $cursor): AdvisoryFetchResult
    {
        $headers = [
            'User-Agent' => $this->userAgent,
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        $token = $source->github?->token;
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        try {
            $response = $this->http->request('GET', $source->url, [
                'timeout' => $this->timeoutSeconds,
                'connect_timeout' => min(10, $this->timeoutSeconds),
                'http_errors' => false,
                'headers' => $headers,
                'query' => ['state' => 'published', 'sort' => 'published', 'direction' => 'desc', 'per_page' => self::PAGE_SIZE],
            ]);
        } catch (GuzzleException $exception) {
            throw new AdvisoryFetchException('request failed: ' . $exception->getMessage(), previous: $exception);
        }

        $status = $response->getStatusCode();
        if (in_array($status, [403, 429], true) && $response->getHeaderLine('X-RateLimit-Remaining') === '0') {
            $reset = (int) $response->getHeaderLine('X-RateLimit-Reset');

            throw new AdvisoryFetchException('GitHub API rate limit exceeded' . ($reset > 0 ? ' until ' . gmdate('Y-m-d H:i', $reset) . ' UTC' : '') . '; set token_env');
        }
        if ($status !== 200) {
            throw new AdvisoryFetchException("HTTP {$status} from {$source->url}");
        }

        return new AdvisoryFetchResult($this->parse((string) $response->getBody()));
    }

    /**
     * @return list<AdvisoryItem>
     *
     * @throws AdvisoryFetchException
     */
    public function parse(string $json): array
    {
        try {
            $advisories = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AdvisoryFetchException('GitHub returned invalid JSON', previous: $exception);
        }
        if (!is_array($advisories) || !array_is_list($advisories)) {
            throw new AdvisoryFetchException('GitHub returned no advisory list');
        }

        $items = [];
        foreach ($advisories as $advisory) {
            if (!is_array($advisory) || !is_string($advisory['ghsa_id'] ?? null) || ($advisory['withdrawn_at'] ?? null) !== null) {
                continue;
            }
            $items[] = $this->item($advisory);
        }

        return $items;
    }

    /** @param array<string,mixed> $advisory */
    private function item(array $advisory): AdvisoryItem
    {
        $cvss = $this->cvssScore($advisory);
        $severity = match (strtolower((string) ($advisory['severity'] ?? ''))) {
            'critical' => Severity::Critical,
            'high' => Severity::High,
            'medium', 'moderate' => Severity::Medium,
            'low' => Severity::Low,
            default => AdvisoryText::severityFromCvss($cvss),
        };

        $cveIds = is_string($advisory['cve_id'] ?? null) ? [strtoupper($advisory['cve_id'])] : [];
        foreach (is_array($advisory['identifiers'] ?? null) ? $advisory['identifiers'] : [] as $identifier) {
            if (is_array($identifier) && ($identifier['type'] ?? null) === 'CVE' && is_string($identifier['value'] ?? null)) {
                $cveIds[] = strtoupper($identifier['value']);
            }
        }

        $summary = is_string($advisory['summary'] ?? null) ? $advisory['summary'] : $advisory['ghsa_id'];

        return new AdvisoryItem(
            externalId: $advisory['ghsa_id'],
            title: AdvisoryText::title($summary, $advisory['ghsa_id']),
            link: is_string($advisory['html_url'] ?? null) ? $advisory['html_url'] : null,
            summary: AdvisoryText::plainFromMarkdown(is_string($advisory['description'] ?? null) ? $advisory['description'] : null),
            severity: $severity,
            cvssScore: $cvss,
            cveIds: array_values(array_unique($cveIds)),
            products: $this->products($advisory['vulnerabilities'] ?? null),
            publishedAt: $this->date($advisory['published_at'] ?? null),
            rawFormat: 'json',
            raw: json_encode($advisory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<string,mixed> $advisory */
    private function cvssScore(array $advisory): ?float
    {
        $severities = is_array($advisory['cvss_severities'] ?? null) ? $advisory['cvss_severities'] : [];
        foreach ([$severities['cvss_v4'] ?? null, $severities['cvss_v3'] ?? null, $advisory['cvss'] ?? null] as $cvss) {
            $score = is_array($cvss) ? ($cvss['score'] ?? null) : null;
            if ((is_float($score) || is_int($score)) && $score > 0) {
                return round((float) $score, 1);
            }
        }

        return null;
    }

    /**
     * "ext-standard <8.4.26 (fixed 8.4.26)", one entry per package and range.
     *
     * @return list<string>
     */
    private function products(mixed $vulnerabilities): array
    {
        $products = [];
        foreach (is_array($vulnerabilities) ? $vulnerabilities : [] as $vulnerability) {
            $name = is_array($vulnerability) && is_array($vulnerability['package'] ?? null) ? ($vulnerability['package']['name'] ?? null) : null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            $range = is_string($vulnerability['vulnerable_version_range'] ?? null) ? trim($vulnerability['vulnerable_version_range']) : '';
            $patched = is_string($vulnerability['patched_versions'] ?? null) ? trim($vulnerability['patched_versions']) : '';
            $products[] = trim($name . ($range !== '' ? " {$range}" : '') . ($patched !== '' ? " (fixed {$patched})" : ''));
        }

        return array_values(array_unique($products));
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
