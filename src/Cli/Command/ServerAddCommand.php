<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Config\Monitoring\Section\ServersConfig;
use Mata\Config\Monitoring\ServersConfigEditor;
use Mata\Services\Monitor\NodeConnectionTester;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:add', description: 'Add a node to servers.json, store its key in servers.secrets.json, and test it')]
final class ServerAddCommand extends Command
{
    public function __construct(
        private readonly ServersConfigEditor $files,
        private readonly NodeConnectionTester $tester,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, "Server name; must equal the node's DASHBOARD_SERVER_ID")
            ->addOption('api-url', null, InputOption::VALUE_REQUIRED, "The node's base URL, e.g. https://node.example.com/")
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Display title (default: the name)')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Public URL of the monitored site, linked from the card')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'prod, live, test, dev or staging')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'Use this key (the node already has one); default: generate. Also read from MATA_NODE_API_KEY')
            ->addOption('allow-http', null, InputOption::VALUE_NONE, 'Allow a plain-HTTP api_url (development only)')
            ->addOption('no-test', null, InputOption::VALUE_NONE, 'Do not contact the node after adding')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be written without writing')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON')
            ->setHelp(<<<'HELP'
                Writes the server to servers.json and its key to servers.secrets.json (new file: 0640, group of
                servers.json), prints the two lines to put in the node's config/config.env, then tests the node.

                Exit codes: 0 added and the node accepted the key, 1 added but the test failed or was skipped
                (expected until the node has the key), 2 nothing written.

                Run as the owner of config/, not the web server user: in Docker
                `docker compose exec -u "$(id -u):$(id -g)" mata-web php bin/mata server add …`, or `make mata ARGS="server add …"`.
                The printed key is a secret: it is shown once and stored only in servers.secrets.json.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $apiUrl = $input->getOption('api-url');
        $json = (bool) $input->getOption('json');
        if (preg_match(ServersConfig::NAME_PATTERN, $name) !== 1) {
            return CliOutput::fail($input, $output, "Invalid name '{$name}': use letters, digits, '.', '_' or '-' (max 64), starting with a letter or digit");
        }
        if (!is_string($apiUrl) || $apiUrl === '') {
            return CliOutput::fail($input, $output, '--api-url is required');
        }

        $entry = ['title' => is_string($input->getOption('title')) ? $input->getOption('title') : $name];
        if (is_string($input->getOption('url'))) {
            $entry['url'] = $input->getOption('url');
        }
        $entry['api_url'] = $apiUrl;
        if (is_string($input->getOption('env'))) {
            $entry['env'] = $input->getOption('env');
        }
        if ($input->getOption('allow-http')) {
            $entry['allow_http'] = true;
        }

        $apiKey = self::nonEmpty($input->getOption('api-key')) ?? self::nonEmpty(getenv('MATA_NODE_API_KEY'));
        $generated = $apiKey === null;
        $apiKey ??= bin2hex(random_bytes(32));

        $errors = ServersConfig::validationErrors([$name => $entry + ['api_key' => $apiKey]]);
        if ($errors !== []) {
            return CliOutput::fail($input, $output, implode("\n", $errors));
        }

        $nodeLines = "DASHBOARD_SERVER_ID={$name}\nDASHBOARD_API_KEY={$apiKey}";
        if ($input->getOption('dry-run')) {
            if ($json) {
                CliOutput::json($output, ['status' => 'ok', 'dry_run' => true, 'server' => $name, 'servers_json_entry' => $entry, 'node_env' => ['DASHBOARD_SERVER_ID' => $name, 'DASHBOARD_API_KEY' => $apiKey]]);
            } else {
                $output->writeln("Would add to servers.json:\n" . json_encode([$name => $entry], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $output->writeln("Would store the key in servers.secrets.json. Node config.env:\n{$nodeLines}");
            }

            return StatusLevel::Ok->value;
        }

        $this->files->add($name, $entry, $apiKey);

        $result = $input->getOption('no-test') ? null : $this->tester->test($name, $entry, $apiKey);
        $level = $result?->isOk() && $this->files->warnings() === [] ? StatusLevel::Ok : StatusLevel::Warning;

        if ($json) {
            CliOutput::json($output, [
                'status' => $level->label(),
                'server' => $name,
                'api_key_generated' => $generated,
                'node_env' => ['DASHBOARD_SERVER_ID' => $name, 'DASHBOARD_API_KEY' => $apiKey],
                'warnings' => $this->files->warnings(),
                'test' => $result?->toArray(),
            ]);

            return $level->value;
        }

        $output->writeln("<info>Added '{$name}'</info> to servers.json; key stored in servers.secrets.json.");
        foreach ($this->files->warnings() as $warning) {
            $output->writeln("<comment>{$warning}</comment>");
        }
        if ($generated) {
            $output->writeln("\nSet these in the node's config/config.env (the key is shown only now):\n\n{$nodeLines}\n");
        }
        if ($result !== null) {
            ServerTestCommand::writeResult($output, $result);
            if (!$result->isOk()) {
                $output->writeln("Re-run <info>bin/mata server test {$name}</info> once the node is configured.");
            }
        }

        return $level->value;
    }

    private static function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
