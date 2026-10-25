<?php

declare(strict_types=1);

namespace Mata\Services\Collect\Demo;

/**
 * Static demo data for server-system responses (metrics, OS info, databases,
 * cronjobs). Values are moved verbatim from MockServerDataCollector; methods
 * that embed mt_rand()/date() intentionally recompute per call.
 */
final class DemoServerCatalog
{
    private const DATABASES = [
        'mata_dashboard' => 'Dashboard state store',
        'ecommerce_prod' => 'Production ecommerce database',
        'analytics' => 'Reporting and analytics warehouse',
        'legacy_reports' => 'Legacy reporting database',
    ];

    /**
     * Generate base metrics based on server type.
     *
     * @return array<string,int>
     */
    public function baseMetricsForServerType(string $serverName): array
    {
        $metrics = [
            'production-web' => [
                'cpu' => 45,
                'ram_capacity' => 16384 * 1024,
                'ram_used' => 11000 * 1024,
                'disk_capacity' => 500000 * 1024,
                'disk_used' => 280000 * 1024,
            ],
            'database-server' => [
                'cpu' => 30,
                'ram_capacity' => 32768 * 1024,
                'ram_used' => 24000 * 1024,
                'disk_capacity' => 2000000 * 1024,
                'disk_used' => 1200000 * 1024,
            ],
            'staging-server' => [
                'cpu' => 15,
                'ram_capacity' => 8192 * 1024,
                'ram_used' => 4500 * 1024,
                'disk_capacity' => 200000 * 1024,
                'disk_used' => 85000 * 1024,
            ],
            'development-server' => [
                'cpu' => 25,
                'ram_capacity' => 8192 * 1024,
                'ram_used' => 5200 * 1024,
                'disk_capacity' => 250000 * 1024,
                'disk_used' => 125000 * 1024,
            ],
            'backup-server' => [
                'cpu' => 10,
                'ram_capacity' => 16384 * 1024,
                'ram_used' => 6000 * 1024,
                'disk_capacity' => 5000000 * 1024,
                'disk_used' => 3500000 * 1024,
            ],
            'load-balancer' => [
                'cpu' => 20,
                'ram_capacity' => 4096 * 1024,
                'ram_used' => 2800 * 1024,
                'disk_capacity' => 100000 * 1024,
                'disk_used' => 35000 * 1024,
            ],
        ];

        return $metrics[$serverName] ?? $metrics['production-web'];
    }

    /**
     * @return array<int,string>
     */
    public function osOptions(): array
    {
        return [
            'Ubuntu 22.04.3 LTS',
            'Ubuntu 20.04.6 LTS',
            'Debian GNU/Linux 11 (bullseye)',
            'Debian GNU/Linux 12 (bookworm)',
            'CentOS Linux 8.5.2111',
            'Rocky Linux 9.2',
            'AlmaLinux 9.1',
            'Fedora 38',
        ];
    }

    /**
     * @return array<int,string>
     */
    public function commonPhpExtensions(): array
    {
        return [
            'Core', 'date', 'libxml', 'openssl', 'pcre', 'zlib', 'filter', 'hash',
            'json', 'pcntl', 'readline', 'Reflection', 'SPL', 'session', 'standard',
            'mysqlnd', 'PDO', 'pdo_mysql', 'mysqli', 'curl', 'mbstring', 'gd',
            'xml', 'xmlreader', 'xmlwriter', 'SimpleXML', 'dom', 'fileinfo',
            'iconv', 'tokenizer', 'zip', 'Phar', 'ctype', 'posix', 'sockets',
        ];
    }

    /**
     * @return array<int,string>
     */
    public function optionalPhpExtensions(): array
    {
        return [
            'redis', 'memcached', 'opcache', 'apcu', 'imagick', 'bcmath',
            'gmp', 'intl', 'soap', 'xsl', 'exif', 'calendar', 'ftp',
            'gettext', 'shmop', 'sysvmsg', 'sysvsem', 'sysvshm',
        ];
    }

