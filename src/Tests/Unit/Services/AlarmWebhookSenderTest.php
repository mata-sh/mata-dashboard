<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mata\Config\Monitoring\Section\WebhookChannel;
use Mata\Services\Alarm\Delivery\AlarmTemplateDataFactory;
use Mata\Services\Alarm\Delivery\AlarmWebhookPayloadFactory;
use Mata\Services\Alarm\Delivery\AlarmWebhookSender;
use Mata\Services\Alarm\Delivery\Dto\AlarmDeliveryView;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmLevel;
use Mata\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;
use Twig\Loader\FilesystemLoader;

/**
 * @covers \Mata\Services\Alarm\Delivery\AlarmWebhookSender
 *
 * @internal
 */
final class AlarmWebhookSenderTest extends TestCase
{
    private const TOKEN = '123456:AAH-secret-token';
    private const URL = 'https://api.telegram.org/bot' . self::TOKEN . '/sendMessage';

    /** @var list<array{request: Request, options: array<string,mixed>}> */
    private array $history = [];

    public function testPostsTheRenderedPayloadWithoutFollowingRedirects(): void
    {
        $this->sender([new Response(200, [], '{"ok":true}')])->send($this->view());

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::URL, (string) $request->getUri());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(42, $body['chat_id']);
        $this->assertStringContainsString('Disk full', $body['text']);
        $this->assertFalse($this->history[0]['options']['allow_redirects']);
        $this->assertSame(7, $this->history[0]['options']['timeout']);
    }

    #[DataProvider('failures')]
    public function testFailuresThrowARedactedMessage(ResponseInterface|Throwable $outcome, string $expected): void
    {
        try {
            $this->sender([$outcome])->send($this->view());
            $this->fail('A failed delivery must throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('Webhook channel telegram (api.telegram.org) ', $exception->getMessage());
            $this->assertStringContainsString($expected, $exception->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            $this->assertNull($exception->getPrevious(), 'The original exception carries the secret URL.');
        }
    }

    /** @return iterable<string,array{ResponseInterface|Throwable,string}> */
    public static function failures(): iterable
    {
        yield 'client error with body' => [
            new Response(400, [], '{"ok":false,"error_code":400,"description":"Bad Request: chat not found"}'),
            'returned HTTP 400: {"ok":false,"error_code":400,"description":"Bad Request: chat not found"}',
        ];

        yield 'server error' => [new Response(502, [], 'Bad Gateway'), 'returned HTTP 502: Bad Gateway'];

        yield 'rate limited' => [new Response(429, [], '{"ok":false,"parameters":{"retry_after":5}}'), 'returned HTTP 429'];

        yield 'redirect' => [new Response(302, ['Location' => 'https://elsewhere.example/']), 'returned HTTP 302.'];

        yield 'transport error' => [
            new ConnectException(
                'cURL error 28: Operation timed out after 7001 milliseconds for ' . self::URL,
                new Request('POST', self::URL),
            ),
            'request failed: cURL error 28: Operation timed out after 7001 milliseconds for [redacted]',
        ];
    }

    public function testSecretsEchoedInTheResponseBodyAreRedacted(): void
    {
        try {
            $this->sender([new Response(404, [], 'No route for POST /bot' . self::TOKEN . '/sendMessage')])->send($this->view());
            $this->fail('A failed delivery must throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            $this->assertStringContainsString('No route for POST [redacted]', $exception->getMessage());
        }
    }

    public function testLongResponseBodiesAreTruncated(): void
    {
        try {
            $this->sender([new Response(500, [], str_repeat('e', 5000))])->send($this->view());
            $this->fail('A failed delivery must throw.');
        } catch (RuntimeException $exception) {
            $this->assertLessThan(500, mb_strlen($exception->getMessage()));
            $this->assertStringEndsWith('…', $exception->getMessage());
        }
    }

    /** @param list<ResponseInterface|Throwable> $queue */
    private function sender(array $queue): AlarmWebhookSender
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new AlarmWebhookSender(
            new WebhookChannel('telegram', self::URL, 'telegram', ['chat_id' => 42], 7),
            new AlarmWebhookPayloadFactory(new FilesystemLoader(dirname(__DIR__, 4) . '/templates'), new AlarmTemplateDataFactory('MATA', null)),
            new Client(['handler' => $stack]),
        );
    }

    private function view(): AlarmDeliveryView
    {
        $now = new DateTimeImmutable('2026-10-10 14:03:00 UTC');

        return new AlarmDeliveryView(
            alarm: new Alarm('remaining_diskspace_too_low', 'Disk full', null, AlarmLevel::Critical, 'osira'),
            alarmId: 1,
            alarmCreatedAt: $now,
            eventId: 2,
            eventOccurredAt: $now,
        );
    }
}
