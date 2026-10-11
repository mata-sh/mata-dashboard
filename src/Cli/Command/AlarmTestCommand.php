<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use DateTimeImmutable;
use Mata\Bootstrap\AlarmChannelRuntime;
use Mata\Bootstrap\CronRuntime;
use Mata\Cli\CliOutput;
use Mata\Services\Alarm\Delivery\Dto\AlarmDeliveryView;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmLevel;
use Mata\Services\Event\EventManager;
use Mata\Services\Event\EventRecorderService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'alarm:test', description: 'Send a sample alarm through one delivery channel, bypassing the queue')]
final class AlarmTestCommand extends Command
{
    public function __construct(private readonly string $entryPoint)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('channel', InputArgument::OPTIONAL, 'email or a channel from channels.json; omit to list them')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON')
            ->setHelp('Exit codes: 0 sent (or channels listed), 1 delivery failed, 2 unknown channel. Uses config/, not MATA_CONFIG_PATH.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runtime = CronRuntime::boot($this->entryPoint);
        $events = new EventManager($runtime->appConfig, eventRecorder: new EventRecorderService($runtime->db), logger: $runtime->log);
        $senders = AlarmChannelRuntime::fromRuntime($runtime, $events)->senders();
        $json = (bool) $input->getOption('json');

        $channel = strtolower(trim((string) $input->getArgument('channel')));
        if (!isset($senders[$channel])) {
            $known = array_keys($senders);
            $exitCode = $channel === '' ? CliOutput::EXIT_OK : CliOutput::EXIT_ERROR;
            if ($json) {
                CliOutput::json($output, ['status' => CliOutput::statusName($exitCode), 'channels' => $known] + ($channel === '' ? [] : ['error' => "Unknown channel '{$channel}'"]));
            } else {
                $output->writeln(($channel === '' ? '' : "<error>Unknown channel '{$channel}'.</error> ") . 'Configured channels: ' . implode(', ', $known));
                $output->writeln('Channels missing here are disabled or invalid in channels.json; see bin/mata config validate channels.json');
            }

            return $exitCode;
        }

        $now = new DateTimeImmutable('now');
        $view = new AlarmDeliveryView(
            alarm: new Alarm(
                type: 'alarm_channel_test',
                message: "Test alarm from {$runtime->appConfig->general->appName}. If you can read this, the {$channel} channel works.",
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
            $json ? CliOutput::json($output, ['status' => 'error', 'channel' => $channel, 'error' => $exception->getMessage()])
                : $output->writeln("<error>{$channel}: {$exception->getMessage()}</error>");

            return CliOutput::EXIT_WARNING;
        }

        $json ? CliOutput::json($output, ['status' => 'ok', 'channel' => $channel])
            : $output->writeln("<info>Sent a test alarm through {$channel}.</info>");

        return CliOutput::EXIT_OK;
    }
}
