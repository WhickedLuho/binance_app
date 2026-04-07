<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Core\Config;
use InvalidArgumentException;

final class AutoTradeSettingsService
{
    public function __construct(
        private readonly Config $config,
        private readonly AutoTradeSettingsRepository $repository
    ) {
    }

    public function show(): array
    {
        return $this->normalizeSettings($this->repository->load());
    }

    public function update(array $payload): array
    {
        $settings = $this->normalizeSettings($payload);
        $this->repository->save($settings);

        return $settings;
    }

    private function normalizeSettings(array $payload): array
    {
        $allowedPairs = array_values(array_unique(array_map(
            static fn (mixed $pair): string => strtoupper(trim((string) $pair)),
            (array) $this->config->get('pairs.pairs', [])
        )));

        $storedPairs = is_array($payload['pairs'] ?? null) ? $payload['pairs'] : [];
        $pairs = [];
        $manualTotal = 0.0;
        $autoEnabledCount = 0;

        foreach ($allowedPairs as $symbol) {
            $row = is_array($storedPairs[$symbol] ?? null) ? $storedPairs[$symbol] : [];
            $enabled = (bool) ($row['enabled'] ?? true);
            $manual = $this->nullablePercent($row['manual_allocation_percent'] ?? null);

            if ($enabled && $manual !== null) {
                $manualTotal += $manual;
            }

            if ($enabled && $manual === null) {
                $autoEnabledCount++;
            }

            $pairs[$symbol] = [
                'symbol' => $symbol,
                'enabled' => $enabled,
                'manual_allocation_percent' => $manual,
                'effective_allocation_percent' => 0.0,
                'capital_usdt' => 0.0,
            ];
        }

        if ($manualTotal > 100.0) {
            throw new InvalidArgumentException('Manual pair allocations cannot exceed 100%.');
        }

        $enabledPairs = array_values(array_filter($pairs, static fn (array $pair): bool => $pair['enabled']));
        $remainingPercent = max(0.0, 100.0 - $manualTotal);
        $autoShare = $autoEnabledCount > 0 ? $remainingPercent / $autoEnabledCount : 0.0;
        $remainingToDistribute = $remainingPercent;
        $remainingAutoSlots = $autoEnabledCount;

        foreach ($pairs as $symbol => $pair) {
            if (!$pair['enabled']) {
                continue;
            }

            if ($pair['manual_allocation_percent'] !== null) {
                $effective = $pair['manual_allocation_percent'];
            } else {
                $remainingAutoSlots--;
                $effective = $remainingAutoSlots === 0
                    ? $remainingToDistribute
                    : round($autoShare, 4);
                $remainingToDistribute = max(0.0, $remainingToDistribute - $effective);
            }

            $pairs[$symbol]['effective_allocation_percent'] = round($effective, 4);
        }

        $totalCapital = $this->positiveFloat($payload['total_capital_usdt'] ?? 100.0, 'Total capital');
        foreach ($pairs as $symbol => $pair) {
            $pairs[$symbol]['capital_usdt'] = round(($totalCapital * $pair['effective_allocation_percent']) / 100, 4);
        }

        return [
            'enabled' => (bool) ($payload['enabled'] ?? false),
            'total_capital_usdt' => round($totalCapital, 2),
            'max_open_positions' => max(1, (int) ($payload['max_open_positions'] ?? 3)),
            'default_position_type' => $this->enumValue(
                $payload['default_position_type'] ?? 'FUTURES_LONG',
                ['SPOT', 'FUTURES_LONG', 'FUTURES_SHORT'],
                'Default position type'
            ),
            'default_margin_type' => $this->enumValue(
                $payload['default_margin_type'] ?? 'ISOLATED',
                ['ISOLATED', 'CROSS'],
                'Default margin type'
            ),
            'default_leverage' => max(1, min((int) $this->config->get('paper.max_leverage', 20), (int) ($payload['default_leverage'] ?? 5))),
            'pairs' => $pairs,
            'summary' => [
                'enabled_pairs' => count($enabledPairs),
                'manual_total_percent' => round($manualTotal, 4),
                'auto_pairs' => $autoEnabledCount,
                'remaining_percent' => round($remainingPercent, 4),
                'allocated_percent' => round(array_sum(array_map(
                    static fn (array $pair): float => (float) $pair['effective_allocation_percent'],
                    $pairs
                )), 4),
            ],
        ];
    }

    private function nullablePercent(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $float = (float) $value;
        if ($float < 0 || $float > 100) {
            throw new InvalidArgumentException('Pair allocation percentages must be between 0 and 100.');
        }

        return round($float, 4);
    }

    private function positiveFloat(mixed $value, string $field): float
    {
        $float = (float) $value;
        if ($float <= 0) {
            throw new InvalidArgumentException($field . ' must be greater than zero.');
        }

        return $float;
    }

    private function enumValue(mixed $value, array $allowed, string $field): string
    {
        $normalized = strtoupper(trim((string) $value));
        if (!in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException($field . ' is invalid.');
        }

        return $normalized;
    }
}
