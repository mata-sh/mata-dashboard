<?php

declare(strict_types=1);

namespace Mata\Services\Advisory\Fetch;

use Closure;
use DateTimeImmutable;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\MessageInterface;
use Mata\Config\Monitoring\Section\Advisory\AdvisorySource;
use Mata\Config\Monitoring\Section\Advisory\ImapMailbox;
use Mata\Services\Advisory\AdvisoryText;
use Mata\Services\Advisory\Dto\AdvisoryItem;
use Mata\Services\Audit\Severity;
use Throwable;

/**
 * Messages of a mailbox that receives a mailing-list subscription, one advisory per message.
 * The mailbox is only read: messages are fetched with BODY.PEEK and never flagged, moved or
 * deleted. The cursor is "<UIDVALIDITY>:<last UID>"; without a usable cursor the last
 * BACKFILL_DAYS are read, and Message-IDs already stored are skipped by the poller.
 */
final class ImapAdvisoryFetcher implements AdvisoryFetcherInterface
{
    private const BACKFILL_DAYS = 14;
    private const MAX_MESSAGES = 200;
    private const MAX_RAW_BYTES = 256 * 1024;
    private const MATCHED_HEADERS = ['from', 'sender', 'to', 'list-id', 'list-post'];

    /** @var Closure(ImapMailbox):MailboxInterface */
    private Closure $connect;

    /** @param null|Closure(ImapMailbox):MailboxInterface $connect for tests; defaults to a TLS connection */
    public function __construct(?Closure $connect = null, private int $timeoutSeconds = 30)
    {
        $this->connect = $connect ?? fn (ImapMailbox $mailbox): MailboxInterface => new Mailbox([
            'host' => $mailbox->host,
            'port' => $mailbox->port,
            'encryption' => $mailbox->encryption->value,
            'username' => $mailbox->username,
            'password' => $mailbox->password,
            'validate_cert' => true,
            'timeout' => $this->timeoutSeconds,
        ]);
    }

    public function fetch(AdvisorySource $source, ?string $cursor): AdvisoryFetchResult
    {
        $config = $source->mailbox ?? throw new AdvisoryFetchException('imap source without mailbox settings');

        try {
            $mailbox = ($this->connect)($config);
            $folder = $mailbox->folders()->find($config->folder) ?? throw new AdvisoryFetchException("folder {$config->folder} does not exist");
            $uidValidity = (string) ($folder->status()['UIDVALIDITY'] ?? '');

            $query = $folder->messages()->withHeaders()->withBody()->leaveUnread();
            [$cursorValidity, $lastUid] = array_pad(explode(':', (string) $cursor, 2), 2, '');
            if ($cursorValidity !== '' && $cursorValidity === $uidValidity && ctype_digit($lastUid)) {
                $query->uid((int) $lastUid + 1, INF);
            } else {
                $query->since(new DateTimeImmutable('-' . self::BACKFILL_DAYS . ' days'));
            }

            $items = [];
            $maxUid = ctype_digit($lastUid) && $cursorValidity === $uidValidity ? (int) $lastUid : 0;
            foreach ($query->limit(self::MAX_MESSAGES)->get() as $message) {
                $maxUid = max($maxUid, $message->uid());
                if ($this->accepts($message, $config->addresses)) {
                    $items[] = $this->item($message);
                }
            }
            $mailbox->disconnect();
        } catch (AdvisoryFetchException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Connection errors can quote the server greeting, never the credentials.
            throw new AdvisoryFetchException("IMAP {$config->host}: " . $exception->getMessage(), previous: $exception);
        }

        return new AdvisoryFetchResult($items, $uidValidity !== '' && $maxUid > 0 ? "{$uidValidity}:{$maxUid}" : null);
    }

    /** @param list<string> $addresses */
    private function accepts(MessageInterface $message, array $addresses): bool
    {
        if ($addresses === []) {
            return true;
        }
        $headers = '';
        foreach (self::MATCHED_HEADERS as $name) {
            $headers .= ' ' . strtolower((string) $message->header($name)?->getRawValue());
        }
        foreach ($addresses as $address) {
            if (str_contains($headers, $address)) {
                return true;
            }
        }

        return false;
    }

    private function item(MessageInterface $message): AdvisoryItem
    {
        $subject = (string) $message->subject();
        $text = $message->text() ?? AdvisoryText::plain($message->html(), 100_000) ?? '';
        $summary = AdvisoryText::plain($text);
        $cvss = preg_match('/\bCVSS(?:v?[234](?:\.\d)?)?(?:\s*base)?(?:\s*score)?\s*[:=]?\s*(\d{1,2}\.\d)\b/i', $text, $match) === 1
            ? min(10.0, (float) $match[1]) : null;
        $severity = AdvisoryText::severity($subject);
        if ($severity === Severity::Unknown) {
            $severity = AdvisoryText::severityFromCvss($cvss);
        }
        $link = preg_match('#https?://[^\s<>"\')\]]+#i', $text, $linkMatch) === 1 ? $linkMatch[0] : null;
        $product = AdvisoryText::productFromTitle($subject);
        $date = $message->date();
        $raw = (string) $message;

        return new AdvisoryItem(
            externalId: mb_substr($message->messageId() ?? ('uid:' . $message->uid() . ':' . sha1($subject)), 0, 255),
            title: AdvisoryText::title($subject, '(no subject)'),
            link: $link,
            summary: $summary,
            severity: $severity,
            cvssScore: $cvss,
            cveIds: AdvisoryText::cveIds($subject, $text),
            products: $product === null ? [] : [$product],
            publishedAt: $date === null ? null : DateTimeImmutable::createFromInterface($date),
            rawFormat: 'eml',
            raw: strlen($raw) > self::MAX_RAW_BYTES ? substr($raw, 0, self::MAX_RAW_BYTES) . "\r\n[truncated]" : $raw,
        );
    }
}
