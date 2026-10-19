<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use DateTimeImmutable;
use Mata\Bootstrap\AlarmChannelRuntime;
use Mata\Cli\CliConfig;
use Mata\Cli\CliOutput;
use Mata\Config\LogConfigurator;
use Mata\Services\Alarm\Delivery\Dto\AlarmDeliveryView;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmLevel;
use Mata\Services\Status\StatusLevel;
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
    public function __construct(private readonly CliConfig $cli)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('channel', InputArgument::OPTIONAL, 'email or a channel from channels.json; omit to list them')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON')
            ->setHelp('Exit codes: 0 sent (or channels listed), 2 delivery failed or unknown channel.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->cli->load();
        $paths = $this->cli->paths();
        $senders = AlarmChannelRuntime::load(
            paths: $paths,
            appConfig: $config,
            log: (new LogConfigurator())->createConfiguredLogger($config->logging, $paths, 'alarm-test'),
        )->senders();

        $channel = strtolower(trim((string) $input->getArgument('channel')));
        if (!isset($senders[$channel])) {
            $known = array_keys($senders);
            $level = $channel === '' ? StatusLevel::Ok : StatusLevel::Error;
            if ($input->getOption('json')) {
                CliOutput::json($output, ['status' => $level->label(), 'channels' => $known] + ($channel === '' ? [] : ['error' => "Unknown channel '{$channel}'"]));
            } else {
                $output->writeln(($channel === '' ? '' : "<error>Unknown channel '{$channel}'.</error> ") . 'Configured channels: ' . implode(', ', $known));
                $output->writeln('Channels missing here are disabled or invalid in channels.json; see bin/mata config validate channels.json');
            }

            return $level->value;
        }

        $now = new DateTimeImmutable('now');
        $view = new AlarmDeliveryView(
            alarm: new Alarm(
                type: 'alarm_channel_test',
                message: "Test alarm from {$config->general->appName}. If you can read this, the {$channel} channel works.",
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
            return CliOutput::fail($input, $output, "{$channel}: {$exception->getMessage()}", payload: ['channel' => $channel]);
        }

        return CliOutput::ok($input, $output, "Sent a test alarm through {$channel}.", ['channel' => $channel]);
    }
}
