<?php

declare(strict_types=1);

namespace App\Services\Strategy;

final class RiskFilterService
{
    public function __construct(private readonly array $config)
    {
    }

    public function evaluate(array $context): array
    {
        $flags = [];
        $cooldownSeconds = max(0, (int) ($this->config['cooldown_seconds'] ?? 0));
        $candleAgeSeconds = isset($context['candle_age_seconds']) ? (int) $context['candle_age_seconds'] : null;

        if (($context['atr_percent'] ?? 0.0) > (float) $this->config['max_atr_percent']) {
            $flags[] = 'ATR too high';
        }

        if (($context['volume_ratio'] ?? 0.0) < (float) $this->config['min_volume_ratio']) {
            $flags[] = 'Volume below threshold';
        }

        if (abs((float) ($context['last_candle_change'] ?? 0.0)) > (float) $this->config['max_spike_percent']) {
            $flags[] = 'Last candle spike too large';
        }

        if ($cooldownSeconds > 0 && $candleAgeSeconds !== null && $candleAgeSeconds < $cooldownSeconds) {
            $flags[] = 'Cooldown active';
        }

        return [
            'allowed' => $flags === [],
            'flags' => $flags,
        ];
    }
}
