<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Config\Monitoring\ServerConfigState;
use Mata\Services\ServerConfig\ServerConfigFileEditor;
use Mata\Services\ServerConfig\ServerConnectionResult;
use Mata\Services\ServerConfig\ServerConnectionTester;
use RuntimeException;
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
        private readonly ServerConfigFileEditor $files,
        private readonly ServerConnectionTester $tester,
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

        try {
            $servers = $this->files->readServers();
            $secrets = $this->files->readSecrets();
        } catch (RuntimeException $exception) {
            $output->writeln("<error>{$exception->getMessage()}</error>");

            return CliOutput::EXIT_ERROR;
        }
        if (is_string($only) && !is_array($servers[$only] ?? null)) {
            $output->writeln("<error>Server '{$only}' is not in servers.json</error>");

            return CliOutput::EXIT_ERROR;
        }

        $results = [];
        foreach ($servers as $name => $entry) {
            $name = (string) $name;
            if (!is_array($entry) || str_starts_with($name, '_') || (is_string($only) && $only !== $name)) {
                continue;
            }
            if ($only === null && ServerConfigState::isDisabled($entry)) {
                continue;
            }
            $secret = $secrets[$name] ?? null;
            $apiKey = is_array($secret) && is_string($secret['api_key'] ?? null) ? $secret['api_key'] : null;
            $results[] = $this->tester->test($name, $entry, $apiKey);
        }

        $exitCode = self::exitCodeFor($results);
        if ($input->getOption('json')) {
            CliOutput::json($output, [
                'status' => CliOutput::statusName($exitCode),
                'servers' => array_map(static fn (ServerConnectionResult $result): array => $result->toArray(), $results),
            ]);

            return $exitCode;
        }
        foreach ($results as $result) {
            self::writeResult($output, $result);
        }
        if ($results === []) {
            $output->writeln('No enabled servers to test.');
        }

        return $exitCode;
    }

    /** @param list<ServerConnectionResult> $results */
    public static function exitCodeFor(array $results): int
    {
        $code = CliOutput::EXIT_OK;
        foreach ($results as $result) {
            if ($result->auth !== 'ok') {
                return CliOutput::EXIT_ERROR;
            }
            if ($result->health !== 'healthy') {
                $code = CliOutput::EXIT_WARNING;
            }
        }

        return $code;
    }

    public static function writeResult(OutputInterface $output, ServerConnectionResult $result): void
    {
        $mark = $result->isOk() ? '<info>✓</info>' : ($result->auth === 'ok' ? '<comment>!</comment>' : '<error>✗</error>');
        $output->writeln("{$mark} {$result->server} ({$result->apiUrl})");
        $health = $result->health . ($result->healthError !== null ? ": {$result->healthError}" : '');
        $output->writeln("    health: {$health}");
        $output->writeln("    auth:   {$result->auth}" . ($result->authError !== null ? ": {$result->authError}" : ''));
        if ($result->hint !== null) {
            $output->writeln("    <comment>{$result->hint}</comment>");
        }
    }
}
