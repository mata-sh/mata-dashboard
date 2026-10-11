<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Config\Monitoring\Section\ServersConfig;
use Mata\Config\Monitoring\ServerConfigState;
use Mata\Services\ServerConfig\ServerConfigFileEditor;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:list', description: 'List the servers in servers.json and whether each has a key')]
final class ServerListCommand extends Command
{
    public function __construct(private readonly ServerConfigFileEditor $files)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $servers = $this->files->readServers();
            $secrets = $this->files->readSecrets();
        } catch (RuntimeException $exception) {
            $output->writeln("<error>{$exception->getMessage()}</error>");

            return CliOutput::EXIT_ERROR;
        }

        $rows = [];
        foreach ($servers as $name => $entry) {
            $name = (string) $name;
            if (str_starts_with($name, '_')) {
                continue;
            }
            $secret = $secrets[$name] ?? null;
            $apiKey = is_array($secret) && is_string($secret['api_key'] ?? null) ? $secret['api_key'] : null;
            $merged = is_array($entry) ? $entry + ['api_key' => $apiKey] : $entry;
            $rows[] = [
                'name' => $name,
                'title' => is_array($entry) && is_string($entry['title'] ?? null) ? $entry['title'] : '',
                'api_url' => is_array($entry) && is_string($entry['api_url'] ?? null) ? $entry['api_url'] : '',
                'disabled' => is_array($entry) && ServerConfigState::isDisabled($entry),
                'has_api_key' => $apiKey !== null && $apiKey !== '',
                'problems' => ServersConfig::validationErrors([$name => $merged]),
            ];
        }
        $exitCode = array_filter($rows, static fn (array $row): bool => $row['problems'] !== []) === [] ? CliOutput::EXIT_OK : CliOutput::EXIT_ERROR;

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => CliOutput::statusName($exitCode), 'servers' => $rows]);

            return $exitCode;
        }
        if ($rows === []) {
            $output->writeln('No servers configured. Add one with: bin/mata server add <name> --api-url <url>');

            return $exitCode;
        }

        $table = new Table($output);
        $table->setHeaders(['Name', 'Title', 'API URL', 'Key', 'State']);
        foreach ($rows as $row) {
            $table->addRow([
                $row['name'],
                $row['title'],
                $row['api_url'],
                $row['has_api_key'] ? 'yes' : '<error>no</error>',
                $row['disabled'] ? 'disabled' : ($row['problems'] === [] ? 'ok' : '<error>' . implode('; ', $row['problems']) . '</error>'),
            ]);
        }
        $table->render();

        return $exitCode;
    }
}
