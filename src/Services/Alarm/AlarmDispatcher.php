<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

use DateInterval;
use DateTimeImmutable;
use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Models\AlarmDeliveryRepository;
use Mata\Models\QueuedAlarmDelivery;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmEmailView;
use Mata\Services\Alarm\Dto\AlarmLevel;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * Processes queued alarm deliveries and dispatches them to their channels.
 */
final class AlarmDispatcher
{
    private const RETRY_BASE_DELAY_SECONDS = 60;
    private const RETRY_MAX_DELAY_SECONDS = 3600;

    private AlarmDeliveryRepository $deliveryRepository;
    private AlarmRateLimitsConfig $limitsConfig;
    private AlarmEmailSender $emailSender;
    private int $maxAttempts;
    private LoggerInterface $logger;

    public function __construct(
        AlarmDeliveryRepository $deliveryRepository,
        AlarmRateLimitsConfig $limitsConfig,
        AlarmEmailSender $emailSender,
        ?LoggerInterface $logger = null,
        int $maxAttempts = 5,
    ) {
        $this->deliveryRepository = $deliveryRepository;
        $this->limitsConfig = $limitsConfig;
        $this->emailSender = $emailSender;
        $this->maxAttempts = max(1, $maxAttempts);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Dispatch pending deliveries respecting per-channel limit windows.
     */
    public function dispatch(DateTimeImmutable $now, int $batchSize = 50): void
    {
        $deliveries = $this->deliveryRepository->claimAvailable($now, $batchSize);
        if ($deliveries === []) {
            return;
        }

        $pairs = $this->collectServerChannelPairs($deliveries);
        $counts = $this->deliveryRepository->getRecentDispatchCounts($pairs, $now);
        $rateLimiter = new AlarmDeliveryRateLimiter($this->limitsConfig);
        $rateLimiter->preload($counts);

        foreach ($deliveries as $queuedDelivery) {
            $this->processDelivery($queuedDelivery, $rateLimiter, $now);
        }
    }

    /**
     * @param array<int,QueuedAlarmDelivery> $deliveries
     *
     * @return array<int,array{server:string,channel:string}>
     */
    private function collectServerChannelPairs(array $deliveries): array
    {
        $pairs = [];
        $seen = [];

        foreach ($deliveries as $delivery) {
            $server = $delivery->getServerKey();
            $channel = strtolower($delivery->channel);
            $key = "{$server}|{$channel}";
            if (!isset($seen[$key])) {
                $pairs[] = ['server' => $server, 'channel' => $channel];
                $seen[$key] = true;
            }
        }

        return $pairs;
    }

    private function processDelivery(QueuedAlarmDelivery $queuedDelivery, AlarmDeliveryRateLimiter $rateLimiter, DateTimeImmutable $now): void
    {
        $serverKey = $queuedDelivery->getServerKey();
        $channel = strtolower($queuedDelivery->channel);

        $limit = $this->limitsConfig->resolve($serverKey, $channel);
        if (!$rateLimiter->allow($serverKey, $channel)) {
            $this->logger->info('rate limiter rejected alarm delivery', [
                'server' => $serverKey,
                'channel' => $channel,
                'limit' => $limit,
            ]);
            $spacing = AlarmDispatchSpacingCalculator::calculateSpacingSeconds($limit);
            $nextRetry = $spacing > 0 ? $now->add(new DateInterval('PT' . $spacing . 'S')) : null;
            $this->deliveryRepository->updateStatus($queuedDelivery->id, 'pending', $queuedDelivery->attempts, $nextRetry);

            return;
        }

        $attempts = $queuedDelivery->attempts + 1;

        try {
            $this->sendDelivery($queuedDelivery, $channel);
            $this->deliveryRepository->updateStatus($queuedDelivery->id, 'sent', $attempts);
            $rateLimiter->recordSend($serverKey, $channel);
        } catch (Throwable $exception) {
            $this->deliveryRepository->updateError($queuedDelivery->id, $exception->getMessage());

            if ($attempts >= $this->maxAttempts) {
                $this->deliveryRepository->updateStatus($queuedDelivery->id, 'failed', $attempts);

                return;
            }

            $delaySeconds = $this->computeRetryDelaySeconds($attempts);
            $nextRetry = $now->add(new DateInterval('PT' . $delaySeconds . 'S'));
            $this->deliveryRepository->updateStatus($queuedDelivery->id, 'retry', $attempts, $nextRetry);
        }
    }

    private function sendDelivery(QueuedAlarmDelivery $queuedDelivery, string $channel): void
    {
        $alarmLevel = AlarmLevel::fromString($queuedDelivery->level);

        $alarm = new Alarm(
            type: $queuedDelivery->type,
            message: $queuedDelivery->message,
            context: $queuedDelivery->context,
            level: $alarmLevel,
            server: $queuedDelivery->serverName,
            app: $queuedDelivery->appName,
            channels: [$channel],
            triggerEvidence: $queuedDelivery->triggerEvidence,
        );
        match ($channel) {
            'email' => $this->emailSender->send(new AlarmEmailView(
                alarm: $alarm,
                alarmId: $queuedDelivery->alarmId,
                alarmCreatedAt: $queuedDelivery->alarmCreatedAt,
                eventId: $queuedDelivery->eventId,
                eventOccurredAt: $queuedDelivery->eventOccurredAt,
                previousAlarmCreatedAt: $queuedDelivery->previousAlarmCreatedAt,
                appEnvironment: $queuedDelivery->appEnvironment,
            )),
            default => throw new RuntimeException("Unsupported alarm delivery channel: {$channel}"),
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
