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

        if (($context['atr_percent'] ?? 0.0) > (float) $this->config['max_atr_percent']) {
            $flags[] = 'Az ATR túl magas';
        }

        if (($context['volume_ratio'] ?? 0.0) < (float) $this->config['min_volume_ratio']) {
            $flags[] = 'A volumen a küszöb alatt van';
        }

        if (abs((float) ($context['last_candle_change'] ?? 0.0)) > (float) $this->config['max_spike_percent']) {
            $flags[] = 'Az utolsó gyertya kilengése túl erős';
        }

        return [
            'allowed' => $flags === [],
            'flags' => $flags,
        ];
    }
}