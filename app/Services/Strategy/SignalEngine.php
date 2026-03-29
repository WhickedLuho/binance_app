<?php

declare(strict_types=1);

namespace App\Services\Strategy;

final class SignalEngine
{
    public function __construct(
        private readonly array $strategyConfig,
        private readonly array $pairsConfig,
        private readonly IndicatorService $indicators,
        private readonly RiskFilterService $riskFilter
    ) {
    }

    public function analyze(string $symbol, string $decisionTimeframe, array $candlesByTimeframe): array
    {
        $timeframeAnalysis = [];
        foreach ($candlesByTimeframe as $timeframe => $candles) {
            $timeframeAnalysis[$timeframe] = $this->analyzeTimeframe((string) $timeframe, $candles);
        }

        $decision = $timeframeAnalysis[$decisionTimeframe] ?? reset($timeframeAnalysis);
        if (!is_array($decision)) {
            return $this->emptySignal($symbol, $decisionTimeframe, 'Nem érhető el idősík-elemzés');
        }

        $triggerTimeframe = $this->firstConfiguredTimeframe(
            $timeframeAnalysis,
            [(string) ($this->pairsConfig['trigger_timeframe'] ?? '1m')]
        );
        $confirmationTimeframe = $this->firstConfiguredTimeframe(
            $timeframeAnalysis,
            [(string) ($this->pairsConfig['confirmation_timeframe'] ?? '15m')]
        );
        $trigger = $triggerTimeframe !== null ? $timeframeAnalysis[$triggerTimeframe] : null;
        $confirmation = $confirmationTimeframe !== null ? $timeframeAnalysis[$confirmationTimeframe] : null;

        $weightedBull = 0.0;
        $weightedBear = 0.0;
        $weightTotal = 0.0;
        $reasons = [];
        $timeframePayload = [];
        foreach ($timeframeAnalysis as $timeframe => $analysis) {
            $weight = (float) ($this->strategyConfig['timeframe_weights'][$timeframe] ?? 1.0);
            $weightedBull += $analysis['bull_score'] * $weight;
            $weightedBear += $analysis['bear_score'] * $weight;
            $weightTotal += $weight;
            $reasons = [...$reasons, ...$analysis['reasons']];
            $timeframePayload[$timeframe] = [
                'bias' => $analysis['bias'],
                'bull_score' => $analysis['bull_score'],
                'bear_score' => $analysis['bear_score'],
                'metrics' => $analysis['metrics'],
            ];
        }

        $bullScore = (int) round($weightedBull / max($weightTotal, 1.0));
        $bearScore = (int) round($weightedBear / max($weightTotal, 1.0));
        $scoreGap = abs($bullScore - $bearScore);

        $riskFlags = $decision['risk']['flags'];
        if ($confirmation !== null && $this->isOpposingBias($decision['bias'], $confirmation['bias'])) {
            $riskFlags[] = 'Magasabb idősík ellentmond';
        }
        if ($trigger !== null && $this->isOpposingBias($decision['bias'], $trigger['bias'])) {
            $riskFlags[] = 'Trigger idősík ellentmond';
        }

        $riskPenalty = count($riskFlags) * 12;
        if (!$decision['risk']['allowed']) {
            $riskPenalty += 12;
        }

        $rawConfidence = max($bullScore, $bearScore) - $riskPenalty;
        $confidence = max(0, min(100, $rawConfidence));
        $marketRegime = $this->determineMarketRegime($decision, $confirmation, $bullScore, $bearScore, $riskFlags);
        $action = $this->determineAction($decision, $trigger, $confirmation, $bullScore, $bearScore, $scoreGap, $confidence, $riskFlags);

        return [
            'symbol' => $symbol,
            'interval' => $decisionTimeframe,
            'market_regime' => $marketRegime,
            'action' => $action,
            'direction' => $action,
            'price' => round((float) $decision['price'], 8),
            'confidence' => $confidence,
            'bull_score' => $bullScore,
            'bear_score' => $bearScore,
            'long_score' => $bullScore,
            'short_score' => $bearScore,
            'risk_penalty' => $riskPenalty,
            'risk' => [
                'allowed' => $riskFlags === [],
                'flags' => array_values(array_unique($riskFlags)),
            ],
            'metrics' => $decision['metrics'],
            'timeframes' => $timeframePayload,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    private function analyzeTimeframe(string $timeframe, array $candles): array
    {
        if (count($candles) < 2) {
            return [
                'timeframe' => $timeframe,
                'price' => 0.0,
                'bias' => 'NEUTRAL',
                'bull_score' => 0,
                'bear_score' => 0,
                'metrics' => [
                    'ema20' => null,
                    'ema50' => null,
                    'ema200' => null,
                    'rsi14' => null,
                    'macd_histogram' => null,
                    'atr_percent' => null,
                    'volume_ratio' => null,
                    'last_candle_change' => null,
                ],
                'risk' => [
                    'allowed' => false,
                    'flags' => ['Nincs elegendő gyertyaadat'],
                ],
                'reasons' => ['Még kevés a piaci adat ezen az idősíkon: ' . $timeframe],
            ];
        }

        $closes = $this->indicators->closes($candles);
        $volumes = $this->indicators->volumes($candles);
        $lastCandle = $candles[array_key_last($candles)];
        $lastPrice = (float) $lastCandle['close'];

        $ema20 = $this->indicators->ema($closes, 20);
        $ema50 = $this->indicators->ema($closes, 50);
        $ema200 = $this->indicators->ema($closes, 200);
        $rsi = $this->indicators->rsi($closes, 14);
        $macd = $this->indicators->macd($closes);
        $atrPercent = $this->indicators->atrPercent($candles, 14);
        $avgVolume = $this->indicators->average(array_slice($volumes, -20));
        $volumeRatio = $avgVolume > 0 ? $lastCandle['volume'] / $avgVolume : 0.0;
        $recentChange = $this->indicators->percentChange((float) $candles[count($candles) - 2]['close'], $lastPrice);
        $lookback = array_slice($closes, -10);
        $structureMove = $lookback !== []
            ? $this->indicators->percentChange((float) $lookback[0], (float) $lookback[array_key_last($lookback)])
            : 0.0;

        $weights = $this->strategyConfig['weights'];
        $bullScore = 0;
        $bearScore = 0;
        $reasons = [];

        if ($ema20 !== null && $ema50 !== null && $ema200 !== null) {
            if ($ema20 > $ema50 && $ema50 > $ema200 && $lastPrice > $ema20) {
                $bullScore += $weights['trend'];
                $reasons[] = sprintf('%s trend emelkedő', $timeframe);
            }
            if ($ema20 < $ema50 && $ema50 < $ema200 && $lastPrice < $ema20) {
                $bearScore += $weights['trend'];
                $reasons[] = sprintf('%s trend csökkenő', $timeframe);
            }
        }

        if ($rsi !== null) {
            if ($rsi >= 55 && $rsi <= 68) {
                $bullScore += $weights['momentum'];
                $reasons[] = sprintf('%s RSI emelkedést támogat', $timeframe);
            }
            if ($rsi <= 45 && $rsi >= 32) {
                $bearScore += $weights['momentum'];
                $reasons[] = sprintf('%s RSI esést támogat', $timeframe);
            }
        }

        if (($macd['histogram'] ?? null) !== null) {
            if ($macd['histogram'] > 0) {
                $bullScore += $weights['macd'];
                $reasons[] = sprintf('%s MACD pozitív', $timeframe);
            }
            if ($macd['histogram'] < 0) {
                $bearScore += $weights['macd'];
                $reasons[] = sprintf('%s MACD negatív', $timeframe);
            }
        }

        if ($volumeRatio >= 1.05) {
            if ($recentChange >= 0) {
                $bullScore += $weights['volume'];
                $reasons[] = sprintf('%s volumen a vevőket erősíti', $timeframe);
            } else {
                $bearScore += $weights['volume'];
                $reasons[] = sprintf('%s volumen az eladókat erősíti', $timeframe);
            }
        }

        if ($structureMove > 0.6) {
            $bullScore += $weights['structure'];
            $reasons[] = sprintf('%s struktúra emelkedő', $timeframe);
        }
        if ($structureMove < -0.6) {
            $bearScore += $weights['structure'];
            $reasons[] = sprintf('%s struktúra csökkenő', $timeframe);
        }

        $risk = $this->riskFilter->evaluate([
            'atr_percent' => $atrPercent,
            'volume_ratio' => $volumeRatio,
            'last_candle_change' => $recentChange,
        ]);

        return [
            'timeframe' => $timeframe,
            'price' => $lastPrice,
            'bias' => $this->determineBias($bullScore, $bearScore),
            'bull_score' => $bullScore,
            'bear_score' => $bearScore,
            'metrics' => [
                'ema20' => $ema20,
                'ema50' => $ema50,
                'ema200' => $ema200,
                'rsi14' => $rsi,
                'macd_histogram' => $macd['histogram'],
                'atr_percent' => $atrPercent,
                'volume_ratio' => $volumeRatio,
                'last_candle_change' => $recentChange,
                'structure_move' => $structureMove,
            ],
            'risk' => $risk,
            'reasons' => $reasons,
        ];
    }

    private function determineBias(int $bullScore, int $bearScore): string
    {
        $gap = abs($bullScore - $bearScore);
        if ($gap < 15) {
            return 'NEUTRAL';
        }

        return $bullScore > $bearScore ? 'BULLISH' : 'BEARISH';
    }

    private function determineMarketRegime(array $decision, ?array $confirmation, int $bullScore, int $bearScore, array $riskFlags): string
    {
        if (($decision['metrics']['atr_percent'] ?? 0.0) > (float) $this->strategyConfig['max_atr_percent']) {
            return 'MAGAS_VOLATILITÁS';
        }

        if (in_array('Magasabb idősík ellentmond', $riskFlags, true)) {
            return 'GYENGE_STRUKTÚRA';
        }

        if ($decision['bias'] === 'BULLISH' && ($confirmation === null || $confirmation['bias'] !== 'BEARISH')) {
            return 'EMELKEDŐ_TREND';
        }

        if ($decision['bias'] === 'BEARISH' && ($confirmation === null || $confirmation['bias'] !== 'BULLISH')) {
            return 'CSÖKKENŐ_TREND';
        }

        if (abs($bullScore - $bearScore) < 12) {
            return 'OLDALAZÁS';
        }

        return 'GYENGE_STRUKTÚRA';
    }

    private function determineAction(array $decision, ?array $trigger, ?array $confirmation, int $bullScore, int $bearScore, int $scoreGap, int $confidence, array $riskFlags): string
    {
        $minConfidence = (int) $this->strategyConfig['min_confidence'];
        $spotConfidence = (int) ($this->strategyConfig['spot_confidence'] ?? 58);

        if ($decision['bias'] === 'NEUTRAL' || $scoreGap < 12) {
            return 'NO_TRADE';
        }

        if (count($riskFlags) >= 2) {
            return 'NO_TRADE';
        }

        if ($decision['bias'] === 'BULLISH') {
            $triggerSupports = $trigger === null || $trigger['bias'] !== 'BEARISH';
            $confirmationSupports = $confirmation === null || $confirmation['bias'] !== 'BEARISH';

            if ($confidence >= $minConfidence && $triggerSupports && $confirmation !== null && $confirmation['bias'] === 'BULLISH') {
                return 'LONG';
            }

            if ($confidence >= $spotConfidence && $confirmationSupports) {
                return 'SPOT_BUY';
            }
        }

        if ($decision['bias'] === 'BEARISH') {
            $triggerSupports = $trigger === null || $trigger['bias'] !== 'BULLISH';
            $confirmationSupports = $confirmation === null || $confirmation['bias'] !== 'BULLISH';

            if ($confidence >= $minConfidence && $triggerSupports && $confirmation !== null && $confirmation['bias'] === 'BEARISH') {
                return 'SHORT';
            }

            if ($confidence >= $spotConfidence && $confirmationSupports) {
                return 'SPOT_SELL';
            }
        }

        return 'NO_TRADE';
    }

    private function isOpposingBias(string $left, string $right): bool
    {
        return ($left === 'BULLISH' && $right === 'BEARISH') || ($left === 'BEARISH' && $right === 'BULLISH');
    }

    private function firstConfiguredTimeframe(array $analysis, array $preferred): ?string
    {
        foreach ($preferred as $timeframe) {
            if (isset($analysis[$timeframe])) {
                return $timeframe;
            }
        }

        return null;
    }

    private function emptySignal(string $symbol, string $interval, string $reason): array
    {
        return [
            'symbol' => $symbol,
            'interval' => $interval,
            'market_regime' => 'HIBA',
            'action' => 'NO_TRADE',
            'direction' => 'NO_TRADE',
            'price' => 0.0,
            'confidence' => 0,
            'bull_score' => 0,
            'bear_score' => 0,
            'long_score' => 0,
            'short_score' => 0,
            'risk_penalty' => 100,
            'risk' => [
                'allowed' => false,
                'flags' => ['A signal motor hibát jelzett'],
            ],
            'metrics' => [
                'ema20' => null,
                'ema50' => null,
                'ema200' => null,
                'rsi14' => null,
                'macd_histogram' => null,
                'atr_percent' => null,
                'volume_ratio' => null,
                'last_candle_change' => null,
            ],
            'timeframes' => [],
            'reasons' => [$reason],
        ];
    }
}