    /**
     * Node payload for `GET /databases/overview`.
     *
     * @return array<string,mixed>
     */
    public function databaseOverview(): array
    {
        $primary = [
            'engine' => 'mariadb',
            'collected_at' => gmdate('c'),
            'status' => [
                'max_connections' => 151,
                'connections' => mt_rand(8, 40),
                'running' => mt_rand(1, 6),
                'max_used_connections' => mt_rand(45, 90),
                'refused_connections' => 0,
                'aborted_connects' => mt_rand(0, 12),
                'uptime_seconds' => mt_rand(5, 40) * 86400,
            ],
            'databases' => array_map(
                static fn (string $name, string $description): array => ['name' => $name, 'description' => $description],
                array_keys(self::DATABASES),
                self::DATABASES,
            ),
        ];
        $primary['connection'] = 'primary';
        $primary['error'] = null;
        $reporting = $primary;
        $reporting['connection'] = 'reporting';
        $reporting['engine'] = 'pgsql';
        $reporting['status']['connections'] = 3;
        $reporting['status']['running'] = 1;
        $reporting['status']['max_used_connections'] = null;
        $reporting['status']['refused_connections'] = null;
        $reporting['status']['aborted_connects'] = null;
        $reporting['databases'] = [['name' => 'analytics', 'description' => 'Reporting replica']];

        return ['connections' => [$primary, $reporting]];
    }

    /**
     * Node payload for `GET /databases/processes`, or null for an unmonitored database.
     *
     * @return null|array<string,mixed>
     */
    public function databaseProcesses(?string $database, string $connection): ?array
    {
        if (!in_array($connection, ['primary', 'reporting'], true)
            || ($connection === 'reporting' && $database !== null && $database !== 'analytics')
            || ($database !== null && !isset(self::DATABASES[$database]))) {
            return null;
        }

        $processes = array_values(array_filter(
            [
                ['id' => 301, 'user' => 'reporter', 'host' => '10.0.2.14:40112', 'database' => 'analytics', 'command' => 'Query', 'time_seconds' => 12, 'state' => 'Sending data', 'statement' => 'SELECT'],
                ['id' => 201, 'user' => 'storefront', 'host' => '10.0.1.21:51234', 'database' => 'ecommerce_prod', 'command' => 'Sleep', 'time_seconds' => 4, 'state' => '', 'statement' => null],
                ['id' => 202, 'user' => 'worker', 'host' => '10.0.1.30:51290', 'database' => 'ecommerce_prod', 'command' => 'Query', 'time_seconds' => 1, 'state' => 'Updating', 'statement' => 'UPDATE'],
                ['id' => 101, 'user' => 'dashboard', 'host' => 'localhost', 'database' => 'mata_dashboard', 'command' => 'Query', 'time_seconds' => 0, 'state' => 'executing', 'statement' => 'SELECT'],
            ],
            static fn (array $row): bool => ($database === null || $row['database'] === $database)
                && ($connection === 'primary' || $row['database'] === 'analytics'),
        ));

        $summary = [];
        foreach ($database === null ? array_keys(self::DATABASES) : [$database] as $name) {
            $rows = array_filter($processes, static fn (array $row): bool => $row['database'] === $name);
            if ($rows === [] && $database === null) {
                continue;
            }
            $summary[] = [
                'database' => $name,
                'connections' => count($rows),
                'running' => count(array_filter($rows, static fn (array $row): bool => $row['command'] !== 'Sleep')),
            ];
        }

        return [
            'engine' => $connection === 'primary' ? 'mariadb' : 'pgsql',
            'collected_at' => gmdate('c'),
            'connection' => $connection,
            'visibility' => 'all_sessions',
            'summary_by_database' => $summary,
            'processes' => $processes,
            'truncated' => false,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function cronjobs(): array
    {
        return [
            [
                'index' => 0,
                'path' => '/var/log/mata/cron/daily-report.log',
                'exists' => true,
                'mtime' => date('Y-m-d H:i:s', time() - 300),
                'age_seconds' => 300,
                'stale' => false,
                'size' => 2048,
                'content_tail' => [
                    '[2026-07-10 03:00:01] daily-report started',
                    '[2026-07-10 03:00:05] daily-report finished in 4.2s',
                ],
            ],
            [
                'index' => 1,
                'path' => '/var/log/mata/cron/cache-warmup.log',
                'exists' => true,
                'mtime' => date('Y-m-d H:i:s', time() - 93600),
                'age_seconds' => 93600,
                'stale' => true,
                'size' => 512,
                'content_tail' => [
                    '[2026-07-09 01:00:01] cache warmup started',
                    '[2026-07-09 01:00:02] cache warmup skipped: lock present',
                ],
            ],
        ];
    }
}
