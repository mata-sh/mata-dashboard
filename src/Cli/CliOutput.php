<?php

declare(strict_types=1);

namespace Mata\Cli;

use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Shared output conventions for bin/mata. Every command ends in a StatusLevel: its value is the
 * exit code and its label the `status` of the --json document, which carries a schema_version.
 * With --json every outcome, failures included, is a JSON document.
 */
final class CliOutput
{
    /**
     * Written raw: the formatter would strip `<info>`-like text from values.
     *
     * @param array<string,mixed> $payload
     */
    public static function json(OutputInterface $output, array $payload): void
    {
        $output->writeln(json_encode(
            ['schema_version' => 1] + $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ), OutputInterface::OUTPUT_RAW);
    }

    /**
     * Reports a failure as `{"status":…,"error":…}` with --json, else as an error line.
     *
     * @param array<string,mixed> $payload extra fields for the JSON document
     */
    public static function fail(InputInterface $input, OutputInterface $output, string $message, StatusLevel $level = StatusLevel::Error, array $payload = []): int
    {
        if ($input->hasOption('json') && $input->getOption('json')) {
            self::json($output, ['status' => $level->label(), 'error' => $message] + $payload);
        } else {
            $output->writeln('<error>' . OutputFormatter::escape($message) . '</error>');
        }

        return $level->value;
    }

    /**
     * Typed confirmation before a production write, skipped with --yes; without a terminal
     * --yes is required. Null when confirmed, else the exit code of the reported refusal.
     *
     * @param string $what e.g. 'the PRODUCTION database'
     */
    public static function confirmProduction(InputInterface $input, OutputInterface $output, string $what, string $hint = ''): ?int
    {
        if ($input->getOption('yes')) {
            return null;
        }
        if (!$input->isInteractive()) {
            return self::fail($input, $output, "Changing {$what} needs --yes without a terminal.{$hint}");
        }
        $answer = (new QuestionHelper())->ask($input, $output, new Question("Changing {$what}. Type 'CONFIRM PRODUCTION' to continue: "));

        return is_string($answer) && trim($answer) === 'CONFIRM PRODUCTION' ? null : self::fail($input, $output, 'Cancelled.');
    }

    /** The level as a coloured symbol, for list lines. */
    public static function mark(StatusLevel $level): string
    {
        return match ($level) {
            StatusLevel::Ok => '<info>✓</info>',
            StatusLevel::Warning => '<comment>!</comment>',
            StatusLevel::Error => '<error>✗</error>',
            StatusLevel::Unknown => '<comment>?</comment>',
        };
    }

    /** The level as a coloured word, e.g. in `status`. */
    public static function label(StatusLevel $level): string
    {
        return match ($level) {
            StatusLevel::Ok => '<info>ok</info>',
            StatusLevel::Warning => '<comment>warning</comment>',
            StatusLevel::Error => '<error>error</error>',
            StatusLevel::Unknown => '<comment>unknown</comment>',
        };
    }

    /** Name of the account running bin/mata; null without the posix extension. */
    public static function currentUser(): ?string
    {
        $user = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;

        return is_array($user) ? $user['name'] : null;
    }

    /**
     * Appended when logs/ or temp/ is not writable: commands other than `config validate` and
     * `server *` write there, which only the web server user may.
     */
    public static function withWebUserHint(string $message): string
    {
        if (!str_contains($message, 'Directory is not writable')) {
            return $message;
        }

        return $message . '. Run as the web server user: sudo -u www-data php bin/mata … (http on Arch, apache on RHEL; make does this in Docker)';
    }
}
