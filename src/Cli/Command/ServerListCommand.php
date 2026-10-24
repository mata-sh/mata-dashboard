<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Config\Monitoring\Section\ServersConfig;
use Mata\Config\Monitoring\ServerConfigState;
use Mata\Config\Monitoring\ServersConfigEditor;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:list', description: 'List the servers in servers.json and whether each has a key')]
final class ServerListCommand extends Command
{
    public function __construct(private readonly ServersConfigEditor $editor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entries = $this->editor->entries();

        $rows = [];
        foreach ($entries as $name => ['entry' => $entry, 'apiKey' => $apiKey]) {
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
        $level = array_filter($rows, static fn (array $row): bool => $row['problems'] !== []) === [] ? StatusLevel::Ok : StatusLevel::Error;

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => $level->label(), 'servers' => $rows]);
        } else {
            $this->writeHuman($output, $rows);
        }

        return $level->value;
    }

    /** @param list<array{name:string,title:string,api_url:string,disabled:bool,has_api_key:bool,problems:list<string>}> $rows */
    private function writeHuman(OutputInterface $output, array $rows): void
    {
        if ($rows === []) {
            $output->writeln('No servers configured. Add one with: bin/mata server add <name> --api-url <url>');

            return;
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
    }
}
