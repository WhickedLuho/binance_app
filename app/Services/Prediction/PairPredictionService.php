<?php

declare(strict_types=1);

namespace App\Services\Prediction;

use App\Core\Config;
use App\Services\Market\MarketSnapshotService;
use DateTimeImmutable;
use DateTimeZone;

final class PairPredictionService
{
    public function __construct(
        private readonly Config $config,
        private readonly MarketSnapshotService $snapshots
    ) {
    }

    public function predict(string $symbol): array
    {
        $timeframes = $this->predictionTimeframes();
        $limit = max(2, (int) $this->config->get('pairs.prediction_limit', $this->config->get('pairs.default_limit', 160)));
        $snapshot = $this->snapshots->buildSymbolSnapshot($symbol, $timeframes, $limit);

        $analysis = [];
        foreach ($timeframes as $timeframe) {
            $analysis[$timeframe] = $this->analyzeTimeframe(
                $timeframe,
                is_array($snapshot['timeframes'][$timeframe] ?? null) ? $snapshot['timeframes'][$timeframe] : []
            );
        }

        $quality = $this->summarizeQuality($analysis, $snapshot);
        $validAnalysis = $this->readyTimeframes($analysis);
        if ($validAnalysis === []) {
            return $this->insufficientPrediction($symbol, $analysis, $quality);
        }

        $primaryTimeframe = $timeframes[0] ?? null;
        $currentPrice = $primaryTimeframe !== null && !empty($analysis[$primaryTimeframe]['ready'])
            ? (float) $analysis[$primaryTimeframe]['price']
            : (float) reset($validAnalysis)['price'];

        $directionScore = $this->directionScore($validAnalysis);
        $bias = $this->resolveBias($directionScore);
        $supportZone = $this->buildZone($validAnalysis, $currentPrice, 'support');
        $resistanceZone = $this->buildZone($validAnalysis, $currentPrice, 'resistance');
        $atrMove = $this->averageAtrMove($validAnalysis, $currentPrice);

        $shortScenario = $this->buildShortScenario($currentPrice, $supportZone, $resistanceZone, $atrMove);
        $longScenario = $this->buildLongScenario($currentPrice, $supportZone, $resistanceZone, $atrMove);
        $neutralScenario = $this->buildNeutralScenario($currentPrice, $supportZone, $resistanceZone, $atrMove);
        $summary = $this->buildSummary($bias, $shortScenario, $longScenario, $neutralScenario);

        if (!$quality['ready']) {
            $summary = 'Partial market data. ' . $summary;
        }

        return [
            'symbol' => $symbol,
            'generated_at' => $this->nowAtom(),
            'current_price' => round($currentPrice, 8),
            'bias' => $bias,
            'confidence' => min(100, abs($directionScore)),
            'summary' => $summary,
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
            'data_quality' => $quality,
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

    private function analyzeTimeframe(string $timeframe, array $snapshot): array
    {
        $metrics = is_array($snapshot['metrics'] ?? null) ? $snapshot['metrics'] : [];
        $levels = is_array($snapshot['levels'] ?? null) ? $snapshot['levels'] : [];
        $quality = is_array($snapshot['data_quality'] ?? null) ? $snapshot['data_quality'] : ['ready' => false, 'issues' => ['Snapshot data unavailable']];
        $price = (float) ($snapshot['price'] ?? 0.0);
        $ema20 = $metrics['ema20'] ?? null;
        $ema50 = $metrics['ema50'] ?? null;
        $rsi = $metrics['rsi14'] ?? null;
        $atrPercent = $metrics['atr_percent'] ?? null;
        $support = $levels['support'] ?? null;
        $resistance = $levels['resistance'] ?? null;
        $momentum = (float) ($metrics['momentum_percent'] ?? 0.0);

        return [
            'price' => round($price, 8),
            'bias' => ($quality['ready'] ?? false) ? $this->timeframeBias($price, $ema20, $ema50, $rsi, $momentum) : 'UNAVAILABLE',
            'ema20' => $ema20,
            'ema50' => $ema50,
            'rsi14' => $rsi,
            'atr_percent' => $atrPercent,
            'support' => $support,
            'resistance' => $resistance,
            'momentum_percent' => $momentum,
            'support_levels' => $levels['support_levels'] ?? [],
            'resistance_levels' => $levels['resistance_levels'] ?? [],
            'ready' => (bool) ($quality['ready'] ?? false),
            'issues' => array_values(array_unique(array_map('strval', $quality['issues'] ?? []))),
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

    private function readyTimeframes(array $analysis): array
    {
        return array_filter($analysis, static fn (array $row): bool => !empty($row['ready']) && (float) ($row['price'] ?? 0.0) > 0.0);
    }

    private function summarizeQuality(array $analysis, array $snapshot): array
    {
        $issues = [];
        $readyCount = 0;

        foreach ($analysis as $row) {
            if (!empty($row['ready'])) {
                $readyCount++;
            }
            $issues = [...$issues, ...($row['issues'] ?? [])];
        }

        $issues = [...$issues, ...(is_array($snapshot['meta']['issues'] ?? null) ? $snapshot['meta']['issues'] : [])];
        $totalCount = count($analysis);

        return [
            'ready' => $totalCount > 0 && $readyCount === $totalCount,
            'ready_timeframes' => $readyCount,
            'total_timeframes' => $totalCount,
            'issues' => array_values(array_unique(array_map('strval', $issues))),
        ];
    }

    private function insufficientPrediction(string $symbol, array $analysis, array $quality): array
    {
        return [
            'symbol' => $symbol,
            'generated_at' => $this->nowAtom(),
            'current_price' => 0.0,
            'bias' => 'INSUFFICIENT_DATA',
            'confidence' => 0,
            'summary' => 'Prediction unavailable because the required closed market data is not ready yet.',
            'timeframes' => $analysis,
            'zones' => [
                'support' => null,
                'resistance' => null,
            ],
            'scenarios' => [
                'short' => ['summary' => 'Not enough data for a bearish scenario.'],
                'long' => ['summary' => 'Not enough data for a bullish scenario.'],
                'neutral' => ['summary' => 'Not enough data for a range scenario.'],
            ],
            'data_quality' => $quality,
        ];
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
        if ($from == 0.0) {
            return 0.0;
        }

        return abs((($to - $from) / $from) * 100);
    }

    private function nowAtom(): string
    {
        $timezone = (string) $this->config->get('app.timezone', 'UTC');

        return (new DateTimeImmutable('now', new DateTimeZone($timezone !== '' ? $timezone : 'UTC')))->format(DATE_ATOM);
    }
}
