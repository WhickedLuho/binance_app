<?php

declare(strict_types=1);

namespace App\Services\Strategy;

final class IndicatorService
{
    public function closes(array $candles): array
    {
        return array_map(static fn (array $candle): float => $candle['close'], $candles);
    }

    public function volumes(array $candles): array
    {
        return array_map(static fn (array $candle): float => $candle['volume'], $candles);
    }

    public function ema(array $values, int $period): ?float
    {
        if (count($values) < $period) {
            return null;
        }

        $multiplier = 2 / ($period + 1);
        $ema = array_sum(array_slice($values, 0, $period)) / $period;
        foreach (array_slice($values, $period) as $value) {
            $ema = (($value - $ema) * $multiplier) + $ema;
        }

        return $ema;
    }

    public function rsi(array $values, int $period = 14): ?float
    {
        if (count($values) <= $period) {
            return null;
        }

        $gains = 0.0;
        $losses = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $change = $values[$i] - $values[$i - 1];
            if ($change >= 0) {
                $gains += $change;
            } else {
                $losses += abs($change);
            }
        }

        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;

        for ($i = $period + 1, $count = count($values); $i < $count; $i++) {
            $change = $values[$i] - $values[$i - 1];
            $gain = max($change, 0);
            $loss = max(-$change, 0);
            $avgGain = (($avgGain * ($period - 1)) + $gain) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $loss) / $period;
        }

        if ($avgLoss == 0.0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;

        return 100 - (100 / (1 + $rs));
    }

    public function macd(array $values): array
    {
        if (count($values) < 35) {
            return ['macd' => null, 'signal' => null, 'histogram' => null];
        }

        $multiplier12 = 2 / (12 + 1);
        $multiplier26 = 2 / (26 + 1);
        $multiplier9 = 2 / (9 + 1);

        $ema12 = array_sum(array_slice($values, 0, 12)) / 12;
        $ema26 = array_sum(array_slice($values, 0, 26)) / 26;
        $macdSeries = [];

        foreach ($values as $index => $value) {
            if ($index >= 12) {
                $ema12 = (($value - $ema12) * $multiplier12) + $ema12;
            }

            if ($index >= 26) {
                $ema26 = (($value - $ema26) * $multiplier26) + $ema26;
                $macdSeries[] = $ema12 - $ema26;
            }
        }

        if (count($macdSeries) < 9) {
            return ['macd' => null, 'signal' => null, 'histogram' => null];
        }

        $signal = array_sum(array_slice($macdSeries, 0, 9)) / 9;
        foreach (array_slice($macdSeries, 9) as $value) {
            $signal = (($value - $signal) * $multiplier9) + $signal;
        }

        $macd = $macdSeries[array_key_last($macdSeries)];

        return [
            'macd' => $macd,
            'signal' => $signal,
            'histogram' => $macd - $signal,
        ];
    }

    public function atrPercent(array $candles, int $period = 14): ?float
    {
        if (count($candles) <= $period) {
            return null;
        }

        $ranges = [];
        for ($i = 1, $count = count($candles); $i < $count; $i++) {
            $current = $candles[$i];
            $previous = $candles[$i - 1];
            $ranges[] = max(
                $current['high'] - $current['low'],
                abs($current['high'] - $previous['close']),
                abs($current['low'] - $previous['close'])
            );
        }

        $atr = array_sum(array_slice($ranges, -$period)) / $period;
        $lastClose = $candles[array_key_last($candles)]['close'];

        return $lastClose > 0 ? ($atr / $lastClose) * 100 : null;
    }

    public function percentChange(float $from, float $to): float
    {
        if ($from == 0.0) {
            return 0.0;
        }

        return (($to - $from) / $from) * 100;
    }

    public function average(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }
}
