<?php

declare(strict_types=1);

namespace App\Services\Prediction;

use App\Core\Config;
use App\Services\Binance\BinanceApiClient;
use App\Services\Strategy\IndicatorService;

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
        [$supportZoneLow, $supportZoneHigh] = $this->nearestSupportZone($analysis, $currentPrice);
        [$resistanceZoneLow, $resistanceZoneHigh] = $this->nearestResistanceZone($analysis, $currentPrice);
        $atrMove = $this->averageAtrMove($analysis, $currentPrice);

        $shortScenario = $this->buildShortScenario($currentPrice, $supportZoneLow, $supportZoneHigh, $resistanceZoneHigh, $atrMove);
        $longScenario = $this->buildLongScenario($currentPrice, $supportZoneLow, $resistanceZoneLow, $resistanceZoneHigh, $atrMove);
        $neutralScenario = $this->buildNeutralScenario($supportZoneHigh, $resistanceZoneLow);

        return [
            'symbol' => $symbol,
            'generated_at' => gmdate(DATE_ATOM),
            'current_price' => round($currentPrice, 8),
            'bias' => $bias,
            'confidence' => min(100, abs($directionScore)),
            'summary' => $this->buildSummary($bias, $shortScenario, $longScenario, $neutralScenario),
            'timeframes' => $analysis,
            'zones' => [
                'support' => [
                    'low' => $supportZoneLow,
                    'high' => $supportZoneHigh,
                ],
                'resistance' => [
                    'low' => $resistanceZoneLow,
                    'high' => $resistanceZoneHigh,
                ],
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

    private function directionScore(array $analysis): int
    {
        $weights = ['15m' => 20, '1h' => 35, '4h' => 45];
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

    private function nearestSupportZone(array $analysis, float $price): array
    {
        $levels = array_values(array_filter(
            array_map(static fn (array $row): ?float => $row['support'], $analysis),
            static fn (?float $value): bool => $value !== null
        ));

        if ($levels === []) {
            return [round($price * 0.96, 8), round($price * 0.98, 8)];
        }

        sort($levels);

        return [round((float) $levels[0], 8), round((float) $levels[array_key_last($levels)], 8)];
    }

    private function nearestResistanceZone(array $analysis, float $price): array
    {
        $levels = array_values(array_filter(
            array_map(static fn (array $row): ?float => $row['resistance'], $analysis),
            static fn (?float $value): bool => $value !== null
        ));

        if ($levels === []) {
            return [round($price * 1.02, 8), round($price * 1.04, 8)];
        }

        sort($levels);

        return [round((float) $levels[0], 8), round((float) $levels[array_key_last($levels)], 8)];
    }

    private function buildShortScenario(float $price, float $supportLow, float $supportHigh, float $resistanceHigh, float $atrMove): array
    {
        $target = min($supportHigh, $price - (1.8 * $atrMove));
        $stop = max($resistanceHigh, $price + (1.1 * $atrMove));

        return [
            'entry' => round($price, 8),
            'target_zone' => [
                'low' => round(min($supportLow, $target - ($atrMove * 0.4)), 8),
                'high' => round(max($supportHigh, $target), 8),
            ],
            'suggested_take_profit' => round($target, 8),
            'invalidation' => round($stop, 8),
            'reward_percent' => round($this->distancePercent($price, $target), 2),
            'risk_percent' => round($this->distancePercent($price, $stop), 2),
            'summary' => 'A bearish folytatás akkor erős, ha az ár gyorsulva közelít a támasz zóna felé.',
        ];
    }

    private function buildLongScenario(float $price, float $supportHigh, float $resistanceLow, float $resistanceHigh, float $atrMove): array
    {
        $target = max($resistanceLow, $price + (1.8 * $atrMove));
        $stop = min($supportHigh, $price - (1.1 * $atrMove));

        return [
            'entry' => round($price, 8),
            'target_zone' => [
                'low' => round(min($resistanceLow, $target), 8),
                'high' => round(max($resistanceHigh, $target + ($atrMove * 0.4)), 8),
            ],
            'suggested_take_profit' => round($target, 8),
            'invalidation' => round($stop, 8),
            'reward_percent' => round($this->distancePercent($price, $target), 2),
            'risk_percent' => round($this->distancePercent($price, $stop), 2),
            'summary' => 'A bullish folytatás akkor erős, ha az ár a közeli ellenállás fölé tud zárni.',
        ];
    }

    private function buildNeutralScenario(float $supportHigh, float $resistanceLow): array
    {
        return [
            'range_low' => $supportHigh,
            'range_high' => $resistanceLow,
            'summary' => 'Oldalazó piacban nincs tiszta irány, ilyenkor a jó kockázat/hozam arány fontosabb, mint maga a signal.',
        ];
    }

    private function buildSummary(string $bias, array $shortScenario, array $longScenario, array $neutralScenario): string
    {
        return match ($bias) {
            'BEARISH' => sprintf(
                'A fő forgatókönyv jelenleg bearish: a defenzív short take profit zóna nagyjából %s körül kereshető.',
                number_format((float) $shortScenario['suggested_take_profit'], 4, '.', ' ')
            ),
            'BULLISH' => sprintf(
                'A fő forgatókönyv jelenleg bullish: a defenzív long take profit zóna nagyjából %s körül kereshető.',
                number_format((float) $longScenario['suggested_take_profit'], 4, '.', ' ')
            ),
            default => sprintf(
                'A piac inkább oldalazó, a várt sáv nagyjából %s - %s között lehet.',
                number_format((float) $neutralScenario['range_low'], 4, '.', ' '),
                number_format((float) $neutralScenario['range_high'], 4, '.', ' ')
            ),
        };
    }

    private function distancePercent(float $from, float $to): float
    {
        return abs($this->indicators->percentChange($from, $to));
    }
}