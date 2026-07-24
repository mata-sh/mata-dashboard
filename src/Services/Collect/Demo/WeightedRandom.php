<?php

declare(strict_types=1);

namespace Mata\Services\Collect\Demo;

/**
 * Pure weighting/random helpers extracted from MockServerDataCollector's demo
 * app generation. Behavior is identical to the original inline closures.
 */
final class WeightedRandom
{
    /**
     * @param array<string,float> $weights
     */
    public function pickWeightedKey(array $weights): string
    {
        $filtered = [];
        foreach ($weights as $key => $weight) {
            if ($weight > 0) {
                $filtered[$key] = $weight;
            }
        }
        if (empty($filtered)) {
            $keys = array_keys($weights);

            return $keys[array_rand($keys)];
        }

        $sum = array_sum($filtered);
        $max = max(1, (int) round($sum * 1000));
        $rand = mt_rand(1, $max);
        $running = 0;

        foreach ($filtered as $key => $weight) {
            $running += (int) round($weight * 1000);
            if ($rand <= $running) {
                return $key;
            }
        }

        $keys = array_keys($filtered);

        return $keys[array_rand($keys)];
    }

    /**
     * @param array<string,float> $weights
     *
     * @return array<int,string>
     */
    public function selectWeightedUnique(array $weights, int $count): array
    {
        $selected = [];
        $pool = $weights;

        while (count($selected) < $count && !empty($pool)) {
            $choice = $this->pickWeightedKey($pool);
            $selected[] = $choice;
            unset($pool[$choice]);
        }

        return $selected;
    }

    /**
     * @param array{0:int,1:int} $range
     */
    public function randomFromRange(array $range, float $multiplier = 1.0): int
    {
        $min = max(0, (int) round($range[0] * $multiplier));
        $max = max($min, (int) round($range[1] * $multiplier));

        return $min === $max ? $min : mt_rand($min, $max);
    }

    /**
     * @param array<int,array<string,mixed>> $profile
     */
    public function rollProfile(array $profile): int
    {
        if (empty($profile)) {
            return 0;
        }

        $probabilities = [];
        foreach ($profile as $index => $entry) {
            $probabilities[$index] = max($entry['prob'] ?? 0, 0);
        }

        $sum = array_sum($probabilities);
        if ($sum <= 0) {
            $entry = reset($profile);
            [$min, $max] = $entry['range'];

            return $min >= $max ? $min : mt_rand($min, $max);
        }

        $rand = mt_rand(1, 1000);
        $cumulative = 0;

        foreach ($profile as $index => $entry) {
            $prob = $probabilities[$index];
            if ($prob <= 0) {
                continue;
            }

            $cumulative += (int) round($prob / $sum * 1000);
            if ($rand <= $cumulative) {
                [$min, $max] = $entry['range'];

                return $min >= $max ? $min : mt_rand($min, $max);
            }
        }

        $entry = end($profile);
        [$min, $max] = $entry['range'];

        return $min >= $max ? $min : mt_rand($min, $max);
    }
}
