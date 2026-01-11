<?php

declare(strict_types=1);

namespace Mata\Services\Foundation\DataTransformers;

/**
 * Chart data transformation service for visualization formatting.
 *
 * Handles chart-specific transformations:
 * - Series data alignment across multiple servers
 * - Chart.js dataset entry formatting
 * - Server statistics calculation
 * - Color transformations (hex to rgba)
 */
class ChartDataTransformer
{
    /**
     * Align series data to expected labels for multi-server charts.
     *
     * Ensures all server datasets have values at the same timestamps,
     * filling missing values with null.
     *
     * @param array<int,string>         $expectedLabels Target timestamp labels
     * @param array<int,string>         $seriesLabels   Actual series timestamp labels
     * @param array<int,null|float|int> $seriesData     Actual series data values
     *
     * @return array<int,null|float|int> Aligned data matching expected labels
     */
    public function alignSeriesData(array $expectedLabels, array $seriesLabels, array $seriesData): array
    {
        if ($expectedLabels === []) {
            return $seriesData;
        }

        if ($expectedLabels === $seriesLabels) {
            return $seriesData;
        }

        $seriesMap = [];
        foreach ($seriesLabels as $index => $label) {
            $seriesMap[$label] = $seriesData[$index] ?? null;
        }

        $aligned = [];
        foreach ($expectedLabels as $label) {
            $aligned[] = $seriesMap[$label] ?? null;
        }

        return $aligned;
    }

    /**
     * Build Chart.js dataset entry for a server.
     *
     * Formats data with visual styling for line charts including:
     * - Border and background colors
     * - Point styling and hover effects
     * - Line tension and gap handling
     *
     * @param string                    $serverName  Server identifier
     * @param string                    $serverLabel Display label for server
     * @param array<int,null|float|int> $alignedData Aligned data series
     * @param string                    $color       Primary color for this server (hex format)
     *
     * @return array<string,mixed> Chart.js dataset configuration
     */
    public function buildDatasetEntry(
        string $serverName,
        string $serverLabel,
        array $alignedData,
        string $color
    ): array {
        return [
            'server' => $serverName,
            'label' => $serverLabel,
            'data' => $alignedData,
            'borderColor' => $color,
            'backgroundColor' => $this->hexToRgba($color, 0.18),
            'fill' => false,
            'tension' => 0.32,
            'spanGaps' => true,
            'pointRadius' => 2,
            'pointHoverRadius' => 4,
            'pointBackgroundColor' => '#ffffff',
            'borderWidth' => 2,
        ];
    }

    /**
     * Build server statistics summary.
     *
     * Calculates current, average, min, and max values for display.
     *
     * @param string                    $serverName  Server identifier
     * @param string                    $serverLabel Display label for server
     * @param array<string,mixed>       $series      Time series data with statistics
     * @param array<int,null|float|int> $alignedData Aligned data series
     *
     * @return array<string,mixed> Statistics summary
     */
    public function buildServerStats(
        string $serverName,
        string $serverLabel,
        array $series,
        array $alignedData
    ): array {
        return [
            'name' => $serverName,
            'label' => $serverLabel,
            'current' => $this->getLatestValue($alignedData),
            'avg' => $series['avg'] ?? null,
            'min' => $series['min'] ?? null,
            'max' => $series['max'] ?? null,
        ];
    }

    /**
     * Convert hex color to rgba format with alpha channel.
     *
     * @param string $hexColor Hex color code (e.g., "#3273dc")
     * @param float  $alpha    Alpha transparency (0.0 to 1.0)
     *
     * @return string RGBA color string (e.g., "rgba(50, 115, 220, 0.180)")
     */
    public function hexToRgba(string $hexColor, float $alpha): string
    {
        $hex = ltrim($hexColor, '#');
        if (strlen($hex) !== 6) {
            return sprintf('rgba(%d, %d, %d, %.3f)', 50, 115, 220, $alpha);
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return sprintf('rgba(%d, %d, %d, %.3f)', $r, $g, $b, $alpha);
    }

    /**
     * Get the latest non-null value from a series.
     *
     * Searches backwards from the end of the series to find
     * the most recent valid data point.
     *
     * @param array<int,null|float|int> $series Data series
     *
     * @return null|float|int Latest value or null if all values are null
     */
    private function getLatestValue(array $series): float|int|null
    {
        for ($index = count($series) - 1; $index >= 0; --$index) {
            $value = $series[$index] ?? null;
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
