<?php

declare(strict_types=1);

namespace App\Services\Market;

use App\Core\Config;
use App\Services\Binance\BinanceApiClient;
use App\Services\Strategy\IndicatorService;
use App\Services\Strategy\RiskFilterService;

final class MarketSnapshotService
{
    public function __construct(
        private readonly Config $config,
        private readonly BinanceApiClient $binance,
        private readonly IndicatorService $indicators,
        private readonly RiskFilterService $riskFilter
    ) {
    }

    public function buildSymbolSnapshot(string $symbol, ?array $requestedTimeframes = null, ?int $limit = null): array
    {
        $snapshots = $this->buildSymbolSnapshots([$symbol], $requestedTimeframes, $limit);
        $symbol = strtoupper($symbol);

        return $snapshots[$symbol] ?? $this->emptySymbolSnapshot($symbol, $this->resolveTimeframes($requestedTimeframes), $this->resolveLimit($limit));
    }

    public function buildSymbolSnapshots(array $symbols, ?array $requestedTimeframes = null, ?int $limit = null): array
    {
        $symbols = array_values(array_unique(array_filter(array_map(
            static fn (mixed $symbol): string => strtoupper(trim((string) $symbol)),
            $symbols
        ), static fn (string $symbol): bool => $symbol !== '')));
        $timeframes = $this->resolveTimeframes($requestedTimeframes);
        $limit = $this->resolveLimit($limit);

        if ($symbols === []) {
            return [];
        }

        $requests = [];
        foreach ($symbols as $symbol) {
            foreach ($timeframes as $timeframe) {
                $requests[$this->requestKey($symbol, $timeframe)] = [
                    'symbol' => $symbol,
                    'interval' => $timeframe,
                    'limit' => $limit,
                ];
            }
        }

        $responses = $this->binance->getKlinesBatch($requests);
        $snapshots = [];

        foreach ($symbols as $symbol) {
            $issues = [];
            $timeframeSnapshots = [];

            foreach ($timeframes as $timeframe) {
                $response = $responses[$this->requestKey($symbol, $timeframe)] ?? ['ok' => false, 'error' => 'Missing batch response.'];

                if (!($response['ok'] ?? false)) {
                    $error = (string) ($response['error'] ?? 'Unknown market data error.');
                    $timeframeSnapshots[$timeframe] = $this->buildUnavailableSnapshot($timeframe, $error);
                    $issues[] = sprintf('%s: %s', $timeframe, $error);
                    continue;
                }

                $timeframeSnapshots[$timeframe] = $this->buildTimeframeSnapshot($timeframe, (array) ($response['data'] ?? []));
                foreach (($timeframeSnapshots[$timeframe]['data_quality']['issues'] ?? []) as $issue) {
                    $issues[] = sprintf('%s: %s', $timeframe, (string) $issue);
                }
            }

            $snapshots[$symbol] = [
                'symbol' => $symbol,
                'timeframes' => $timeframeSnapshots,
                'meta' => [
                    'limit' => $limit,
                    'timeframes' => $timeframes,
                    'issues' => array_values(array_unique($issues)),
                ],
            ];
        }

        return $snapshots;
    }

    public function signalTimeframes(): array
    {
        $timeframes = array_merge(
            $this->analysisTimeframes(),
            [
                (string) $this->config->get('pairs.decision_timeframe', '15m'),
                (string) $this->config->get('pairs.confirmation_timeframe', '1h'),
                (string) $this->config->get('pairs.trigger_timeframe', '5m'),
            ]
        );

        return $this->normalizeTimeframes($timeframes, ['5m', '15m', '1h']);
    }

    public function predictionTimeframes(): array
    {
        return $this->normalizeTimeframes(
            $this->config->get('pairs.prediction_timeframes', ['15m', '1h', '4h']),
            ['15m', '1h', '4h']
        );
    }

