<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Config\Monitoring\ServerConfigState;
use Mata\Config\Monitoring\ServersConfigEditor;
use Mata\Services\Monitor\Dto\NodeConnectionResult;
use Mata\Services\Monitor\NodeConnectionTester;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:test', description: 'Check that servers answer /health and accept this dashboard\'s signed requests')]
final class ServerTestCommand extends Command
{
    public function __construct(
        private readonly ServersConfigEditor $editor,
        private readonly NodeConnectionTester $tester,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'Server to test; all enabled servers when omitted')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON')
            ->setHelp('Exit codes: 0 all healthy and authenticated, 1 authenticated but /health not healthy, 2 a server unreachable or rejecting the key.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $only = $input->getArgument('name');

        $entries = $this->editor->entries();
        if (is_string($only) && !is_array($entries[$only]['entry'] ?? null)) {
            return CliOutput::fail($input, $output, "Server '{$only}' is not in servers.json");
        }

        $results = [];
        foreach ($entries as $name => ['entry' => $entry, 'apiKey' => $apiKey]) {
            if (!is_array($entry) || (is_string($only) && $only !== $name) || ($only === null && ServerConfigState::isDisabled($entry))) {
                continue;
            }
            $results[] = $this->tester->test($name, $entry, $apiKey);
        }

        $level = StatusLevel::worst(array_map(static fn (NodeConnectionResult $result): StatusLevel => $result->level(), $results));
        if ($input->getOption('json')) {
            CliOutput::json($output, [
                'status' => $level->label(),
                'servers' => $results,
            ]);
        } else {
            foreach ($results as $result) {
                self::writeResult($output, $result);
            }
            if ($results === []) {
                $output->writeln('No enabled servers to test.');
            }
        }

        return $level->value;
    }

    public static function writeResult(OutputInterface $output, NodeConnectionResult $result): void
    {
        $output->writeln(CliOutput::mark($result->level()) . " {$result->server} ({$result->apiUrl})");
        $health = $result->health->value . ($result->healthError !== null ? ": {$result->healthError}" : '');
        $output->writeln("    health: {$health}");
        $output->writeln("    auth:   {$result->auth->value}" . ($result->authError !== null ? ": {$result->authError}" : ''));
        if ($result->hint !== null) {
            $output->writeln("    <comment>{$result->hint}</comment>");
        }
    }
}
