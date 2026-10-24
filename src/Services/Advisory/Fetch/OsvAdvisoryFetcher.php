<?php

declare(strict_types=1);

namespace Mata\Services\Advisory\Fetch;

use DateTimeImmutable;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Mata\Config\Monitoring\Section\Advisory\AdvisorySource;
use Mata\Config\Monitoring\Section\Advisory\OsvPackage;
use Mata\Services\Advisory\AdvisoryText;
use Mata\Services\Advisory\Dto\AdvisoryItem;

/**
 * Vulnerabilities of one package from the OSV.dev query API, which merges GitHub's reviewed
 * advisories, PyPI, Go, RustSec, distribution trackers and more into one schema. Every poll
 * lists all of the package's vulnerabilities; the poller stores the new ones. Severity is the
 * database's rating (GHSA "MODERATE", Ubuntu "medium"): OSV carries CVSS vectors, not scores.
 */
final class OsvAdvisoryFetcher implements AdvisoryFetcherInterface
{
    private const QUERY_URL = 'https://api.osv.dev/v1/query';
    private const MAX_PAGES = 10;

    public function __construct(
        private ClientInterface $http,
        private string $userAgent,
        private int $timeoutSeconds = 20,
    ) {}

    public function fetch(AdvisorySource $source, ?string $cursor): AdvisoryFetchResult
    {
        $package = $source->package ?? throw new AdvisoryFetchException('source has no OSV package');
        $query = ['package' => ['ecosystem' => $package->ecosystem, 'name' => $package->name]];

        $vulns = [];
        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $response = $this->query($query);
            array_push($vulns, ...$response['vulns']);
            if ($response['next_page_token'] === null) {
                break;
            }
            $query['page_token'] = $response['next_page_token'];
        }

