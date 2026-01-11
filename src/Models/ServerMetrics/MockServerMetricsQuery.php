<?php

declare(strict_types=1);

namespace Mata\Models\ServerMetrics;

use DateInterval;
use DateTimeImmutable;
use Mata\Models\ServerMetrics\Dto\ServerMetricDailyAggregate;
use Mata\Models\ServerMetrics\Dto\ServerMetricHourlyAggregate;
use Mata\Models\ServerMetrics\Dto\ServerMetricRawSnapshot;

/**
 * Mock server metrics query for demo mode.
 *
 * Generates realistic historical metrics data for charts without requiring
 * a database. Creates time-series data with natural variations and trends.
 */
final class MockServerMetricsQuery implements ServerMetricsQueryInterface
{
    /** @var array<int,string> Demo server names */
    private array $demoServers = [
        'production-web',
        'database-server',
        'staging-server',
        'development-server',
        'backup-server',
        'load-balancer',
    ];

    public function getHourlyMetrics(
        string $serverName,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): array {
        $baseMetrics = $this->getBaseMetricsForServer($serverName);
        $results = [];

        $current = $from->setTime((int) $from->format('H'), 0, 0);
        $end = $to->setTime((int) $to->format('H'), 0, 0);

        while ($current <= $end) {
            $hourOfDay = (int) $current->format('H');
            $dayOfWeek = (int) $current->format('N');

            // Apply time-based variations
            $loadMultiplier = $this->getLoadMultiplier($hourOfDay, $dayOfWeek);

            $cpuBase = $baseMetrics['cpu_base'] * $loadMultiplier;
            $ramBase = $baseMetrics['ram_used_base'];
            $diskBase = $baseMetrics['disk_used_base'];

            // Add realistic variations
            $cpuAvg = $this->clamp($cpuBase + $this->randomVariation(5), 0, 100);
            $ramPercentAvg = $this->clamp($baseMetrics['ram_percent_base'] + $this->randomVariation(3), 0, 100);
            $diskPercentAvg = $this->clamp($baseMetrics['disk_percent_base'] + $this->randomVariation(1), 0, 100);

            // Sample count varies by time of day
            $sampleCount = max(1, (int) round(12 * $loadMultiplier));

            $results[] = new ServerMetricHourlyAggregate(
                $current,
                round($cpuAvg, 2),
                round(max(0, $cpuAvg - mt_rand(3, 8)), 2),
                round(min(100, $cpuAvg + mt_rand(5, 15)), 2),
                (int) round($ramBase * $ramPercentAvg / 100),
                (int) round($ramBase * max(0, $ramPercentAvg - 5) / 100),
                (int) round($ramBase * min(100, $ramPercentAvg + 5) / 100),
                round($ramPercentAvg, 2),
                round(min(100, $ramPercentAvg + mt_rand(2, 8)), 2),
                (int) round($diskBase * $diskPercentAvg / 100),
                (int) round($diskBase * max(0, $diskPercentAvg - 2) / 100),
                (int) round($diskBase * min(100, $diskPercentAvg + 2) / 100),
                round($diskPercentAvg, 2),
                round(min(100, $diskPercentAvg + mt_rand(1, 3)), 2),
                mt_rand(180000, 220000),
                mt_rand(25, 60),
                mt_rand(40, 80),
                $sampleCount,
                $current,
                $current->add(new DateInterval('PT59M'))
            );

            $current = $current->add(new DateInterval('PT1H'));
        }

        return $results;
    }

