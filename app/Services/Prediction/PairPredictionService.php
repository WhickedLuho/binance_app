<?php

declare(strict_types=1);

namespace App\Services\Prediction;

use App\Core\Config;
use App\Services\Binance\BinanceApiClient;
use App\Services\Strategy\IndicatorService;
use DateTimeImmutable;
use DateTimeZone;

final class PairPredictionService
{
    public function __construct(
        private readonly Config $config,
        private readonly BinanceApiClient $binance,
        private readonly IndicatorService $indicators
    ) {
    }

    public function predict(string $symbol): array
    {
        $timeframes = $this->predictionTimeframes();
        $limit = (int) $this->config->get('pairs.default_limit', 200);

        $analysis = [];
        foreach ($timeframes as $timeframe) {
            $candles = $this->binance->getKlines($symbol, $timeframe, $limit);
            $analysis[$timeframe] = $this->analyzeTimeframe($timeframe, $candles);
        }

        $currentPrice = (float) ($analysis[$timeframes[0]]['price'] ?? 0.0);
        $directionScore = $this->directionScore($analysis);
        $bias = $this->resolveBias($directionScore);
        $supportZone = $this->buildZone($analysis, $currentPrice, 'support');
        $resistanceZone = $this->buildZone($analysis, $currentPrice, 'resistance');
        $atrMove = $this->averageAtrMove($analysis, $currentPrice);

        $shortScenario = $this->buildShortScenario($currentPrice, $supportZone, $resistanceZone, $atrMove);
        $longScenario = $this->buildLongScenario($currentPrice, $supportZone, $resistanceZone, $atrMove);
        $neutralScenario = $this->buildNeutralScenario($currentPrice, $supportZone, $resistanceZone, $atrMove);

        return [
            'symbol' => $symbol,
            'generated_at' => $this->nowAtom(),
            'current_price' => round($currentPrice, 8),
            'bias' => $bias,
            'confidence' => min(100, abs($directionScore)),
            'summary' => $this->buildSummary($bias, $shortScenario, $longScenario, $neutralScenario),
            'timeframes' => $analysis,
            'zones' => [
                'support' => $supportZone,
                'resistance' => $resistanceZone,
            ],
            'scenarios' => [
                'short' => $shortScenario,
                'long' => $longScenario,
                'neutral' => $neutralScenario,
            ],
        ];
    }

    private function predictionTimeframes(): array
    {
        $configured = $this->config->get('pairs.prediction_timeframes', ['15m', '1h', '4h']);
        $timeframes = array_values(array_unique(array_map(
            static fn (mixed $timeframe): string => (string) $timeframe,
            is_array($configured) ? $configured : ['15m', '1h', '4h']
        )));

        return $timeframes === [] ? ['15m', '1h', '4h'] : $timeframes;
    }

    private function analyzeTimeframe(string $timeframe, array $candles): array
    {
        $closes = $this->indicators->closes($candles);
        $price = (float) ($candles[array_key_last($candles)]['close'] ?? 0.0);
        $ema20 = $this->indicators->ema($closes, 20);
        $ema50 = $this->indicators->ema($closes, 50);
        $rsi = $this->indicators->rsi($closes, 14);
        $atrPercent = $this->indicators->atrPercent($candles, 14);
        $support = $this->nearestLevel($candles, $price, 'low');
        $resistance = $this->nearestLevel($candles, $price, 'high');
        $momentum = $this->recentMomentum($closes);

        return [
            'price' => round($price, 8),
            'bias' => $this->timeframeBias($price, $ema20, $ema50, $rsi, $momentum),
            'ema20' => $ema20,
            'ema50' => $ema50,
            'rsi14' => $rsi,
            'atr_percent' => $atrPercent,
            'support' => $support,
            'resistance' => $resistance,
            'momentum_percent' => $momentum,
            'support_levels' => $this->extractPivotLevels($candles, $price, 'support'),
            'resistance_levels' => $this->extractPivotLevels($candles, $price, 'resistance'),
        ];
    }

    private function timeframeBias(float $price, ?float $ema20, ?float $ema50, ?float $rsi, float $momentum): string
    {
        $score = 0;

        if ($ema20 !== null && $ema50 !== null) {
            if ($price > $ema20 && $ema20 > $ema50) {
                $score += 2;
            }
            if ($price < $ema20 && $ema20 < $ema50) {
                $score -= 2;
            }
        }

        if ($rsi !== null) {
            if ($rsi >= 58) {
                $score += 1;
            }
            if ($rsi <= 42) {
                $score -= 1;
            }
        }

        if ($momentum > 0.7) {
            $score += 1;
        }
        if ($momentum < -0.7) {
            $score -= 1;
        }

        return $score >= 2 ? 'BULLISH' : ($score <= -2 ? 'BEARISH' : 'NEUTRAL');
    }

    private function recentMomentum(array $closes): float
    {
        if (count($closes) < 6) {
            return 0.0;
        }

        $slice = array_slice($closes, -6);

        return $this->indicators->percentChange((float) $slice[0], (float) $slice[array_key_last($slice)]);
    }

