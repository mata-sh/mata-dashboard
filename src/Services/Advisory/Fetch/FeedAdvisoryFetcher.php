<?php

declare(strict_types=1);

namespace Mata\Services\Advisory\Fetch;

use DateTimeImmutable;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Mata\Config\Monitoring\Section\Advisory\AdvisorySource;
use Mata\Services\Advisory\AdvisoryText;
use Mata\Services\Advisory\Dto\AdvisoryItem;
use SimpleXMLElement;

/**
 * RSS 2.0, RSS 1.0 (RDF) and Atom feeds. An item is identified by its guid/id, else its link,
 * else a hash of title and date. Severity comes from the item's categories, then its title.
 */
final class FeedAdvisoryFetcher implements AdvisoryFetcherInterface
{
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const ATOM_NS = 'http://www.w3.org/2005/Atom';
    private const RSS1_NS = 'http://purl.org/rss/1.0/';
    private const DC_NS = 'http://purl.org/dc/elements/1.1/';

    public function __construct(
        private ClientInterface $http,
        private string $userAgent,
        private int $timeoutSeconds = 20,
    ) {}

    public function fetch(AdvisorySource $source, ?string $cursor): AdvisoryFetchResult
    {
        try {
            $response = $this->http->request('GET', $source->url, [
                'timeout' => $this->timeoutSeconds,
                'connect_timeout' => min(10, $this->timeoutSeconds),
                'http_errors' => false,
                'headers' => [
                    'User-Agent' => $this->userAgent,
                    'Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8',
                ],
            ]);
        } catch (GuzzleException $exception) {
            throw new AdvisoryFetchException('request failed: ' . $exception->getMessage(), previous: $exception);
        }

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new AdvisoryFetchException("HTTP {$status} from {$source->url}");
        }
        $body = (string) $response->getBody();
        if (strlen($body) > self::MAX_BYTES) {
            throw new AdvisoryFetchException('feed is larger than 10 MB');
        }

        return new AdvisoryFetchResult($this->parse($body));
    }

    /**
     * @return list<AdvisoryItem>
     *
     * @throws AdvisoryFetchException
     */
    public function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            // LIBXML_NONET: no network access while parsing; entities are not substituted by default.
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            $error = libxml_get_last_error();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($document === false) {
            throw new AdvisoryFetchException('not a valid XML feed' . ($error !== false ? ': ' . trim($error->message) : ''));
        }

        $root = $document->getName();
        $items = match (true) {
            $root === 'rss' => $this->rssItems($document->channel->item ?? []),
            $root === 'feed' => $this->atomEntries($document->children(self::ATOM_NS)->entry ?? []),
            $root === 'RDF' => $this->rssItems($document->children(self::RSS1_NS)->item ?? []),
            default => throw new AdvisoryFetchException("unsupported feed format <{$root}>"),
        };

        return array_values(array_filter($items));
    }

    /**
     * @param iterable<SimpleXMLElement> $items
     *
     * @return list<?AdvisoryItem>
     */
    private function rssItems(iterable $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $title = (string) $item->title;
            $link = trim((string) $item->link) ?: null;
            $guid = trim((string) $item->guid) ?: null;
            $date = (string) ($item->pubDate ?? '') ?: (string) $item->children(self::DC_NS)->date;
            $categories = [];
            foreach ($item->category as $category) {
                $categories[] = (string) $category;
            }

            $result[] = $this->item($guid ?? $link, $title, $link, (string) $item->description, $categories, $date, $item);
        }

        return $result;
    }

    /**
     * @param iterable<SimpleXMLElement> $entries
     *
     * @return list<?AdvisoryItem>
     */
    private function atomEntries(iterable $entries): array
    {
        $result = [];
        foreach ($entries as $entry) {
            // Atom attributes have no namespace; $element['href'] would look in the Atom one.
            $link = null;
            foreach ($entry->link as $candidate) {
                $attributes = $candidate->attributes();
                $rel = (string) ($attributes['rel'] ?? 'alternate');
                if ($rel === 'alternate' || $link === null) {
                    $link = trim((string) ($attributes['href'] ?? '')) ?: $link;
                }
            }
            $categories = [];
            foreach ($entry->category as $category) {
                $categories[] = (string) ($category->attributes()['term'] ?? '');
            }
            $summary = (string) $entry->summary ?: (string) $entry->content;
            $date = (string) $entry->published ?: (string) $entry->updated;

            $result[] = $this->item(trim((string) $entry->id) ?: $link, (string) $entry->title, $link, $summary, $categories, $date, $entry);
        }

        return $result;
    }

    /** @param list<string> $categories */
    private function item(?string $id, string $title, ?string $link, string $description, array $categories, string $date, SimpleXMLElement $raw): ?AdvisoryItem
    {
        $title = AdvisoryText::title($title, '');
        if ($title === '' && $link === null) {
            return null;
        }
        $summary = AdvisoryText::plain($description);
        $id ??= 'sha1:' . sha1($title . '|' . $date);
        $product = AdvisoryText::productFromTitle($title);

        return new AdvisoryItem(
            externalId: mb_substr($id, 0, 255),
            title: $title !== '' ? $title : (string) $link,
            link: $link !== null && preg_match('#^https?://#i', $link) === 1 ? $link : null,
            summary: $summary,
            severity: AdvisoryText::severity(implode(' ', $categories), $title),
            cvssScore: null,
            cveIds: AdvisoryText::cveIds($title, $description),
            products: $product === null ? [] : [$product],
            publishedAt: $this->date($date),
            rawFormat: 'xml',
            raw: (string) $raw->asXML(),
        );
    }

    private function date(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