    public function getDailyMetrics(
        string $serverName,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): array {
        $baseMetrics = $this->getBaseMetricsForServer($serverName);
        $results = [];

        $current = $from->setTime(0, 0, 0);
        $end = $to->setTime(0, 0, 0);

        while ($current <= $end) {
            $dayOfWeek = (int) $current->format('N');

            // Weekends have different patterns
            $isWeekend = $dayOfWeek >= 6;
            $loadMultiplier = $isWeekend ? 0.6 : 1.0;

            $cpuBase = $baseMetrics['cpu_base'] * $loadMultiplier;
            $ramBase = $baseMetrics['ram_used_base'];
            $diskBase = $baseMetrics['disk_used_base'];

            $cpuAvg = $this->clamp($cpuBase + $this->randomVariation(8), 0, 100);
            $ramPercentAvg = $this->clamp($baseMetrics['ram_percent_base'] + $this->randomVariation(5), 0, 100);
            $diskPercentAvg = $this->clamp($baseMetrics['disk_percent_base'] + $this->randomVariation(2), 0, 100);

            // More samples on weekdays
            $sampleCount = $isWeekend ? mt_rand(150, 200) : mt_rand(250, 300);

            // Alarm and warning patterns
            $alarmCount = $this->generateAlarmCount($cpuAvg, $ramPercentAvg, $isWeekend);
            $warningCount = $this->generateWarningCount($cpuAvg, $ramPercentAvg, $isWeekend);

            $results[] = new ServerMetricDailyAggregate(
                $current,
                round($cpuAvg, 2),
                round(max(0, $cpuAvg - mt_rand(10, 20)), 2),
                round(min(100, $cpuAvg + mt_rand(15, 30)), 2),
                (int) round($ramBase * $ramPercentAvg / 100),
                (int) round($ramBase * max(0, $ramPercentAvg - 10) / 100),
                (int) round($ramBase * min(100, $ramPercentAvg + 8) / 100),
                round($ramPercentAvg, 2),
                round(min(100, $ramPercentAvg + mt_rand(5, 15)), 2),
                (int) round($diskBase * $diskPercentAvg / 100),
                (int) round($diskBase * max(0, $diskPercentAvg - 3) / 100),
                (int) round($diskBase * min(100, $diskPercentAvg + 5) / 100),
                round($diskPercentAvg, 2),
                round(min(100, $diskPercentAvg + mt_rand(2, 6)), 2),
                mt_rand(185000, 215000),
                mt_rand(220000, 250000),
                mt_rand(30, 55),
                mt_rand(50, 95),
                $sampleCount,
                $alarmCount,
                $warningCount
            );

            $current = $current->add(new DateInterval('P1D'));
        }

        return $results;
    }

    public function getRawMetrics(
        string $serverName,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $limit = 0
    ): array {
        $baseMetrics = $this->getBaseMetricsForServer($serverName);
        $results = [];

        // Generate samples every 5 minutes
        $current = $from;
        $maxCount = $limit > 0 ? $limit : 1000; // Default max to prevent memory issues
        $count = 0;

        while ($current <= $to && $count < $maxCount) {
            $hourOfDay = (int) $current->format('H');
            $dayOfWeek = (int) $current->format('N');

            $loadMultiplier = $this->getLoadMultiplier($hourOfDay, $dayOfWeek);

            $cpuUsage = $this->clamp($baseMetrics['cpu_base'] * $loadMultiplier + $this->randomVariation(10), 0, 100);
            $ramPercent = $this->clamp($baseMetrics['ram_percent_base'] + $this->randomVariation(5), 0, 100);
            $diskPercent = $this->clamp($baseMetrics['disk_percent_base'] + $this->randomVariation(1), 0, 100);

            $ramTotal = $baseMetrics['ram_used_base'];
            $diskTotal = $baseMetrics['disk_used_base'];

            $results[] = new ServerMetricRawSnapshot(
                $current,
                $current->add(new DateInterval('PT1S')),
                round($cpuUsage, 2),
                (int) round($ramTotal * $ramPercent / 100),
                $ramTotal,
                (int) round($diskTotal * $diskPercent / 100),
                $diskTotal,
                round($ramPercent, 2),
                round($diskPercent, 2),
                mt_rand(185000, 215000),
                mt_rand(30, 60),
                mt_rand(5, 15),
                'PHP 8.' . mt_rand(1, 3) . '.' . mt_rand(0, 20),
                'Ubuntu ' . mt_rand(20, 22) . '.04 LTS',
                mt_rand(86400 * 5, 86400 * 60) // 5-60 days uptime
            );

            $current = $current->add(new DateInterval('PT5M'));
            ++$count;
        }

        return $results;
    }

