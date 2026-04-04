<?php

declare(strict_types=1);

namespace App\Services\Strategy;

final class SignalEngine
{
    public function __construct(
        private readonly array $strategyConfig,
        private readonly array $pairsConfig
    ) {
    }

    public function analyze(string $symbol, string $decisionTimeframe, array $snapshot): array
    {
        $timeframeSnapshots = $snapshot['timeframes'] ?? [];
        $timeframeAnalysis = [];

        foreach ($timeframeSnapshots as $timeframe => $timeframeSnapshot) {
            if (!is_array($timeframeSnapshot)) {
                continue;
            }

            $timeframeAnalysis[$timeframe] = $this->analyzeTimeframe((string) $timeframe, $timeframeSnapshot);
        }

        $decision = $timeframeAnalysis[$decisionTimeframe] ?? reset($timeframeAnalysis);
        if (!is_array($decision)) {
            return $this->emptySignal($symbol, $decisionTimeframe, 'Timeframe analysis unavailable');
        }

        $triggerTimeframe = $this->firstConfiguredTimeframe(
            $timeframeAnalysis,
            [(string) ($this->pairsConfig['trigger_timeframe'] ?? '5m')]
        );
        $confirmationTimeframe = $this->firstConfiguredTimeframe(
            $timeframeAnalysis,
            [(string) ($this->pairsConfig['confirmation_timeframe'] ?? '1h')]
        );
        $trigger = $triggerTimeframe !== null ? $timeframeAnalysis[$triggerTimeframe] : null;
        $confirmation = $confirmationTimeframe !== null ? $timeframeAnalysis[$confirmationTimeframe] : null;

        $weightedBull = 0.0;
        $weightedBear = 0.0;
        $weightTotal = 0.0;
        $reasons = [];
        $timeframePayload = [];
        $dataIssues = [];

        foreach ($timeframeAnalysis as $timeframe => $analysis) {
            $weight = (float) ($this->strategyConfig['timeframe_weights'][$timeframe] ?? 1.0);
            $weightedBull += $analysis['bull_score'] * $weight;
            $weightedBear += $analysis['bear_score'] * $weight;
            $weightTotal += $weight;
            $reasons = [...$reasons, ...$analysis['reasons']];
            $dataIssues = [...$dataIssues, ...($analysis['data_quality']['issues'] ?? [])];
            $timeframePayload[$timeframe] = [
                'bias' => $analysis['bias'],
                'bull_score' => $analysis['bull_score'],
                'bear_score' => $analysis['bear_score'],
                'metrics' => $analysis['metrics'],
                'data_quality' => $analysis['data_quality'],
            ];
        }

        $bullScore = (int) round($weightedBull / max($weightTotal, 1.0));
        $bearScore = (int) round($weightedBear / max($weightTotal, 1.0));
        $scoreGap = abs($bullScore - $bearScore);

        $riskFlags = $decision['risk']['flags'];
        if ($confirmation !== null && $this->isOpposingBias($decision['bias'], $confirmation['bias'])) {
            $riskFlags[] = 'Higher timeframe conflict';
        }
        if ($trigger !== null && $this->isOpposingBias($decision['bias'], $trigger['bias'])) {
            $riskFlags[] = 'Trigger timeframe conflict';
        }
        if (!($decision['data_quality']['ready'] ?? false)) {
            $riskFlags[] = 'Decision timeframe data unavailable';
        }

        $riskPenalty = count(array_values(array_unique($riskFlags))) * 12;
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
                'allowed' => array_values(array_unique($riskFlags)) === [],
                'flags' => array_values(array_unique($riskFlags)),
            ],
            'metrics' => $decision['metrics'],
            'timeframes' => $timeframePayload,
            'reasons' => array_values(array_unique($reasons)),
            'data_quality' => [
                'ready' => (bool) ($decision['data_quality']['ready'] ?? false),
                'issues' => array_values(array_unique($dataIssues)),
            ],
        ];
    }

    private function analyzeTimeframe(string $timeframe, array $snapshot): array
    {
        $metrics = is_array($snapshot['metrics'] ?? null) ? $snapshot['metrics'] : [];
        $risk = is_array($snapshot['risk'] ?? null)
            ? $snapshot['risk']
            : ['allowed' => false, 'flags' => ['Snapshot risk data unavailable']];
        $quality = is_array($snapshot['data_quality'] ?? null)
            ? $snapshot['data_quality']
            : ['ready' => false, 'issues' => ['Snapshot data quality unavailable']];
        $lastPrice = (float) ($snapshot['price'] ?? 0.0);

        if (!($quality['ready'] ?? false) || $lastPrice <= 0.0) {
            $issues = array_values(array_unique(array_map('strval', $quality['issues'] ?? ['Market data unavailable'])));
            $reasonText = $issues !== [] ? implode(', ', $issues) : 'Market data unavailable';

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
                    'candle_age_seconds' => null,
                ],
                'risk' => [
                    'allowed' => false,
                    'flags' => array_values(array_unique(['Not enough candle data', ...($risk['flags'] ?? [])])),
                ],
                'reasons' => [sprintf('%s data unavailable: %s', $timeframe, $reasonText)],
                'data_quality' => [
                    'ready' => false,
                    'issues' => $issues,
                ],
            ];
        }

        $ema20 = $metrics['ema20'] ?? null;
        $ema50 = $metrics['ema50'] ?? null;
        $ema200 = $metrics['ema200'] ?? null;
        $rsi = $metrics['rsi14'] ?? null;
        $macdHistogram = $metrics['macd_histogram'] ?? null;
        $volumeRatio = (float) ($metrics['volume_ratio'] ?? 0.0);
        $recentChange = (float) ($metrics['last_candle_change'] ?? 0.0);
        $structureMove = (float) ($metrics['structure_move'] ?? 0.0);

        $weights = $this->strategyConfig['weights'];
        $bullScore = 0;
        $bearScore = 0;
        $reasons = [];

        if ($ema20 !== null && $ema50 !== null && $ema200 !== null) {
            if ($ema20 > $ema50 && $ema50 > $ema200 && $lastPrice > $ema20) {
                $bullScore += $weights['trend'];
                $reasons[] = sprintf('%s rising trend', $timeframe);
            }
            if ($ema20 < $ema50 && $ema50 < $ema200 && $lastPrice < $ema20) {
                $bearScore += $weights['trend'];
                $reasons[] = sprintf('%s falling trend', $timeframe);
            }
        } else {
            $reasons[] = sprintf('%s trend baseline incomplete', $timeframe);
        }

        if ($rsi !== null) {
            if ($rsi >= 55 && $rsi <= 68) {
                $bullScore += $weights['momentum'];
                $reasons[] = sprintf('%s RSI supports upside', $timeframe);
            }
            if ($rsi <= 45 && $rsi >= 32) {
                $bearScore += $weights['momentum'];
                $reasons[] = sprintf('%s RSI supports downside', $timeframe);
            }
        }

        if ($macdHistogram !== null) {
            if ($macdHistogram > 0) {
                $bullScore += $weights['macd'];
                $reasons[] = sprintf('%s MACD positive', $timeframe);
            }
            if ($macdHistogram < 0) {
                $bearScore += $weights['macd'];
                $reasons[] = sprintf('%s MACD negative', $timeframe);
            }
        }

        if ($volumeRatio >= 1.05) {
            if ($recentChange >= 0) {
                $bullScore += $weights['volume'];
                $reasons[] = sprintf('%s volume supports buyers', $timeframe);
            } else {
                $bearScore += $weights['volume'];
                $reasons[] = sprintf('%s volume supports sellers', $timeframe);
            }
        }

        if ($structureMove > 0.6) {
            $bullScore += $weights['structure'];
            $reasons[] = sprintf('%s structure rising', $timeframe);
        }
        if ($structureMove < -0.6) {
            $bearScore += $weights['structure'];
            $reasons[] = sprintf('%s structure falling', $timeframe);
        }

        if (in_array('Cooldown active', $risk['flags'], true)) {
            $reasons[] = sprintf('%s cooldown still active', $timeframe);
        }

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
                'macd_histogram' => $macdHistogram,
                'atr_percent' => $metrics['atr_percent'] ?? null,
                'volume_ratio' => $metrics['volume_ratio'] ?? null,
                'last_candle_change' => $metrics['last_candle_change'] ?? null,
                'structure_move' => $metrics['structure_move'] ?? null,
                'candle_age_seconds' => $metrics['candle_age_seconds'] ?? null,
            ],
            'risk' => [
                'allowed' => (bool) ($risk['allowed'] ?? false),
                'flags' => array_values(array_unique($risk['flags'] ?? [])),
            ],
            'reasons' => array_values(array_unique($reasons)),
            'data_quality' => [
                'ready' => true,
                'issues' => array_values(array_unique(array_map('strval', $quality['issues'] ?? []))),
            ],
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
        if (!($decision['data_quality']['ready'] ?? false)) {
            return 'ERROR';
        }

        if (in_array('Cooldown active', $riskFlags, true)) {
            return 'COOLDOWN';
        }

        if (($decision['metrics']['atr_percent'] ?? 0.0) > (float) $this->strategyConfig['max_atr_percent']) {
            return 'HIGH_VOLATILITY';
        }

        if (in_array('Higher timeframe conflict', $riskFlags, true)) {
            return 'WEAK_STRUCTURE';
        }

        if ($decision['bias'] === 'BULLISH' && ($confirmation === null || $confirmation['bias'] !== 'BEARISH')) {
            return 'UPTREND';
        }

        if ($decision['bias'] === 'BEARISH' && ($confirmation === null || $confirmation['bias'] !== 'BULLISH')) {
            return 'DOWNTREND';
        }

        if (abs($bullScore - $bearScore) < 12) {
            return 'RANGE';
        }

        return 'WEAK_STRUCTURE';
    }

    private function determineAction(array $decision, ?array $trigger, ?array $confirmation, int $bullScore, int $bearScore, int $scoreGap, int $confidence, array $riskFlags): string
    {
        $minConfidence = (int) $this->strategyConfig['min_confidence'];
        $spotConfidence = (int) ($this->strategyConfig['spot_confidence'] ?? 58);

        if (!($decision['data_quality']['ready'] ?? false)) {
            return 'NO_TRADE';
        }

        if (in_array('Cooldown active', $riskFlags, true)) {
            return 'NO_TRADE';
        }

        if ($decision['bias'] === 'NEUTRAL' || $scoreGap < 12) {
            return 'NO_TRADE';
        }

        if (count(array_values(array_unique($riskFlags))) >= 2) {
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
            'market_regime' => 'ERROR',
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
                'flags' => ['Signal engine error'],
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
                'candle_age_seconds' => null,
            ],
            'timeframes' => [],
            'reasons' => [$reason],
            'data_quality' => [
                'ready' => false,
                'issues' => [$reason],
            ],
        ];
    }
}
