<?php

declare(strict_types=1);

namespace Mata\Utils;

/**
 * The installed version, derived from git release tags; nothing is edited per release.
 *
 * A Docker build stamps VERSION, because its context has no .git. Checkouts ask
 * scripts/version.sh, which make also uses to stamp images.
 */
final class AppVersion
{
    public static function detect(string $appPath): ?string
    {
        $file = $appPath . '/VERSION';
        if (is_file($file)) {
            $version = trim((string) file_get_contents($file));

            return $version !== '' ? $version : null;
        }

        return file_exists($appPath . '/.git') ? self::describe($appPath) : null;
    }

    private static function describe(string $appPath): ?string
    {
        $process = proc_open(['sh', $appPath . '/scripts/version.sh'], [1 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }

        $output = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);

        return proc_close($process) === 0 && $output !== '' ? $output : null;
    }
}
