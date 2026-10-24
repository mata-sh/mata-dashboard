<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use InvalidArgumentException;
use Mata\Bootstrap\AdvisoryRuntime;
use Mata\Bootstrap\MonitoringRuntime;
use Mata\Cli\CliConfig;
use Mata\Cli\CliOutput;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\LogConfigurator;
use Mata\Config\Monitoring\Loader\AdvisoriesConfigLoader;
use Mata\Database\PdoDatabase;
use Mata\Services\Event\EventManager;
use Monolog\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Security advisory commands: they need advisories.json and the database. */
abstract class AbstractAdvisoryCommand extends Command
{
    protected ApplicationConfig $config;
    protected AdvisoryRuntime $advisories;
    protected PdoDatabase $db;

    public function __construct(private readonly CliConfig $cli)
    {
        parent::__construct();
    }

    abstract protected function handle(InputInterface $input, OutputInterface $output): int;

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->config = $this->cli->load();
        $this->db = new PdoDatabase($this->config->database);
        $this->advisories = AdvisoryRuntime::build($this->cli->paths(), $this->config, $this->db);
        if (!$this->advisories->config->configured) {
            return CliOutput::fail($input, $output, 'config/' . AdvisoriesConfigLoader::ADVISORIES_FILE . ' is missing; copy it from config.example/'
                . AdvisoriesConfigLoader::ADVISORIES_FILE . '.example and run bin/mata config validate.');
        }

        return $this->handle($input, $output);
    }

    protected function logger(string $channel): Logger
    {
        return (new LogConfigurator())->createConfiguredLogger($this->config->logging, $this->cli->paths(), $channel);
    }

    /** Events as the cron jobs record them, so a CLI poll alarms exactly like a scheduled one. */
    protected function eventManager(Logger $log): EventManager
    {
        return MonitoringRuntime::build($this->cli->paths(), $this->config, $log, $this->db)->eventManager;
    }

    /** @return ?list<string> null without --source */
    protected function sourceOption(InputInterface $input): ?array
    {
        $sources = $input->hasOption('source') ? $input->getOption('source') : [];
        if (!is_array($sources) || $sources === []) {
            return null;
        }
        $keys = array_values(array_filter($sources, 'is_string'));
        foreach ($keys as $key) {
            if ($this->advisories->config->source($key) === null) {
                throw new InvalidArgumentException("Unknown advisory source '{$key}'. Sources: " . implode(', ', array_keys($this->advisories->config->sources)));
            }
        }

        return $keys;
    }
}
