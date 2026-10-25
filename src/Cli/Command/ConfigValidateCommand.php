<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Config\Validation\ConfigValidationReport;
use Mata\Config\Validation\ConfigValidator;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'config:validate', description: 'Validate the config files with the loaders the app uses')]
final class ConfigValidateCommand extends Command
{
    public function __construct(
        private readonly ConfigValidator $validator,
        private readonly string $defaultConfigPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'Validate only this file name inside --path (see help for accepted names)')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Config directory to validate, e.g. a config repo checkout', $this->defaultConfigPath)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the report as JSON')
            ->setHelp(<<<HELP
                Runs every config file through the loader the web app and cron use, without falling
                back to defaults, and reports what the app would warn about or ignore.

                Pass a file name to validate only the files loaded with it: any env file validates
                all three, and either server file validates both. Accepted names:
                  {$this->knownFilesHelp()}

                Exit codes: 0 valid, 1 warnings only, 2 errors. Errors include invalid environment
                configuration, rejected files and dropped servers; warnings are skipped entries and advice.

                Missing optional files are not problems. Run as the web server user: a file it cannot
                read fails the app even when your own user can read it.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('file');
        $file = is_string($file) ? $file : null;
        $path = (string) $input->getOption('path');

        $knownFiles = $this->validator->knownFiles();
        if ($file !== null && !in_array($file, $knownFiles, true)) {
            return CliOutput::fail($input, $output, "Unknown config file '{$file}'. Known: " . implode(', ', $knownFiles));
        }

        if (!is_dir($path)) {
            return CliOutput::fail($input, $output, "Config directory {$path} does not exist");
        }

        $report = $this->validator->validate($path, $file);

        if ($input->getOption('json')) {
            CliOutput::json($output, $report->jsonSerialize());
        } else {
            $this->writeHuman($output, $report);
        }

        return $report->level()->value;
    }

    /** Accepted file names, wrapped and indented to fit the help text. */
    private function knownFilesHelp(): string
    {
        return wordwrap(implode(', ', $this->validator->knownFiles()), 86, "\n  ");
    }

    /** Prints each file's status, then every problem tagged with the files it came from, then the verdict. */
    private function writeHuman(OutputInterface $output, ConfigValidationReport $report): void
    {
        $output->writeln("Config: {$report->configPath}");
        foreach (array_keys($report->files) as $file) {
            $status = $report->fileStatus($file);
            if ($status === null) {
                $output->writeln("  <comment>-</comment> {$file} <comment>(not present)</comment>");

                continue;
            }
            $output->writeln('  ' . CliOutput::mark($status) . " {$file}");
        }
        foreach ($report->problems as $problem) {
            $labels = $problem->sourceLabels();
            $sources = $labels === [] ? 'unattributed' : implode(', ', $labels);
            $text = OutputFormatter::escape("[{$sources}] {$problem->message}");
            $output->writeln('  ' . CliOutput::label($problem->level) . " {$text}");
        }

        $output->writeln(match ($report->level()) {
            StatusLevel::Error => '<error>Invalid configuration: fix the errors above.</error>',
            StatusLevel::Warning => '<comment>Valid with warnings.</comment>',
            default => '<info>Valid.</info>',
        });
    }
}