    private function nearestLevel(array $candles, float $price, string $field): ?float
    {
        $values = array_map(static fn (array $candle): float => (float) $candle[$field], array_slice($candles, -60));

        if ($values === []) {
            return null;
        }

        if ($field === 'low') {
            $candidates = array_values(array_filter($values, static fn (float $value): bool => $value <= $price));
            rsort($candidates);
        } else {
            $candidates = array_values(array_filter($values, static fn (float $value): bool => $value >= $price));
            sort($candidates);
        }

        return $candidates[0] ?? null;
    }

    private function extractPivotLevels(array $candles, float $price, string $type): array
    {
        if (count($candles) < 5) {
            return [];
        }

        $slice = array_slice($candles, -120);
        $levels = [];

        for ($index = 2, $count = count($slice) - 2; $index < $count; $index++) {
            $current = $slice[$index];
            $prevOne = $slice[$index - 1];
            $prevTwo = $slice[$index - 2];
            $nextOne = $slice[$index + 1];
            $nextTwo = $slice[$index + 2];

            if ($type === 'support') {
                $value = (float) $current['low'];
                $isPivot = $value <= (float) $prevOne['low']
                    && $value <= (float) $prevTwo['low']
                    && $value <= (float) $nextOne['low']
                    && $value <= (float) $nextTwo['low']
                    && $value <= $price;
            } else {
                $value = (float) $current['high'];
                $isPivot = $value >= (float) $prevOne['high']
                    && $value >= (float) $prevTwo['high']
                    && $value >= (float) $nextOne['high']
                    && $value >= (float) $nextTwo['high']
                    && $value >= $price;
            }

            if ($isPivot) {
                $levels[] = round($value, 8);
            }
        }

        return array_values(array_unique($levels));
    }

    private function directionScore(array $analysis): int
    {
        $weights = $this->timeframeWeights(array_keys($analysis));
        $score = 0;

        foreach ($analysis as $timeframe => $row) {
            $weight = $weights[$timeframe] ?? 25;
            $score += match ($row['bias']) {
                'BULLISH' => $weight,
                'BEARISH' => -$weight,
                default => 0,
            };
        }

        return $score;
    }

    private function timeframeWeights(array $timeframes): array
    {
        $durations = [];
        $total = 0;

        foreach ($timeframes as $timeframe) {
            $seconds = $this->timeframeSeconds((string) $timeframe);
            $durations[$timeframe] = $seconds;
            $total += $seconds;
        }

        if ($total <= 0) {
            $equal = $timeframes === [] ? 0 : (int) round(100 / count($timeframes));

            return array_fill_keys($timeframes, $equal);
        }

        $weights = [];
        foreach ($durations as $timeframe => $seconds) {
            $weights[$timeframe] = max(10, (int) round(($seconds / $total) * 100));
        }

        return $weights;
    }

    private function timeframeSeconds(string $timeframe): int
    {
        if (!preg_match('/^(\d+)(m|h|d|w)$/', $timeframe, $matches)) {
            return 0;
        }

        $value = (int) $matches[1];
        $unit = $matches[2];

        return match ($unit) {
            'm' => $value * 60,
            'h' => $value * 3600,
            'd' => $value * 86400,
            'w' => $value * 604800,
            default => 0,
        };
    }

    private function resolveBias(int $directionScore): string
    {
        if ($directionScore >= 35) {
            return 'BULLISH';
        }

        if ($directionScore <= -35) {
            return 'BEARISH';
        }

        return 'RANGE';
    }

    private function averageAtrMove(array $analysis, float $price): float
    {
        $percents = array_values(array_filter(
            array_map(static fn (array $row): ?float => $row['atr_percent'], $analysis),
            static fn (?float $value): bool => $value !== null
        ));

        if ($percents === []) {
            return $price * 0.015;
        }

        return $price * ((array_sum($percents) / count($percents)) / 100);
    }

    private function buildZone(array $analysis, float $price, string $type): array
    {
        $levels = [];

        foreach ($analysis as $row) {
            $singleLevel = $type === 'support' ? ($row['support'] ?? null) : ($row['resistance'] ?? null);
            if ($singleLevel !== null) {
                $levels[] = (float) $singleLevel;
            }

            $extraLevels = $type === 'support' ? ($row['support_levels'] ?? []) : ($row['resistance_levels'] ?? []);
            foreach ($extraLevels as $level) {
                $levels[] = (float) $level;
            }
        }

        $clusters = $this->clusterLevels($levels, $price);
        if ($clusters !== []) {
            usort($clusters, function (array $left, array $right) use ($price, $type): int {
                $leftReference = $type === 'support' ? $left['high'] : $left['low'];
                $rightReference = $type === 'support' ? $right['high'] : $right['low'];
                $leftDistance = abs($price - $leftReference);
                $rightDistance = abs($price - $rightReference);

                if ($leftDistance === $rightDistance) {
                    return $right['strength'] <=> $left['strength'];
                }

                return $leftDistance <=> $rightDistance;
            });

            $selected = $clusters[0];

            return [
                'low' => round((float) $selected['low'], 8),
                'high' => round((float) $selected['high'], 8),
                'strength' => (int) $selected['strength'],
            ];
        }

        return $type === 'support'
            ? [
                'low' => round($price * 0.96, 8),
                'high' => round($price * 0.98, 8),
                'strength' => 0,
            ]
            : [
                'low' => round($price * 1.02, 8),
                'high' => round($price * 1.04, 8),
                'strength' => 0,
            ];
    }

