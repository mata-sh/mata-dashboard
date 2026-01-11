<?php

declare(strict_types=1);

namespace Mata\Services\Alert;

use DateInterval;
use DateTimeImmutable;
use Mata\Config\Dto\AlertLimitsConfig;
use Mata\Models\AlertQueueRepository;
use Mata\Services\Alert\Dto\Alert;
use Mata\Services\Alert\Dto\AlertLevel;
use Mata\Services\Alert\Dto\QueuedAlert;
use Mata\Utils\AlertLimitHelper;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * Processes queued alerts and dispatches them to their respective channels.
 */
final class AlertDispatcher
{
    private const RETRY_BASE_DELAY_SECONDS = 60;
    private const RETRY_MAX_DELAY_SECONDS = 3600;

    private AlertQueueRepository $queueRepository;
    private AlertLimitsConfig $limitsConfig;
    private AlertEmailSender $emailSender;
    private int $maxAttempts;
    private LoggerInterface $logger;

    public function __construct(
        AlertQueueRepository $queueRepository,
        AlertLimitsConfig $limitsConfig,
        AlertEmailSender $emailSender,
        ?LoggerInterface $logger = null,
        int $maxAttempts = 5,
    ) {
        $this->queueRepository = $queueRepository;
        $this->limitsConfig = $limitsConfig;
        $this->emailSender = $emailSender;
        $this->maxAttempts = max(1, $maxAttempts);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Dispatch pending alerts respecting per-channel limit windows.
     */
    public function dispatch(DateTimeImmutable $now, int $batchSize = 50): void
    {
        $alerts = $this->queueRepository->claimPending($now, $batchSize);
        if ($alerts === []) {
            return;
        }

        $pairs = $this->collectServerChannelPairs($alerts);
        $counts = $this->queueRepository->getRecentDispatchCounts($pairs, $now);
        $rateLimiter = new AlertRateLimiter($this->limitsConfig);
        $rateLimiter->preload($counts);

        foreach ($alerts as $queuedAlert) {
            $this->processAlert($queuedAlert, $rateLimiter, $now);
        }
    }

    /**
     * @param array<int,QueuedAlert> $alerts
     *
     * @return array<int,array{server:string,channel:string}>
     */
    private function collectServerChannelPairs(array $alerts): array
    {
        $pairs = [];
        $seen = [];

        foreach ($alerts as $alert) {
            $server = $alert->getServerKey();
            $channel = strtolower($alert->channel);
            $key = "{$server}|{$channel}";
            if (!isset($seen[$key])) {
                $pairs[] = ['server' => $server, 'channel' => $channel];
                $seen[$key] = true;
            }
        }

        return $pairs;
    }

    private function processAlert(QueuedAlert $queuedAlert, AlertRateLimiter $rateLimiter, DateTimeImmutable $now): void
    {
        $serverKey = $queuedAlert->getServerKey();
        $channel = strtolower($queuedAlert->channel);

        $limit = $this->limitsConfig->resolve($serverKey, $channel);
        if (!$rateLimiter->allow($serverKey, $channel)) {
            $this->logger->info('ratelimiter rejected alert', [
                'server' => $serverKey,
                'channel' => $channel,
                'limit' => $limit,
            ]);
            $spacing = AlertLimitHelper::calculateSpacingSeconds($limit);
            $nextRetry = $spacing > 0 ? $now->add(new DateInterval('PT' . $spacing . 'S')) : null;
            $this->queueRepository->updateStatus($queuedAlert->id, 'pending', $queuedAlert->attempts, $nextRetry);

            return;
        }

        $attempts = $queuedAlert->attempts + 1;

        try {
            $this->sendAlert($queuedAlert, $channel);
            $this->queueRepository->updateStatus($queuedAlert->id, 'sent', $attempts);
            $rateLimiter->recordSend($serverKey, $channel);
        } catch (Throwable $exception) {
            $this->queueRepository->updateError($queuedAlert->id, $exception->getMessage());

            if ($attempts >= $this->maxAttempts) {
                $this->queueRepository->updateStatus($queuedAlert->id, 'failed', $attempts);

                return;
            }

            $delaySeconds = $this->computeRetryDelaySeconds($attempts);
            $nextRetry = $now->add(new DateInterval('PT' . $delaySeconds . 'S'));
            $this->queueRepository->updateStatus($queuedAlert->id, 'retry', $attempts, $nextRetry);
        }
    }

    private function sendAlert(QueuedAlert $queuedAlert, string $channel): void
    {
        $alertLevel = AlertLevel::fromString($queuedAlert->level);

        $alert = new Alert(
            type: $queuedAlert->type,
            message: $queuedAlert->message,
            context: $queuedAlert->context,
            level: $alertLevel,
            server: $queuedAlert->serverName,
            app: $queuedAlert->appName,
            channels: [$channel]
        );
        match ($channel) {
            'email' => $this->emailSender->send($alert),
            default => throw new RuntimeException("Unsupported alert channel: {$channel}"),
        };
    }

    private function computeRetryDelaySeconds(int $attempts): int
    {
        return min(
            self::RETRY_BASE_DELAY_SECONDS * (2 ** max(0, $attempts - 1)),
            self::RETRY_MAX_DELAY_SECONDS
        );
    }
}