    public function analysisTimeframes(): array
    {
        return $this->normalizeTimeframes(
            $this->config->get('pairs.analysis_timeframes', [$this->config->get('pairs.default_interval', '15m')]),
            [(string) $this->config->get('pairs.default_interval', '15m')]
        );
    }

    private function resolveTimeframes(?array $requestedTimeframes): array
    {
        return $this->normalizeTimeframes($requestedTimeframes, $this->signalTimeframes());
    }

    private function resolveLimit(?int $limit): int
    {
        return max(2, $limit ?? (int) $this->config->get('pairs.default_limit', 200));
    }

    private function normalizeTimeframes(mixed $configured, array $fallback): array
    {
        $source = is_array($configured) ? $configured : $fallback;
        $timeframes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $timeframe): string => trim((string) $timeframe),
            $source
        ), static fn (string $timeframe): bool => $timeframe !== '')));

        return $timeframes === [] ? $fallback : $timeframes;
    }

    private function buildTimeframeSnapshot(string $timeframe, array $candles): array
    {
        $sourceCount = count($candles);
        $prepared = $this->prepareClosedCandles($candles);
        $closedCandles = $prepared['candles'];
        $excludedLiveCandle = $prepared['excluded_live_candle'];
        $issues = [];

        if ($excludedLiveCandle) {
            $issues[] = 'Live candle excluded from calculations';
        }

        if (count($closedCandles) < 2) {
            return $this->buildInsufficientSnapshot(
                $timeframe,
                'Not enough closed candle data',
                $sourceCount,
                count($closedCandles),
                $excludedLiveCandle,
                $issues
            );
        }

        $closes = $this->indicators->closes($closedCandles);
        $volumes = $this->indicators->volumes($closedCandles);
        $lastCandle = $closedCandles[array_key_last($closedCandles)];
        $price = (float) $lastCandle['close'];
        $closeTime = isset($lastCandle['close_time']) ? (int) $lastCandle['close_time'] : 0;
        $candleAgeSeconds = $closeTime > 0 ? max(0, (int) floor((microtime(true) * 1000 - $closeTime) / 1000)) : null;
        $avgVolume = $this->indicators->average(array_slice($volumes, -20));
        $recentChange = $this->indicators->percentChange((float) $closedCandles[count($closedCandles) - 2]['close'], $price);
        $lookback = array_slice($closes, -10);
        $structureMove = $lookback !== []
            ? $this->indicators->percentChange((float) $lookback[0], (float) $lookback[array_key_last($lookback)])
            : 0.0;
        $macd = $this->indicators->macd($closes);

        $metrics = [
            'ema20' => $this->indicators->ema($closes, 20),
            'ema50' => $this->indicators->ema($closes, 50),
            'ema200' => $this->indicators->ema($closes, 200),
            'rsi14' => $this->indicators->rsi($closes, 14),
            'macd_histogram' => $macd['histogram'],
            'atr_percent' => $this->indicators->atrPercent($closedCandles, 14),
            'volume_ratio' => $avgVolume > 0 ? ((float) $lastCandle['volume'] / $avgVolume) : 0.0,
            'last_candle_change' => $recentChange,
            'structure_move' => $structureMove,
            'momentum_percent' => $this->recentMomentum($closes),
            'candle_age_seconds' => $candleAgeSeconds,
        ];

        $risk = $this->riskFilter->evaluate([
            'atr_percent' => $metrics['atr_percent'],
            'volume_ratio' => $metrics['volume_ratio'],
            'last_candle_change' => $metrics['last_candle_change'],
            'candle_age_seconds' => $metrics['candle_age_seconds'],
        ]);

        return [
            'timeframe' => $timeframe,
            'price' => round($price, 8),
            'metrics' => $metrics,
            'levels' => [
                'support' => $this->nearestLevel($closedCandles, $price, 'low'),
                'resistance' => $this->nearestLevel($closedCandles, $price, 'high'),
                'support_levels' => $this->extractPivotLevels($closedCandles, $price, 'support'),
                'resistance_levels' => $this->extractPivotLevels($closedCandles, $price, 'resistance'),
            ],
            'risk' => $risk,
            'data_quality' => [
                'ready' => true,
                'issues' => $issues,
                'source_candles' => $sourceCount,
                'closed_candles' => count($closedCandles),
                'excluded_live_candle' => $excludedLiveCandle,
            ],
        ];
    }

    private function prepareClosedCandles(array $candles): array
    {
        if ($candles === []) {
            return [
                'candles' => [],
                'excluded_live_candle' => false,
            ];
        }

        $closedCandles = $candles;
        $lastCandle = $candles[array_key_last($candles)];
        $closeTime = isset($lastCandle['close_time']) ? (int) $lastCandle['close_time'] : 0;
        $nowMs = (int) floor(microtime(true) * 1000);
        $excludedLiveCandle = $closeTime > $nowMs;

        if ($excludedLiveCandle) {
            array_pop($closedCandles);
        }

        return [
            'candles' => $closedCandles,
            'excluded_live_candle' => $excludedLiveCandle,
        ];
    }

    private function buildUnavailableSnapshot(string $timeframe, string $error): array
    {
        return [
            'timeframe' => $timeframe,
            'price' => 0.0,
            'metrics' => [
                'ema20' => null,
                'ema50' => null,
                'ema200' => null,
                'rsi14' => null,
                'macd_histogram' => null,
                'atr_percent' => null,
                'volume_ratio' => null,
                'last_candle_change' => null,
                'structure_move' => null,
                'momentum_percent' => 0.0,
                'candle_age_seconds' => null,
            ],
            'levels' => [
                'support' => null,
                'resistance' => null,
                'support_levels' => [],
                'resistance_levels' => [],
            ],
            'risk' => [
                'allowed' => false,
                'flags' => ['Market data unavailable'],
            ],
            'data_quality' => [
                'ready' => false,
                'issues' => [$error],
                'source_candles' => 0,
                'closed_candles' => 0,
                'excluded_live_candle' => false,
            ],
        ];
    }

    private function buildInsufficientSnapshot(
        string $timeframe,
        string $primaryIssue,
        int $sourceCount,
        int $closedCount,
        bool $excludedLiveCandle,
        array $issues = []
    ): array {
        $allIssues = array_values(array_unique([$primaryIssue, ...$issues]));

        return [
            'timeframe' => $timeframe,
            'price' => 0.0,
            'metrics' => [
                'ema20' => null,
                'ema50' => null,
                'ema200' => null,
                'rsi14' => null,
                'macd_histogram' => null,
                'atr_percent' => null,
                'volume_ratio' => null,
                'last_candle_change' => null,
                'structure_move' => null,
                'momentum_percent' => 0.0,
                'candle_age_seconds' => null,
            ],
            'levels' => [
                'support' => null,
                'resistance' => null,
                'support_levels' => [],
                'resistance_levels' => [],
            ],
            'risk' => [
                'allowed' => false,
                'flags' => ['Not enough candle data'],
            ],
            'data_quality' => [
                'ready' => false,
                'issues' => $allIssues,
                'source_candles' => $sourceCount,
                'closed_candles' => $closedCount,
                'excluded_live_candle' => $excludedLiveCandle,
            ],
        ];
    }

    private function emptySymbolSnapshot(string $symbol, array $timeframes, int $limit): array
    {
        $timeframeSnapshots = [];
        foreach ($timeframes as $timeframe) {
            $timeframeSnapshots[$timeframe] = $this->buildUnavailableSnapshot($timeframe, 'Snapshot data missing.');
        }

        return [
            'symbol' => $symbol,
            'timeframes' => $timeframeSnapshots,
            'meta' => [
                'limit' => $limit,
                'timeframes' => $timeframes,
                'issues' => ['Snapshot data missing.'],
            ],
        ];
    }

    private function requestKey(string $symbol, string $timeframe): string
    {
        return $symbol . '|' . $timeframe;
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
}
