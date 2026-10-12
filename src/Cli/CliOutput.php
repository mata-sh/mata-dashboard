<?php

declare(strict_types=1);

namespace Mata\Cli;

use Symfony\Component\Console\Output\OutputInterface;

/** Shared output conventions for bin/mata: every --json payload carries a schema_version. */
final class CliOutput
{
    public const EXIT_OK = 0;
    public const EXIT_WARNING = 1;
    public const EXIT_ERROR = 2;
    public const EXIT_UNKNOWN = 3;

    /** @param array<string,mixed> $payload */
    public static function json(OutputInterface $output, array $payload): void
    {
        $output->writeln(json_encode(
            ['schema_version' => 1] + $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
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

    public static function statusName(int $exitCode): string
    {
        return match ($exitCode) {
            self::EXIT_OK => 'ok',
            self::EXIT_WARNING => 'warning',
            self::EXIT_ERROR => 'error',
            default => 'unknown',
        };
    }
}
