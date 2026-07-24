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
     * @return array<string,mixed>
     */
    public function serverDatabases(): array
    {
        return [
            'mysql_version' => '8.0.' . mt_rand(25, 35),
            'total_databases' => mt_rand(8, 25),
            'total_tables' => mt_rand(150, 500),
            'data_size_mb' => mt_rand(500, 5000),
            'index_size_mb' => mt_rand(100, 1200),
            'connections_active' => mt_rand(5, 45),
            'connections_max' => 150,
            'uptime_hours' => mt_rand(120, 1080),
            'queries_per_second' => mt_rand(10, 200),
            'processes' => ['sql' => mt_rand(12, 20)],
            'databases' => [
                'mata_dashboard' => [
                    'database' => 'mata_dashboard',
                    'configured_database' => 'mata_dashboard',
                    'host' => 'localhost',
                    'port' => 3306,
                    'description' => 'Dashboard state store',
                    'max_connections' => 150,
                    'active_threads' => mt_rand(2, 10),
                    'active_processes' => mt_rand(1, 8),
                    'process_list' => [
                        ['id' => 101, 'user' => 'dashboard', 'host' => 'localhost', 'db' => 'mata_dashboard', 'command' => 'Query', 'time' => 0, 'state' => 'executing', 'info' => 'SELECT'],
                    ],
                    'connection_error' => '',
                ],
                'ecommerce_prod' => [
                    'database' => 'ecommerce_prod',
                    'configured_database' => 'ecommerce_prod',
                    'host' => 'db-prod.internal',
                    'port' => 3306,
                    'description' => 'Production ecommerce database',
                    'max_connections' => 250,
                    'active_threads' => mt_rand(5, 20),
                    'active_processes' => mt_rand(3, 15),
                    'process_list' => [
                        ['id' => 201, 'user' => 'storefront', 'host' => 'web-01', 'db' => 'ecommerce_prod', 'command' => 'Sleep', 'time' => 4, 'state' => '', 'info' => null],
                        ['id' => 202, 'user' => 'worker', 'host' => 'queue-01', 'db' => 'ecommerce_prod', 'command' => 'Query', 'time' => 1, 'state' => 'executing', 'info' => 'UPDATE'],
                    ],
                    'connection_error' => '',
                ],
                'analytics' => [
                    'database' => 'analytics',
                    'configured_database' => 'analytics',
                    'host' => 'analytics-db.internal',
                    'port' => 3307,
                    'description' => 'Reporting and analytics warehouse',
                    'max_connections' => 100,
                    'active_threads' => mt_rand(1, 6),
                    'active_processes' => mt_rand(1, 5),
                    'process_list' => [
                        ['id' => 301, 'user' => 'reporter', 'host' => 'reports-01', 'db' => 'analytics', 'command' => 'Query', 'time' => 12, 'state' => 'Sending data', 'info' => 'SELECT'],
                    ],
                    'connection_error' => '',
                ],
                'legacy_reports' => [
                    'database' => 'legacy_reports',
                    'configured_database' => 'legacy_reports',
                    'host' => 'legacy-db.internal',
                    'port' => 3306,
                    'description' => 'Legacy reporting database',
                    'max_connections' => 50,
                    'active_threads' => 0,
                    'active_processes' => 0,
                    'process_list' => [],
                    'connection_error' => 'SQLSTATE[HY000] [2002] Connection refused',
                ],
            ],
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