    public function getAvailableServers(): array
    {
        return $this->demoServers;
    }

    /**
     * Get base metrics configuration for a server type.
     *
     * @return array<string,float|int>
     */
    private function getBaseMetricsForServer(string $serverName): array
    {
        $metrics = [
            'production-web' => [
                'cpu_base' => 45.0,
                'ram_used_base' => 16384,
                'ram_percent_base' => 73.0,
                'disk_used_base' => 500000,
                'disk_percent_base' => 56.0,
            ],
            'database-server' => [
                'cpu_base' => 30.0,
                'ram_used_base' => 32768,
                'ram_percent_base' => 73.0,
                'disk_used_base' => 2000000,
                'disk_percent_base' => 60.0,
            ],
            'staging-server' => [
                'cpu_base' => 15.0,
                'ram_used_base' => 8192,
                'ram_percent_base' => 55.0,
                'disk_used_base' => 200000,
                'disk_percent_base' => 42.5,
            ],
            'development-server' => [
                'cpu_base' => 25.0,
                'ram_used_base' => 8192,
                'ram_percent_base' => 63.5,
                'disk_used_base' => 250000,
                'disk_percent_base' => 50.0,
            ],
            'backup-server' => [
                'cpu_base' => 10.0,
                'ram_used_base' => 16384,
                'ram_percent_base' => 36.6,
                'disk_used_base' => 5000000,
                'disk_percent_base' => 70.0,
            ],
            'load-balancer' => [
                'cpu_base' => 20.0,
                'ram_used_base' => 4096,
                'ram_percent_base' => 68.4,
                'disk_used_base' => 100000,
                'disk_percent_base' => 35.0,
            ],
        ];

        return $metrics[$serverName] ?? $metrics['production-web'];
    }

    /**
     * Calculate load multiplier based on time of day and day of week.
     */
    private function getLoadMultiplier(int $hour, int $dayOfWeek): float
    {
        // Weekend reduction
        $weekendFactor = $dayOfWeek >= 6 ? 0.5 : 1.0;

        // Business hours (9-18) have higher load
        if ($hour >= 9 && $hour <= 18) {
            return 1.2 * $weekendFactor;
        }

        // Early morning (0-6) has lower load
        if ($hour >= 0 && $hour <= 6) {
            return 0.4 * $weekendFactor;
        }

        // Evening (19-23) has moderate load
        return 0.8 * $weekendFactor;
    }

    /**
     * Generate random variation around zero.
     */
    private function randomVariation(float $magnitude): float
    {
        return (mt_rand(-100, 100) / 100.0) * $magnitude;
    }

    /**
     * Clamp value between min and max.
     */
    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    /**
     * Generate realistic alarm count based on metrics.
     */
    private function generateAlarmCount(float $cpuAvg, float $ramAvg, bool $isWeekend): int
    {
        // Higher metrics = more likely to have alarms
        $alarmProbability = 0.0;

        if ($cpuAvg > 80) {
            $alarmProbability += 0.3;
        }
        if ($ramAvg > 85) {
            $alarmProbability += 0.25;
        }

        // Weekends have fewer alarms
        if ($isWeekend) {
            $alarmProbability *= 0.4;
        }

        if (mt_rand(0, 100) < ($alarmProbability * 100)) {
            return mt_rand(1, 3);
        }

        return 0;
    }

    /**
     * Generate realistic warning count based on metrics.
     */
    private function generateWarningCount(float $cpuAvg, float $ramAvg, bool $isWeekend): int
    {
        // More lenient than alarms
        $warningProbability = 0.0;

        if ($cpuAvg > 60) {
            $warningProbability += 0.4;
        }
        if ($ramAvg > 70) {
            $warningProbability += 0.35;
        }

        if ($isWeekend) {
            $warningProbability *= 0.6;
        }

        if (mt_rand(0, 100) < ($warningProbability * 100)) {
            return mt_rand(1, 8);
        }

        return mt_rand(0, 2);
    }
}
