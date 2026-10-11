<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Config\Validation\ConfigProblem;
use Mata\Config\Validation\ConfigValidationReport;
use Mata\Config\Validation\InstanceConfigValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'config:validate', description: 'Validate the instance config files with the loaders the app uses')]
final class ConfigValidateCommand extends Command
{
    public function __construct(
        private readonly InstanceConfigValidator $validator,
        private readonly string $defaultConfigPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'Validate only this file, e.g. servers.json')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Config directory to validate, e.g. a config repo checkout', $this->defaultConfigPath)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the report as JSON')
            ->setHelp(<<<'HELP'
                Runs every config file through the loader the web app and cron use, without falling
                back to defaults, and reports what the app would warn about or ignore.

                Exit codes: 0 valid, 1 warnings only, 2 errors. An error means the app would ignore a
                whole file (and use defaults) or drop a server; warnings are skipped entries and advice.

                Missing optional files are not problems. Run as the web server user: a file it cannot
                read fails the app even when your own user can read it.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('file');
        $path = $input->getOption('path');
        if ((!is_string($file) && $file !== null) || !is_string($path)) {
            return self::INVALID;
        }
        if ($file !== null && !in_array($file, $this->validator->knownFiles(), true)) {
            $output->writeln("<error>Unknown config file '{$file}'. Known: " . implode(', ', $this->validator->knownFiles()) . '</error>');

            return self::INVALID;
        }
        if (!is_dir($path)) {
            $output->writeln("<error>Config directory {$path} does not exist</error>");

            return ConfigValidationReport::EXIT_ERROR;
        }

        $report = $this->validator->validate($path, $file);

        if ($input->getOption('json')) {
            $output->writeln(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->writeHuman($report, $output);
        }

        return $report->exitCode();
    }

    private function writeHuman(ConfigValidationReport $report, OutputInterface $output): void
    {
        $output->writeln("Config: {$report->configPath}");
        foreach (array_keys($report->files) as $file) {
            $line = match ($report->fileStatus($file)) {
                'ok' => "  <info>✓</info> {$file}",
                'missing' => "  <comment>-</comment> {$file} <comment>(not present)</comment>",
                ConfigProblem::WARNING => "  <comment>!</comment> {$file}",
                default => "  <error>✗</error> {$file}",
            };
            $output->writeln($line);
            foreach ($report->problems as $problem) {
                if ($problem->file === $file) {
                    $tag = $problem->level === ConfigProblem::ERROR ? 'error' : 'comment';
                    $output->writeln("      <{$tag}>{$problem->level}</{$tag}> {$problem->message}");
                }
            }
        }

        $output->writeln(match ($report->exitCode()) {
            ConfigValidationReport::EXIT_ERROR => '<error>Invalid: the app would ignore the files or servers marked ✗.</error>',
            ConfigValidationReport::EXIT_WARNING => '<comment>Valid with warnings.</comment>',
            default => '<info>Valid.</info>',
        });
    }
}
