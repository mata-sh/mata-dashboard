#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Send a sample alarm through one delivery channel, bypassing the alarm queue.
 *
 * Usage: php scripts/alarm_channel_test.php <channel>
 * Exit codes: 0 sent, 1 delivery failed, 2 usage or unknown channel.
 */

use Mata\Bootstrap\AlarmChannelRuntime;
use Mata\Bootstrap\CronRuntime;
use Mata\Services\Alarm\Delivery\Dto\AlarmDeliveryView;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmLevel;
use Mata\Services\Event\EventManager;
use Mata\Services\Event\EventRecorderService;

require_once __DIR__ . '/../vendor/autoload.php';

$runtime = CronRuntime::boot(__FILE__);
$appConfig = $runtime->appConfig;

$eventManager = new EventManager($appConfig, eventRecorder: new EventRecorderService($runtime->db), logger: $runtime->log);
$alarmChannels = AlarmChannelRuntime::fromRuntime($runtime, $eventManager);
$senders = $alarmChannels->senders();

$channel = strtolower(trim((string) ($argv[1] ?? '')));
if (!isset($senders[$channel])) {
    fwrite(STDERR, "Usage: php scripts/alarm_channel_test.php <channel>\n");
    fwrite(STDERR, 'Configured channels: ' . implode(', ', array_keys($senders)) . "\n");
    fwrite(STDERR, "Channels missing here are disabled or invalid in config/channels.json;\n");
    fwrite(STDERR, "problems are recorded as channels_config_invalid_entry events (logs/app.log without a database).\n");

    exit(2);
}

$now = new DateTimeImmutable('now');
$view = new AlarmDeliveryView(
    alarm: new Alarm(
        type: 'alarm_channel_test',
        message: "Test alarm from {$appConfig->general->appName}. If you can read this, the {$channel} channel works.",
        level: AlarmLevel::Info,
        server: gethostname() ?: null,
        channels: [$channel],
    ),
    alarmId: 0,
    alarmCreatedAt: $now,
    eventId: 0,
    eventOccurredAt: $now,
);

try {
    $senders[$channel]->send($view);
} catch (Throwable $exception) {
    fwrite(STDERR, "❌ {$channel}: {$exception->getMessage()}\n");

    exit(1);
}

echo "✅ Sent a test alarm through {$channel}.\n";