    private function clusterLevels(array $levels, float $price): array
    {
        $filtered = array_values(array_filter($levels, static fn (float $level): bool => $level > 0));
        if ($filtered === []) {
            return [];
        }

        sort($filtered);
        $tolerance = max($price * 0.0025, 0.00000001);
        $clusters = [];
        $current = null;

        foreach ($filtered as $level) {
            if ($current === null) {
                $current = ['low' => $level, 'high' => $level, 'levels' => [$level]];
                continue;
            }

            if (abs($level - $current['high']) <= $tolerance) {
                $current['high'] = $level;
                $current['levels'][] = $level;
                continue;
            }

            $clusters[] = $current;
            $current = ['low' => $level, 'high' => $level, 'levels' => [$level]];
        }

        if ($current !== null) {
            $clusters[] = $current;
        }

        return array_map(static function (array $cluster): array {
            $cluster['strength'] = count($cluster['levels']);
            unset($cluster['levels']);

            return $cluster;
        }, $clusters);
    }

    private function buildShortScenario(float $price, array $supportZone, array $resistanceZone, float $atrMove): array
    {
        $target = min((float) $supportZone['high'], $price - (1.6 * $atrMove));
        $stop = max((float) $resistanceZone['high'], $price + (1.15 * $atrMove));

        return [
            'entry' => round($price, 8),
            'target_zone' => [
                'low' => round(min((float) $supportZone['low'], $target - ($atrMove * 0.35)), 8),
                'high' => round(max((float) $supportZone['high'], $target), 8),
            ],
            'suggested_take_profit' => round($target, 8),
            'invalidation' => round($stop, 8),
            'reward_percent' => round($this->distancePercent($price, $target), 2),
            'risk_percent' => round($this->distancePercent($price, $stop), 2),
            'summary' => 'Bearish continuation is strongest when price drifts into fresh support and fails to reclaim the resistance cluster.',
        ];
    }

    private function buildLongScenario(float $price, array $supportZone, array $resistanceZone, float $atrMove): array
    {
        $target = max((float) $resistanceZone['low'], $price + (1.6 * $atrMove));
        $stop = min((float) $supportZone['low'], $price - (1.15 * $atrMove));

        return [
            'entry' => round($price, 8),
            'target_zone' => [
                'low' => round(min((float) $resistanceZone['low'], $target), 8),
                'high' => round(max((float) $resistanceZone['high'], $target + ($atrMove * 0.35)), 8),
            ],
            'suggested_take_profit' => round($target, 8),
            'invalidation' => round($stop, 8),
            'reward_percent' => round($this->distancePercent($price, $target), 2),
            'risk_percent' => round($this->distancePercent($price, $stop), 2),
            'summary' => 'Bullish continuation is strongest when price clears the nearest resistance cluster and holds above it.',
        ];
    }

    private function buildNeutralScenario(float $price, array $supportZone, array $resistanceZone, float $atrMove): array
    {
        $rangeLow = min((float) $supportZone['high'], $price - ($atrMove * 0.5));
        $rangeHigh = max((float) $resistanceZone['low'], $price + ($atrMove * 0.5));

        if ($rangeLow > $rangeHigh) {
            $rangeLow = $price - $atrMove;
            $rangeHigh = $price + $atrMove;
        }

        return [
            'range_low' => round($rangeLow, 8),
            'range_high' => round($rangeHigh, 8),
            'summary' => 'In range conditions, reactions between the zone edges matter more than the first breakout attempt.',
        ];
    }

    private function buildSummary(string $bias, array $shortScenario, array $longScenario, array $neutralScenario): string
    {
        return match ($bias) {
            'BEARISH' => sprintf(
                'Primary scenario is bearish, with the nearest target zone forming around %s.',
                number_format((float) $shortScenario['suggested_take_profit'], 4, '.', ' ')
            ),
            'BULLISH' => sprintf(
                'Primary scenario is bullish, with the nearest target zone forming around %s.',
                number_format((float) $longScenario['suggested_take_profit'], 4, '.', ' ')
            ),
            default => sprintf(
                'Market structure is mostly range-bound, with an expected band around %s - %s.',
                number_format((float) $neutralScenario['range_low'], 4, '.', ' '),
                number_format((float) $neutralScenario['range_high'], 4, '.', ' ')
            ),
        };
    }

    private function distancePercent(float $from, float $to): float
    {
        return abs($this->indicators->percentChange($from, $to));
    }

    private function nowAtom(): string
    {
        $timezone = (string) $this->config->get('app.timezone', 'UTC');

        return (new DateTimeImmutable('now', new DateTimeZone($timezone !== '' ? $timezone : 'UTC')))->format(DATE_ATOM);
    }
}
