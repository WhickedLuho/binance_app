<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Core\Config;
use InvalidArgumentException;

final class AutoTradeSettingsService
{
    private const ENTRY_TYPES = ['FUTURES_LONG', 'FUTURES_SHORT', 'SPOT'];

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

        $enabledEntryTypes = $this->normalizeEntryTypes($payload);

        return [
            'enabled' => (bool) ($payload['enabled'] ?? false),
            'total_capital_usdt' => round($totalCapital, 2),
            'max_open_positions' => max(1, (int) ($payload['max_open_positions'] ?? 2)),
            'enabled_entry_types' => $enabledEntryTypes,
            'default_position_type' => $enabledEntryTypes[0],
            'default_margin_type' => $this->enumValue(
                $payload['default_margin_type'] ?? 'ISOLATED',
                ['ISOLATED', 'CROSS'],
                'Default margin type'
            ),
            'default_leverage' => max(1, min((int) $this->config->get('paper.max_leverage', 20), (int) ($payload['default_leverage'] ?? 4))),
            'min_profit_trigger_percent_spot' => $this->nonNegativeFloat($payload['min_profit_trigger_percent_spot'] ?? 0.8, 'Spot reward trigger'),
            'min_profit_trigger_percent_long' => $this->nonNegativeFloat($payload['min_profit_trigger_percent_long'] ?? 1.2, 'Long reward trigger'),
            'min_profit_trigger_percent_short' => $this->nonNegativeFloat($payload['min_profit_trigger_percent_short'] ?? 1.2, 'Short reward trigger'),
            'max_prediction_atr_percent' => $this->positiveFloat($payload['max_prediction_atr_percent'] ?? 3.0, 'Maximum prediction ATR'),
            'max_signal_candle_change_percent' => $this->positiveFloat($payload['max_signal_candle_change_percent'] ?? 1.0, 'Maximum candle change'),
            'cooldown_minutes' => max(0, (int) ($payload['cooldown_minutes'] ?? 20)),
            'close_on_take_profit' => (bool) ($payload['close_on_take_profit'] ?? true),
            'close_on_stop_loss' => (bool) ($payload['close_on_stop_loss'] ?? true),
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

    private function normalizeEntryTypes(array $payload): array
    {
        $requested = $payload['enabled_entry_types'] ?? null;
        if (is_array($requested)) {
            $normalized = [];
            foreach ($requested as $entryType) {
                $value = strtoupper(trim((string) $entryType));
                if ($value !== '' && in_array($value, self::ENTRY_TYPES, true) && !in_array($value, $normalized, true)) {
                    $normalized[] = $value;
                }
            }

            if ($normalized === []) {
                throw new InvalidArgumentException('Enable at least one entry type for automation.');
            }

            return $normalized;
        }

        $legacy = strtoupper(trim((string) ($payload['default_position_type'] ?? 'SPOT')));
        if (!in_array($legacy, self::ENTRY_TYPES, true)) {
            $legacy = 'SPOT';
        }

        return [$legacy];
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

        return round($float, 4);
    }

    private function nonNegativeFloat(mixed $value, string $field): float
    {
        $float = (float) $value;
        if ($float < 0) {
            throw new InvalidArgumentException($field . ' cannot be negative.');
        }

        return round($float, 4);
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

