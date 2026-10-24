<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Config\Monitoring\Section\ServersConfig;
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

#[AsCommand(name: 'server:add', description: 'Add a node to servers.json, store its key in servers.secrets.json, and test it')]
final class ServerAddCommand extends Command
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
        $apiUrl = self::nonEmpty($input->getOption('api-url'));
        $json = (bool) $input->getOption('json');
        if ($apiUrl === null) {
            return CliOutput::fail($input, $output, '--api-url is required');
        }

        // Empty options count as not given; key order is the order written to servers.json.
        $entry = array_filter([
            'title' => self::nonEmpty($input->getOption('title')) ?? $name,
            'url' => self::nonEmpty($input->getOption('url')),
            'api_url' => $apiUrl,
            'env' => self::nonEmpty($input->getOption('env')),
            'allow_http' => $input->getOption('allow-http') ? true : null,
        ], static fn (mixed $value): bool => $value !== null);

        $apiKey = self::nonEmpty($input->getOption('api-key')) ?? self::nonEmpty(getenv('MATA_NODE_API_KEY'));
        $generated = $apiKey === null;
        $apiKey ??= bin2hex(random_bytes(32));

        // Also rejects an invalid name, before anything is written.
        $errors = ServersConfig::validationErrors([$name => $entry + ['api_key' => $apiKey]]);
        if ($errors !== []) {
            return CliOutput::fail($input, $output, implode("\n", $errors));
        }

        $nodeEnv = ['DASHBOARD_SERVER_ID' => $name, 'DASHBOARD_API_KEY' => $apiKey];
        if ($input->getOption('dry-run')) {
            if ($json) {
                CliOutput::json($output, ['status' => StatusLevel::Ok->label(), 'dry_run' => true, 'server' => $name, 'servers_json_entry' => $entry, 'node_env' => $nodeEnv]);
            } else {
                $output->writeln("Would add to servers.json:\n" . json_encode([$name => $entry], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $output->writeln("Would store the key in servers.secrets.json. Node config.env:\n" . self::envLines($nodeEnv));
            }

            return StatusLevel::Ok->value;
        }

        $this->editor->add($name, $entry, $apiKey);

        $result = $input->getOption('no-test') ? null : $this->tester->test($name, $entry, $apiKey);
        $level = $result?->isOk() && $this->editor->warnings() === [] ? StatusLevel::Ok : StatusLevel::Warning;

        if ($json) {
            CliOutput::json($output, [
                'status' => $level->label(),
                'server' => $name,
                'api_key_generated' => $generated,
                'node_env' => $nodeEnv,
                'warnings' => $this->editor->warnings(),
                'test' => $result,
            ]);
        } else {
            $this->writeHuman($output, $name, $generated, $nodeEnv, $result);
        }

        return $level->value;
    }

    /** @param array<string,string> $nodeEnv */
    private function writeHuman(OutputInterface $output, string $name, bool $generated, array $nodeEnv, ?NodeConnectionResult $result): void
    {
        $output->writeln("<info>Added '{$name}'</info> to servers.json; key stored in servers.secrets.json.");
        foreach ($this->editor->warnings() as $warning) {
            $output->writeln("<comment>{$warning}</comment>");
        }
        if ($generated) {
            $output->writeln("\nSet these in the node's config/config.env (the key is shown only now):\n\n" . self::envLines($nodeEnv) . "\n");
        }
        if ($result !== null) {
            ServerTestCommand::writeResult($output, $result);
            if (!$result->isOk()) {
                $output->writeln("Re-run <info>bin/mata server test {$name}</info> once the node is configured.");
            }
        }
    }

    private static function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string,string> $env */
    private static function envLines(array $env): string
    {
        $lines = [];
        foreach ($env as $key => $value) {
            $lines[] = "{$key}={$value}";
        }

        return implode("\n", $lines);
    }
}
