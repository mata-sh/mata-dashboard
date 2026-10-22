<?php

declare(strict_types=1);

namespace Mata\Cli;

use Closure;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Every failure exits 2 (error). Symfony would exit 1 for an uncaught exception, which in
 * bin/mata means warning. Exceptions from a command honour --json; usage errors keep
 * Symfony's rendering with the synopsis, and -v adds the stack trace.
 */
final class CliApplication extends Application
{
    private ?string $resolvedVersion = null;

    /**
     * Detecting the version can shell out to git, so it waits until --version or list shows it.
     *
     * @param Closure(): ?string $detectVersion
     */
    public function __construct(string $name, private readonly Closure $detectVersion)
    {
        parent::__construct($name);
    }

    public function getVersion(): string
    {
        // UNKNOWN is Symfony's "no version": the header then shows only the name.
        return $this->resolvedVersion ??= ($this->detectVersion)() ?? 'UNKNOWN';
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRun($input, $output);
        } catch (ExceptionInterface $exception) {
            $this->renderThrowable($exception, self::errorOutput($output));

            return StatusLevel::Error->value;
        }
    }

    /**
     * A failure inside a command goes through CliOutput so it honours --json;
     * -v adds the stack trace.
     */
    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRunCommand($command, $input, $output);
        } catch (ExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($output->isVerbose()) {
                $this->renderThrowable($exception, self::errorOutput($output));
            }

            return CliOutput::fail($input, $output, $exception->getMessage());
        }
    }

    private static function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