        return new AdvisoryFetchResult($this->items($vulns, $package));
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return array{vulns:list<array<string,mixed>>,next_page_token:?string}
     *
     * @throws AdvisoryFetchException
     */
    private function query(array $query): array
    {
        try {
            $response = $this->http->request('POST', self::QUERY_URL, [
                'timeout' => $this->timeoutSeconds,
                'connect_timeout' => min(10, $this->timeoutSeconds),
                'http_errors' => false,
                'headers' => ['User-Agent' => $this->userAgent, 'Accept' => 'application/json'],
                'json' => $query,
            ]);
        } catch (GuzzleException $exception) {
            throw new AdvisoryFetchException('request failed: ' . $exception->getMessage(), previous: $exception);
        }

        try {
            $body = json_decode((string) $response->getBody(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AdvisoryFetchException("HTTP {$response->getStatusCode()} from OSV with invalid JSON", previous: $exception);
        }
        $status = $response->getStatusCode();
        if ($status !== 200) {
            // "invalid ecosystem" for a typo in the source's ecosystem
            $message = is_array($body) && is_string($body['message'] ?? null) ? ': ' . $body['message'] : '';

            throw new AdvisoryFetchException("HTTP {$status} from OSV{$message}");
        }
        $vulns = is_array($body) ? ($body['vulns'] ?? []) : null;
        if (!is_array($vulns) || !array_is_list($vulns)) {
            throw new AdvisoryFetchException('OSV returned no vulnerability list');
        }

        return [
            'vulns' => array_values(array_filter($vulns, static fn (mixed $vuln): bool => is_array($vuln) && is_string($vuln['id'] ?? null))),
            'next_page_token' => is_string($body['next_page_token'] ?? null) && $body['next_page_token'] !== '' ? $body['next_page_token'] : null,
        ];
    }

    /**
     * OSV lists one vulnerability once per database that has it (PyPI: PYSEC-… and GHSA-…,
     * aliasing each other). Rated records come first and one item stands for each alias
     * group, so each vulnerability is notified once. The item carries every ID of its group,
     * withdrawn records' too: a GHSA record that later wins over the PYSEC one stored earlier,
     * or replaces it when PYSEC is withdrawn as a duplicate, is still recognized as stored.
     *
     * @param list<array<string,mixed>> $vulns
     *
     * @return list<AdvisoryItem>
     */
    private function items(array $vulns, OsvPackage $package): array
    {
        /** @var array<string,int> $groupOf ID => group */
        $groupOf = [];

        /** @var array<int,list<string>> $groups group => every ID of one vulnerability */
        $groups = [];
        foreach ($vulns as $index => $vuln) {
            $ids = [$vuln['id'], ...self::aliases($vuln)];
            $joined = [];
            foreach ($ids as $id) {
                if (isset($groupOf[$id])) {
                    $joined[$groupOf[$id]] = true;
                }
            }
            $group = array_key_first($joined) ?? $index;
            foreach (array_keys($joined) as $other) {
                $ids = [...$ids, ...$groups[$other]];
                unset($groups[$other]);
            }
            $groups[$group] = array_values(array_unique($ids));
            foreach ($groups[$group] as $id) {
                $groupOf[$id] = $group;
            }
        }

        $vulns = array_values(array_filter($vulns, static fn (array $vuln): bool => ($vuln['withdrawn'] ?? null) === null));
        usort($vulns, fn (array $a, array $b): int => [$this->rating($a) === null, $a['id']] <=> [$this->rating($b) === null, $b['id']]);

        $items = [];
        foreach ($vulns as $vuln) {
            $group = $groupOf[$vuln['id']];
            if (isset($items[$group])) {
                continue;
            }
            $items[$group] = $this->item($vuln, array_values(array_diff($groups[$group], [$vuln['id']])), $package);
        }

        return array_values($items);
    }

    /**
     * @param array<string,mixed> $vuln
     *
     * @return list<string>
     */
    private static function aliases(array $vuln): array
    {
        return array_values(array_filter(is_array($vuln['aliases'] ?? null) ? $vuln['aliases'] : [], 'is_string'));
    }

    /**
     * @param array<string,mixed> $vuln
     * @param list<string>        $aliases
     */
    private function item(array $vuln, array $aliases, OsvPackage $package): AdvisoryItem
    {
        $id = $vuln['id'];
        $summary = is_string($vuln['summary'] ?? null) ? $vuln['summary'] : null;

        return new AdvisoryItem(
            externalId: $id,
            title: AdvisoryText::title($summary, "{$package->name}: {$id}"),
            link: 'https://osv.dev/vulnerability/' . rawurlencode($id),
            summary: AdvisoryText::plainFromMarkdown(is_string($vuln['details'] ?? null) ? $vuln['details'] : null),
            severity: AdvisoryText::severity($this->rating($vuln)),
            cvssScore: null,
            cveIds: AdvisoryText::cveIds($id, ...$aliases),
            products: $this->products($vuln['affected'] ?? null, $package),
            publishedAt: $this->date($vuln['published'] ?? null),
            rawFormat: 'json',
            raw: json_encode($vuln, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            aliases: $aliases,
        );
    }

    /**
     * The database's own rating: GHSA's database_specific.severity, else a non-CVSS entry of
     * severity[] (Ubuntu), else one of the affected package entries.
     *
     * @param array<string,mixed> $vuln
     */
    private function rating(array $vuln): ?string
    {
        $candidates = [is_array($vuln['database_specific'] ?? null) ? ($vuln['database_specific']['severity'] ?? null) : null];
        foreach (is_array($vuln['severity'] ?? null) ? $vuln['severity'] : [] as $severity) {
            if (is_array($severity) && !str_starts_with((string) ($severity['type'] ?? ''), 'CVSS')) {
                $candidates[] = $severity['score'] ?? null;
            }
        }
        foreach (is_array($vuln['affected'] ?? null) ? $vuln['affected'] : [] as $affected) {
            foreach (['ecosystem_specific', 'database_specific'] as $field) {
                $candidates[] = is_array($affected) && is_array($affected[$field] ?? null) ? ($affected[$field]['severity'] ?? null) : null;
            }
        }
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * "jquery >=1.2.1 <1.9.0 (fixed 1.9.0)" per range of the queried package; the other
     * packages a record lists (jquery-rails, django …) are not what the source watches.
     *
     * @return list<string>
     */
    private function products(mixed $affected, OsvPackage $package): array
    {
        $products = [];
        foreach (is_array($affected) ? $affected : [] as $entry) {
            $entryPackage = is_array($entry) && is_array($entry['package'] ?? null) ? $entry['package'] : [];
            if (($entryPackage['ecosystem'] ?? null) !== $package->ecosystem
                || strcasecmp((string) ($entryPackage['name'] ?? ''), $package->name) !== 0) {
                continue;
            }
            $ranges = array_filter(
                is_array($entry['ranges'] ?? null) ? $entry['ranges'] : [],
                static fn (mixed $range): bool => is_array($range) && in_array($range['type'] ?? null, ['SEMVER', 'ECOSYSTEM'], true),
            );
            if ($ranges === []) {
                $products[] = $package->name;
            }
            foreach ($ranges as $range) {
                array_push($products, ...$this->rangeProducts($package->name, is_array($range['events'] ?? null) ? $range['events'] : []));
            }
        }

        return array_values(array_unique($products));
    }

    /**
     * @param array<mixed> $events introduced/fixed/last_affected in version order
     *
     * @return list<string>
     */
    private function rangeProducts(string $name, array $events): array
    {
        $products = [];
        $introduced = null;
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            if (is_string($event['introduced'] ?? null)) {
                $introduced = $event['introduced'];
            } elseif (is_string($event['fixed'] ?? null) || is_string($event['last_affected'] ?? null)) {
                $fixed = $event['fixed'] ?? null;
                $upper = is_string($fixed) ? "<{$fixed}" : "<={$event['last_affected']}";
                $products[] = $this->rangeProduct($name, $introduced, $upper) . (is_string($fixed) ? " (fixed {$fixed})" : '');
                $introduced = null;
            }
        }
        if ($introduced !== null) {
            $products[] = $this->rangeProduct($name, $introduced, null);
        }

        return $products;
    }

    private function rangeProduct(string $name, ?string $introduced, ?string $upper): string
    {
        $lower = $introduced !== null && $introduced !== '0' ? ">={$introduced}" : null;

        return implode(' ', array_filter([$name, $lower, $upper], static fn (?string $part): bool => $part !== null));
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
