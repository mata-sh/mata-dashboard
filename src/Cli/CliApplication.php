<?php

declare(strict_types=1);

namespace Mata\Cli;

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
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRun($input, $output);
        } catch (ExceptionInterface $exception) {
            $this->renderThrowable($exception, $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);

            return StatusLevel::Error->value;
        }
    }

    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRunCommand($command, $input, $output);
        } catch (ExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($output->isVerbose()) {
                $this->renderThrowable($exception, $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);
            }

            return CliOutput::fail($input, $output, $exception->getMessage());
        }
    }
}
