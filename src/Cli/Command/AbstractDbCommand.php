<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliConfig;
use Mata\Cli\CliOutput;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Database\MigrationRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Database commands against APP_ENV's environment.
 */
abstract class AbstractDbCommand extends Command
{
    protected ApplicationConfig $config;

    public function __construct(
        private readonly CliConfig $cli,
    ) {
        parent::__construct();
    }

    abstract protected function writes(): bool;

    abstract protected function handle(InputInterface $input, OutputInterface $output): int;

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON');
        if ($this->writes()) {
            $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip the production confirmation (required in prod without a terminal)');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->config = $this->cli->load();
        if ($this->writes() && $this->config->environment->env === 'prod') {
            $refused = CliOutput::confirmProduction($input, $output, 'the PRODUCTION database', ' Back up first: make db-backup');
            if ($refused !== null) {
                return $refused;
            }
        }

        return $this->handle($input, $output);
    }

    /** Phinx output goes to $output for humans and is dropped for --json. */
    protected function migrations(InputInterface $input, OutputInterface $output): MigrationRunner
    {
        return new MigrationRunner(
            $this->cli->paths()->appPath . '/migrations',
            $this->config->database,
            $this->config->environment->env,
            $input->getOption('json') ? null : $output,
        );
    }
}
