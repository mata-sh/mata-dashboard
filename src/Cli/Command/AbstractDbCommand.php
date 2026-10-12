<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\EnvConfigLoader;
use Mata\Config\Validation\InstanceConfigValidator;
use Mata\Database\MigrationRunner;
use Mata\Utils\PathResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Throwable;

/**
 * Database commands against APP_ENV's environment.
 */
abstract class AbstractDbCommand extends Command
{
    protected ApplicationConfig $config;

    public function __construct(
        protected readonly PathResolver $paths,
        private readonly string $configPath,
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
        try {
            $this->config = (new EnvConfigLoader())->load(InstanceConfigValidator::ENV_FILES, $this->configPath);
        } catch (Throwable $exception) {
            return $this->fail($input, $output, CliOutput::withWebUserHint("Cannot load the config: {$exception->getMessage()}"));
        }

        $env = $this->config->environment->env;
        if ($this->writes() && $env === 'prod' && !$input->getOption('yes')) {
            if (!$input->isInteractive()) {
                return $this->fail($input, $output, 'Production database changes need --yes without a terminal. Back up first: make db-backup');
            }
            $helper = $this->getHelper('question');
            $answer = $helper instanceof QuestionHelper ? $helper->ask($input, $output, new Question("Changing the PRODUCTION database. Type 'CONFIRM PRODUCTION' to continue: ")) : null;
            if (!is_string($answer) || trim($answer) !== 'CONFIRM PRODUCTION') {
                return $this->fail($input, $output, 'Cancelled.');
            }
        }

        try {
            return $this->handle($input, $output);
        } catch (Throwable $exception) {
            return $this->fail($input, $output, $exception->getMessage());
        }
    }

    /** Phinx output goes to $output for humans and is dropped for --json. */
    protected function migrations(InputInterface $input, OutputInterface $output): MigrationRunner
    {
        return new MigrationRunner(
            $this->paths->appPath . '/migrations',
            $this->config->database,
            $this->config->environment->env,
            $input->getOption('json') ? null : $output,
        );
    }

    protected function fail(InputInterface $input, OutputInterface $output, string $message): int
    {
        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => 'error', 'error' => $message]);
        } else {
            $output->writeln("<error>{$message}</error>");
        }

        return CliOutput::EXIT_ERROR;
    }
}
