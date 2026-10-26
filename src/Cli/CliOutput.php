<?php

declare(strict_types=1);

namespace Mata\Cli;

use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Shared output conventions for bin/mata. Every command ends in a StatusLevel: its value is the
 * exit code and its label the `status` of the --json document, which carries a schema_version.
 * With --json every outcome, failures included, is a JSON document.
 */
final class CliOutput
{
    /** Raised when a field changes meaning or disappears; new fields keep it. */
    public const SCHEMA_VERSION = 1;

    /**
     * Written raw: the formatter would strip `<info>`-like text from values.
     *
     * @param array<string,mixed> $payload
     */
    public static function json(OutputInterface $output, array $payload): void
    {
        $output->writeln(json_encode(
            ['schema_version' => self::SCHEMA_VERSION] + $payload,
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
     * Reports success as `{"status":"ok",…}` with --json, else as an info line.
     *
     * @param array<string,mixed> $payload fields for the JSON document
     */
    public static function ok(InputInterface $input, OutputInterface $output, string $message, array $payload = []): int
    {
        if ($input->getOption('json')) {
            self::json($output, ['status' => StatusLevel::Ok->label()] + $payload);
        } else {
            $output->writeln("<info>{$message}</info>");
        }

        return StatusLevel::Ok->value;
    }

    /**
     * y/N question, skipped with --yes; without a terminal --yes is required. Null when
     * confirmed, else the exit code of the reported refusal.
     */
    public static function confirm(InputInterface $input, OutputInterface $output, string $question): ?int
    {
        if ($input->getOption('yes')) {
            return null;
        }
        if (!$input->isInteractive()) {
            return self::fail($input, $output, 'Pass --yes to confirm without a terminal.');
        }
        $confirmed = (new QuestionHelper())->ask($input, $output, new ConfirmationQuestion("{$question} [y/N] ", false));

        return $confirmed === true ? null : self::fail($input, $output, 'Cancelled.');
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

    /**
     * A data value for human output: escaped, so `<…>` in node or user text prints as text
     * instead of styling; '-' when missing or empty; booleans as yes/no; lists joined with
     * commas, nested lists in brackets: "a, [b, c]".
     */
    public static function value(mixed $value): string
    {
        $text = self::plain($value);

        return $text === '' ? '-' : OutputFormatter::escape($text);
    }

    /** "server/app", escaped. A missing name is null from EventRepository and '' from AlarmRepository. */
    public static function location(mixed $server, mixed $app): string
    {
        $location = self::value($server);

        return self::value($app) === '-' ? $location : $location . '/' . self::value($app);
    }

    /** value() before escaping; null and objects in a list are left out. */
    private static function plain(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => (string) $value,
            is_array($value) => implode(', ', array_map(
                static fn (mixed $item): string => is_array($item) ? '[' . self::plain($item) . ']' : self::plain($item),
                array_filter($value, static fn (mixed $item): bool => is_scalar($item) || is_array($item)),
            )),
            default => '',
        };
    }

    /**
     * The note under a list that --limit cut short.
     *
     * @param string   $items   plural noun, e.g. 'events'
     * @param null|int $matched how many matched in total, null when unknown
     */
    public static function truncated(OutputInterface $output, string $items, int $shown, ?int $matched = null): void
    {
        $output->writeln($matched === null
            ? "<comment>Showing {$shown} {$items}; more match. Narrow the filters or raise --limit.</comment>"
            : "<comment>Showing {$shown} of {$matched} {$items}; raise --limit for more.</comment>");
    }

    /** Right-aligns numeric columns, keeping the table's style otherwise. Call after setStyle(). */
    public static function alignRight(Table $table, int ...$columns): void
    {
        $right = (clone $table->getStyle())->setPadType(STR_PAD_LEFT);
        foreach ($columns as $column) {
            $table->setColumnStyle($column, $right);
        }
    }

    /** Name of the account running bin/mata; null without the posix extension. */
    public static function currentUser(): ?string
    {
        $user = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;

        return is_array($user) ? $user['name'] : null;
    }
}
